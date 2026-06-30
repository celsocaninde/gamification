<?php

namespace GlpiPlugin\Gamification;

use Ticket;

/**
 * Importacao retroativa de XP a partir dos dados nativos do GLPI.
 *
 * Percorre tickets ja resolvidos/fechados, pesquisas de satisfacao e artigos
 * da base de conhecimento que existiam ANTES da instalacao do plugin (ou que
 * nunca geraram XP) e cria as transacoes correspondentes, respeitando as mesmas
 * regras do EventListener.
 *
 * Caracteristicas:
 *  - Idempotente: cada evento e checado via alreadyAwarded(); rodar duas vezes
 *    nao duplica XP.
 *  - Usa a DATA REAL do evento (solvedate, date_answered, etc.) como
 *    date_creation da transacao, para que a XP historica conte em xp_total mas
 *    NAO infle a temporada atual (xp_season so soma transacoes apos o inicio
 *    da temporada ativa).
 *  - Ao final chama Score::recalculateAll() para reconstruir scores e leaderboard.
 */
class HistoricalImporter
{
    /** Saldo de XP por usuario, mantido durante o replay cronologico. */
    private array $balances = [];

    /** @var array{tickets:int,fcr:int,sla:int,sla_tto:int,satisfaction:int,kb:int,transactions:int,users:int} */
    private array $stats = [
        'tickets'      => 0,
        'fcr'          => 0,
        'sla'          => 0,
        'sla_tto'      => 0,
        'satisfaction' => 0,
        'kb'           => 0,
        'transactions' => 0,
        'users'        => 0,
    ];

    /**
     * Executa a importacao completa.
     * @return array estatisticas do que foi importado
     */
    public static function run(): array
    {
        $importer = new self();
        $importer->importTickets();
        $importer->importSatisfaction();
        $importer->importKbArticles();

        $importer->stats['users'] = Score::recalculateAll();

        return $importer->stats;
    }

    /**
     * Coleta os eventos candidatos de um ticket, ordena por data e registra.
     */
    private function importTickets(): void
    {
        global $DB;

        $fcr_max  = (int) (Config::getConfig('fcr_max_minutes') ?: 60);
        $penalties = (bool) Config::getConfig('enable_penalties');

        $tickets = $DB->request([
            'SELECT' => [
                'id', 'entities_id', 'date', 'solvedate', 'closedate',
                'time_to_resolve', 'time_to_own', 'takeintoaccountdate',
            ],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => [
                'is_deleted' => 0,
                ['status'    => ['IN', [Ticket::SOLVED, Ticket::CLOSED]]],
            ],
            'ORDER'  => 'solvedate ASC',
        ]);

        foreach ($tickets as $t) {
            $tickets_id  = (int) $t['id'];
            $entities_id = (int) $t['entities_id'];

            $users_id = EventListener::getAssignedTechnician($tickets_id);
            if (!$users_id) {
                continue;
            }

            $solved = !empty($t['solvedate'])
                ? $t['solvedate']
                : (!empty($t['closedate']) ? $t['closedate'] : null);
            if ($solved === null) {
                continue;
            }
            $solvedTs = strtotime($solved);

            // 1. Ticket resolvido
            if ($rule = Rule::getRuleForEvent('ticket_resolved')) {
                if ($this->award($users_id, (int) $rule['xp_value'], 'ticket_resolved', $tickets_id, $solved,
                    sprintf(__('Ticket %d resolvido', 'gamification'), $tickets_id), $entities_id)) {
                    $this->stats['tickets']++;
                }
            }

            // 2. FCR — resolvido dentro do tempo maximo e sem escalonamento
            if (!empty($t['date'])) {
                $minutes = ($solvedTs - strtotime($t['date'])) / 60;
                if ($minutes <= $fcr_max && !$this->wasEscalated($tickets_id)) {
                    if ($rule = Rule::getRuleForEvent('ticket_resolved_fcr')) {
                        if ($this->award($users_id, (int) $rule['xp_value'], 'ticket_resolved_fcr', $tickets_id, $solved,
                            sprintf(__('FCR no Ticket %d', 'gamification'), $tickets_id), $entities_id)) {
                            $this->stats['fcr']++;
                        }
                    }
                }
            }

            // 3. SLA de solucao
            if (!empty($t['time_to_resolve']) && $solvedTs <= strtotime($t['time_to_resolve'])) {
                if ($rule = Rule::getRuleForEvent('sla_met')) {
                    if ($this->award($users_id, (int) $rule['xp_value'], 'sla_met', $tickets_id, $solved,
                        sprintf(__('SLA de solução cumprido no Ticket %d', 'gamification'), $tickets_id), $entities_id)) {
                        $this->stats['sla']++;
                    }
                }
            }

            // 4. SLA de atendimento (cumprido ou estourado)
            if (!empty($t['time_to_own'])) {
                $tto  = strtotime($t['time_to_own']);
                $tacd = !empty($t['takeintoaccountdate']) ? strtotime($t['takeintoaccountdate']) : null;

                if ($tacd !== null && $tacd <= $tto) {
                    if ($rule = Rule::getRuleForEvent('sla_tto_met')) {
                        if ($this->award($users_id, (int) $rule['xp_value'], 'sla_tto_met', $tickets_id, $solved,
                            sprintf(__('SLA de atendimento cumprido no Ticket %d', 'gamification'), $tickets_id), $entities_id)) {
                            $this->stats['sla_tto']++;
                        }
                    }
                } elseif ($penalties) {
                    $rule = Rule::getRuleForEvent('sla_tto_breached');
                    $penalty = $rule ? -abs((int) $rule['xp_value']) : 0;
                    if ($this->award($users_id, $penalty, 'sla_tto_breached', $tickets_id, $solved,
                        sprintf(__('SLA de atendimento estourado no Ticket %d', 'gamification'), $tickets_id), $entities_id)) {
                        $this->stats['sla_tto']++;
                    }
                }
            }
        }
    }

    private function importSatisfaction(): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_ticketsatisfactions')) {
            return;
        }

        $rows = $DB->request([
            'SELECT' => [
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
                ['NOT' => ['glpi_ticketsatisfactions.satisfaction' => null]],
                ['glpi_ticketsatisfactions.satisfaction' => ['>=', 4]],
            ],
            'ORDER' => 'glpi_ticketsatisfactions.date_answered ASC',
        ]);

        foreach ($rows as $r) {
            $tickets_id  = (int) $r['tickets_id'];
            $rating      = (int) $r['satisfaction'];
            $entities_id = (int) ($r['entities_id'] ?? 0);

            $users_id = EventListener::getAssignedTechnician($tickets_id);
            if (!$users_id) {
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
                // Evita XP duplicada quando ja existe qualquer evento de satisfacao no ticket.
                if ($this->satisfactionAlreadyAwarded($users_id, $tickets_id)) {
                    continue;
                }
                if ($this->award($users_id, (int) $rule['xp_value'], $event, $tickets_id, $date, $label, $entities_id)) {
                    $this->stats['satisfaction']++;
                }
            }
        }
    }

    private function importKbArticles(): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_knowbaseitems')) {
            return;
        }

        $rule = Rule::getRuleForEvent('kb_article_created');
        if (!$rule) {
            return;
        }

        $rows = $DB->request([
            'SELECT' => ['id', 'users_id', 'date_creation'],
            'FROM'   => 'glpi_knowbaseitems',
            'WHERE'  => [['users_id' => ['>', 0]]],
            'ORDER'  => 'date_creation ASC',
        ]);

        foreach ($rows as $r) {
            $users_id = (int) $r['users_id'];
            $kb_id    = (int) $r['id'];
            $date     = !empty($r['date_creation']) ? $r['date_creation'] : date('Y-m-d H:i:s');

            if ($this->award($users_id, (int) $rule['xp_value'], 'kb_article_created', $kb_id, $date,
                sprintf(__('Artigo KB %d criado', 'gamification'), $kb_id), 0, 'KnowbaseItem')) {
                $this->stats['kb']++;
            }
        }
    }

    /**
     * Insere uma transacao de XP historica (idempotente) com a data real do
     * evento e o saldo corrente do usuario. Retorna true se inseriu.
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

        // Saldo corrente: inicializa com o que ja existe no banco para o usuario.
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

        $this->stats['transactions']++;
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
}
