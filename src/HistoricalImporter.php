<?php

namespace GlpiPlugin\Gamification;

use Ticket;

/**
 * Importacao retroativa de XP a partir dos dados nativos do GLPI.
 *
 * Percorre tickets ja resolvidos/fechados, pesquisas de satisfacao e artigos
 * da base de conhecimento e cria as transacoes de XP correspondentes,
 * respeitando as mesmas regras do EventListener.
 *
 * Processamento EM LOTES com cursor persistido em Config, para nao estourar o
 * tempo/memoria em bases grandes:
 *  - fases: tickets -> satisfaction -> kb -> done
 *  - cursor: hist_import_phase + hist_import_last_id (ultimo id processado)
 *  - cada chamada a runBatch() processa ate $limit linhas e avanca o cursor
 *  - pode ser dirigido por botao (varias chamadas) ou pela cron ImportHistorical
 *
 * Caracteristicas:
 *  - Idempotente: cada evento e checado via alreadyAwarded(); rodar de novo
 *    nao duplica XP.
 *  - Usa a DATA REAL do evento como date_creation da transacao, para que a XP
 *    historica entre em xp_total mas NAO infle a temporada atual.
 *  - Ao concluir todas as fases, chama Score::recalculateAll() para reconstruir
 *    scores e leaderboard.
 */
class HistoricalImporter
{
    public const BATCH_DEFAULT = 300;

    private const PHASE_KEY  = 'hist_import_phase';
    private const CURSOR_KEY = 'hist_import_last_id';
    private const USERS_KEY  = 'hist_import_users';

    /** Ordem das fases. '' = nao iniciado, 'done' = concluido. */
    private const PHASES = ['tickets', 'satisfaction', 'kb'];

    /** Saldo de XP por usuario, mantido durante o lote atual. */
    private array $balances = [];

    private int $transactions = 0;

    // ── Controle de estado ────────────────────────────────────────────────────

    /** (Re)inicia a importacao do zero, posicionando o cursor na primeira fase. */
    public static function reset(): void
    {
        Config::setConfig(self::PHASE_KEY, 'tickets');
        Config::setConfig(self::CURSOR_KEY, '0');
        Config::setConfig(self::USERS_KEY, '0');
    }

    public static function currentPhase(): string
    {
        return (string) (Config::getConfig(self::PHASE_KEY) ?? '');
    }

    public static function isRunning(): bool
    {
        $p = self::currentPhase();
        return $p !== '' && $p !== 'done';
    }

    public static function isDone(): bool
    {
        return self::currentPhase() === 'done';
    }

    // ── Processamento de um lote ──────────────────────────────────────────────

    /**
     * Processa ate $limit linhas da fase atual e avanca o cursor.
     * @return array{done:bool,phase:string,processed:int,transactions:int}
     */
    public static function runBatch(int $limit = self::BATCH_DEFAULT): array
    {
        $phase = self::currentPhase();
        if ($phase === '') {
            self::reset();
            $phase = 'tickets';
        }
        if ($phase === 'done') {
            return ['done' => true, 'phase' => 'done', 'processed' => 0, 'transactions' => 0];
        }

        $importer = new self();
        $afterId  = (int) (Config::getConfig(self::CURSOR_KEY) ?? 0);

        $result = match ($phase) {
            'tickets'      => $importer->batchTickets($afterId, $limit),
            'satisfaction' => $importer->batchSatisfaction($afterId, $limit),
            'kb'           => $importer->batchKb($afterId, $limit),
            default        => ['count' => 0, 'last_id' => $afterId],
        };

        if ($result['count'] < $limit) {
            // Fim da fase: avanca para a proxima.
            $next = self::nextPhase($phase);
            Config::setConfig(self::PHASE_KEY, $next);
            Config::setConfig(self::CURSOR_KEY, '0');
            if ($next === 'done') {
                Config::setConfig(self::USERS_KEY, (string) Score::recalculateAll());
            }
        } else {
            Config::setConfig(self::CURSOR_KEY, (string) $result['last_id']);
        }

        return [
            'done'         => self::isDone(),
            'phase'        => $phase,
            'processed'    => $result['count'],
            'transactions' => $importer->transactions,
        ];
    }

    private static function nextPhase(string $phase): string
    {
        $idx = array_search($phase, self::PHASES, true);
        if ($idx === false || $idx === count(self::PHASES) - 1) {
            return 'done';
        }
        return self::PHASES[$idx + 1];
    }

    // ── Progresso (para a barra na UI) ────────────────────────────────────────

    /**
     * @return array{phase:string,phase_label:string,processed:int,total:int,percent:int,done:bool,users:int}
     */
    public static function getProgress(): array
    {
        $phase   = self::currentPhase();
        $afterId = (int) (Config::getConfig(self::CURSOR_KEY) ?? 0);

        $totTickets = self::countTickets();
        $totSat     = self::countSatisfaction();
        $totKb      = self::countKb();
        $grand      = $totTickets + $totSat + $totKb;

        $processed = match ($phase) {
            'tickets'      => self::countTickets($afterId),
            'satisfaction' => $totTickets + self::countSatisfaction($afterId),
            'kb'           => $totTickets + $totSat + self::countKb($afterId),
            'done'         => $grand,
            default        => 0,
        };

        $labels = [
            ''             => __('Não iniciado', 'gamification'),
            'tickets'      => __('Tickets', 'gamification'),
            'satisfaction' => __('Satisfação', 'gamification'),
            'kb'           => __('Base de conhecimento', 'gamification'),
            'done'         => __('Concluído', 'gamification'),
        ];

        return [
            'phase'       => $phase,
            'phase_label' => $labels[$phase] ?? $phase,
            'processed'   => $processed,
            'total'       => $grand,
            'percent'     => $grand > 0 ? (int) floor($processed / $grand * 100) : ($phase === 'done' ? 100 : 0),
            'done'        => $phase === 'done',
            'users'       => (int) (Config::getConfig(self::USERS_KEY) ?? 0),
        ];
    }

    // ── Lotes por fase ────────────────────────────────────────────────────────

    /**
     * @return array{count:int,last_id:int}
     */
    private function batchTickets(int $afterId, int $limit): array
    {
        global $DB;

        $fcr_max   = (int) (Config::getConfig('fcr_max_minutes') ?: 60);
        $penalties = (bool) Config::getConfig('enable_penalties');

        $rows = $DB->request([
            'SELECT' => [
                'id', 'entities_id', 'date', 'solvedate', 'closedate',
                'time_to_resolve', 'time_to_own', 'takeintoaccountdate',
            ],
            'FROM'  => 'glpi_tickets',
            'WHERE' => [
                'is_deleted' => 0,
                ['status'    => ['IN', [Ticket::SOLVED, Ticket::CLOSED]]],
                ['id'        => ['>', $afterId]],
            ],
            'ORDER' => 'id ASC',
            'LIMIT' => $limit,
        ]);

        $count = 0;
        $lastId = $afterId;
        foreach ($rows as $t) {
            $lastId = (int) $t['id'];
            $count++;
            $this->processTicket($t, $fcr_max, $penalties);
        }

        return ['count' => $count, 'last_id' => $lastId];
    }

    private function processTicket(array $t, int $fcr_max, bool $penalties): void
    {
        $tickets_id  = (int) $t['id'];
        $entities_id = (int) $t['entities_id'];

        $users_id = EventListener::getAssignedTechnician($tickets_id);
        if (!$users_id) {
            return;
        }

        $solved = !empty($t['solvedate'])
            ? $t['solvedate']
            : (!empty($t['closedate']) ? $t['closedate'] : null);
        if ($solved === null) {
            return;
        }
        $solvedTs = strtotime($solved);

        // 1. Ticket resolvido
        if ($rule = Rule::getRuleForEvent('ticket_resolved')) {
            $this->award($users_id, (int) $rule['xp_value'], 'ticket_resolved', $tickets_id, $solved,
                sprintf(__('Ticket %d resolvido', 'gamification'), $tickets_id), $entities_id);
        }

        // 2. FCR — resolvido dentro do tempo maximo e sem escalonamento
        if (!empty($t['date'])) {
            $minutes = ($solvedTs - strtotime($t['date'])) / 60;
            if ($minutes <= $fcr_max && !$this->wasEscalated($tickets_id)) {
                if ($rule = Rule::getRuleForEvent('ticket_resolved_fcr')) {
                    $this->award($users_id, (int) $rule['xp_value'], 'ticket_resolved_fcr', $tickets_id, $solved,
                        sprintf(__('FCR no Ticket %d', 'gamification'), $tickets_id), $entities_id);
                }
            }
        }

        // 3. SLA de solucao
        if (!empty($t['time_to_resolve']) && $solvedTs <= strtotime($t['time_to_resolve'])) {
            if ($rule = Rule::getRuleForEvent('sla_met')) {
                $this->award($users_id, (int) $rule['xp_value'], 'sla_met', $tickets_id, $solved,
                    sprintf(__('SLA de solução cumprido no Ticket %d', 'gamification'), $tickets_id), $entities_id);
            }
        }

        // 4. SLA de atendimento (cumprido ou estourado)
        if (!empty($t['time_to_own'])) {
            $tto  = strtotime($t['time_to_own']);
            $tacd = !empty($t['takeintoaccountdate']) ? strtotime($t['takeintoaccountdate']) : null;

            if ($tacd !== null && $tacd <= $tto) {
                if ($rule = Rule::getRuleForEvent('sla_tto_met')) {
                    $this->award($users_id, (int) $rule['xp_value'], 'sla_tto_met', $tickets_id, $solved,
                        sprintf(__('SLA de atendimento cumprido no Ticket %d', 'gamification'), $tickets_id), $entities_id);
                }
            } elseif ($penalties) {
                $rule = Rule::getRuleForEvent('sla_tto_breached');
                $penalty = $rule ? -abs((int) $rule['xp_value']) : 0;
                $this->award($users_id, $penalty, 'sla_tto_breached', $tickets_id, $solved,
                    sprintf(__('SLA de atendimento estourado no Ticket %d', 'gamification'), $tickets_id), $entities_id);
            }
        }
    }

    /**
     * @return array{count:int,last_id:int}
     */
    private function batchSatisfaction(int $afterId, int $limit): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_ticketsatisfactions')) {
            return ['count' => 0, 'last_id' => $afterId];
        }

        $rows = $DB->request([
            'SELECT' => [
                'glpi_ticketsatisfactions.id',
                'glpi_ticketsatisfactions.tickets_id',
                'glpi_ticketsatisfactions.satisfaction',
                'glpi_ticketsatisfactions.date_answered',
                'glpi_tickets.entities_id',
                'glpi_tickets.solvedate',
            ],
            'FROM' => 'glpi_ticketsatisfactions',
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_ticketsatisfactions' => 'tickets_id',
                        'glpi_tickets'             => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                ['glpi_ticketsatisfactions.satisfaction' => ['>=', 4]],
                ['glpi_ticketsatisfactions.id'           => ['>', $afterId]],
            ],
            'ORDER' => 'glpi_ticketsatisfactions.id ASC',
            'LIMIT' => $limit,
        ]);

        $count = 0;
        $lastId = $afterId;
        foreach ($rows as $r) {
            $lastId = (int) $r['id'];
            $count++;

            $tickets_id  = (int) $r['tickets_id'];
            $rating      = (int) $r['satisfaction'];
            $entities_id = (int) ($r['entities_id'] ?? 0);

            $users_id = EventListener::getAssignedTechnician($tickets_id);
            if (!$users_id) {
                continue;
            }

            if ($this->satisfactionAlreadyAwarded($users_id, $tickets_id)) {
                continue;
            }

            $date = !empty($r['date_answered'])
                ? $r['date_answered']
                : (!empty($r['solvedate']) ? $r['solvedate'] : date('Y-m-d H:i:s'));

            $event = $rating >= 5 ? 'satisfaction_max' : 'satisfaction_good';
            $label = $rating >= 5
                ? sprintf(__('Satisfação 5 estrelas no Ticket %d', 'gamification'), $tickets_id)
                : sprintf(__('Satisfação 4 estrelas no Ticket %d', 'gamification'), $tickets_id);

            if ($rule = Rule::getRuleForEvent($event)) {
                $this->award($users_id, (int) $rule['xp_value'], $event, $tickets_id, $date, $label, $entities_id);
            }
        }

        return ['count' => $count, 'last_id' => $lastId];
    }

    /**
     * @return array{count:int,last_id:int}
     */
    private function batchKb(int $afterId, int $limit): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_knowbaseitems')) {
            return ['count' => 0, 'last_id' => $afterId];
        }

        $rule = Rule::getRuleForEvent('kb_article_created');

        $rows = $DB->request([
            'SELECT' => ['id', 'users_id', 'date_creation'],
            'FROM'   => 'glpi_knowbaseitems',
            'WHERE'  => [
                ['users_id' => ['>', 0]],
                ['id'       => ['>', $afterId]],
            ],
            'ORDER' => 'id ASC',
            'LIMIT' => $limit,
        ]);

        $count = 0;
        $lastId = $afterId;
        foreach ($rows as $r) {
            $lastId = (int) $r['id'];
            $count++;

            if (!$rule) {
                continue;
            }

            $users_id = (int) $r['users_id'];
            $kb_id    = (int) $r['id'];
            $date     = !empty($r['date_creation']) ? $r['date_creation'] : date('Y-m-d H:i:s');

            $this->award($users_id, (int) $rule['xp_value'], 'kb_article_created', $kb_id, $date,
                sprintf(__('Artigo KB %d criado', 'gamification'), $kb_id), 0, 'KnowbaseItem');
        }

        return ['count' => $count, 'last_id' => $lastId];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Insere uma transacao de XP historica (idempotente) com a data real do
     * evento e o saldo corrente do usuario.
     */
    private function award(
        int $users_id,
        int $amount,
        string $event_type,
        int $source_items_id,
        string $date,
        string $description,
        int $entities_id,
        string $source_itemtype = 'Ticket'
    ): bool {
        global $DB;

        if (EventListener::alreadyAwarded($users_id, $event_type, $source_itemtype, $source_items_id)) {
            return false;
        }

        if (!isset($this->balances[$users_id])) {
            $this->balances[$users_id] = $this->currentBalance($users_id);
        }
        $this->balances[$users_id] = max(0, $this->balances[$users_id] + $amount);

        $DB->insert(XPTransaction::$table, [
            'users_id'         => $users_id,
            'entities_id'      => $entities_id,
            'xp_amount'        => $amount,
            'xp_balance_after' => $this->balances[$users_id],
            'event_type'       => $event_type,
            'source_itemtype'  => $source_itemtype,
            'source_items_id'  => $source_items_id,
            'description'      => $description,
            'date_creation'    => $date,
        ]);

        $this->transactions++;
        return true;
    }

    private function currentBalance(int $users_id): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('SUM(`xp_amount`) AS `total`')],
            'FROM'   => XPTransaction::$table,
            'WHERE'  => ['users_id' => $users_id],
        ])->current();
        return max(0, (int) ($row['total'] ?? 0));
    }

    private function wasEscalated(int $tickets_id): bool
    {
        return countElementsInTable('glpi_tickets_users', [
            'tickets_id' => $tickets_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]) > 1;
    }

    private function satisfactionAlreadyAwarded(int $users_id, int $tickets_id): bool
    {
        global $DB;
        $row = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => XPTransaction::$table,
            'WHERE' => [
                'users_id'        => $users_id,
                'source_itemtype' => 'Ticket',
                'source_items_id' => $tickets_id,
                'event_type'      => ['satisfaction_max', 'satisfaction_good'],
            ],
        ])->current();
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    // ── Contagens para o progresso ────────────────────────────────────────────

    private static function countTickets(?int $maxId = null): int
    {
        $where = [
            'is_deleted' => 0,
            ['status'    => ['IN', [Ticket::SOLVED, Ticket::CLOSED]]],
        ];
        if ($maxId !== null) {
            $where[] = ['id' => ['<=', $maxId]];
        }
        return countElementsInTable('glpi_tickets', $where);
    }

    private static function countSatisfaction(?int $maxId = null): int
    {
        global $DB;
        if (!$DB->tableExists('glpi_ticketsatisfactions')) {
            return 0;
        }
        $where = [['satisfaction' => ['>=', 4]]];
        if ($maxId !== null) {
            $where[] = ['id' => ['<=', $maxId]];
        }
        return countElementsInTable('glpi_ticketsatisfactions', $where);
    }

    private static function countKb(?int $maxId = null): int
    {
        global $DB;
        if (!$DB->tableExists('glpi_knowbaseitems')) {
            return 0;
        }
        $where = [['users_id' => ['>', 0]]];
        if ($maxId !== null) {
            $where[] = ['id' => ['<=', $maxId]];
        }
        return countElementsInTable('glpi_knowbaseitems', $where);
    }
}
