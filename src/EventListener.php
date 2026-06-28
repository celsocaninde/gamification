<?php

namespace GlpiPlugin\Gamification;

use Ticket;
use TicketSatisfaction;
use KnowbaseItem;

class EventListener
{
    public static function onTicketCreated(Ticket $ticket): void
    {
        // No XP awarded here normally, but we track it if needed.
        // FCR calculation uses $ticket->fields['date'] when solved.
    }

    public static function onTicketUpdated(Ticket $ticket): void
    {
        if (empty($ticket->fields['id'])) {
            return;
        }

        $old_status = $ticket->oldvalues['status'] ?? null;
        $new_status = $ticket->fields['status'] ?? null;

        if ($old_status !== null && $new_status !== null && $old_status != $new_status) {
            $users_id = self::getAssignedTechnician($ticket->fields['id']);
            if (!$users_id) {
                return;
            }
            $entities_id = (int) ($ticket->fields['entities_id'] ?? 0);

            // Ticket Solved (status 5)
            if ($new_status == Ticket::SOLVED || $new_status == Ticket::CLOSED) {
                // Basic Ticket Resolved
                if ($rule = Rule::getRuleForEvent('ticket_resolved')) {
                    if (!self::alreadyAwarded($users_id, 'ticket_resolved', 'Ticket', $ticket->fields['id'])) {
                        Score::addXP($users_id, $rule['xp_value'], 'ticket_resolved', 'Ticket', $ticket->fields['id'], sprintf(__('Ticket %d resolvido', 'gamification'), $ticket->fields['id']), $entities_id);
                    }
                }

                // FCR Check
                $fcr_max = (int)Config::getConfig('fcr_max_minutes') ?: 60;
                $date_created = strtotime($ticket->fields['date']);
                $date_solved = strtotime($ticket->fields['solvedate'] ?? date('Y-m-d H:i:s'));
                $minutes_taken = ($date_solved - $date_created) / 60;

                if ($minutes_taken <= $fcr_max && !self::wasEscalated($ticket->fields['id'])) {
                    if ($rule = Rule::getRuleForEvent('ticket_resolved_fcr')) {
                        if (!self::alreadyAwarded($users_id, 'ticket_resolved_fcr', 'Ticket', $ticket->fields['id'])) {
                            Score::addXP($users_id, $rule['xp_value'], 'ticket_resolved_fcr', 'Ticket', $ticket->fields['id'], sprintf(__('FCR no Ticket %d', 'gamification'), $ticket->fields['id']), $entities_id);
                        }
                    }
                }

                // SLA Check
                if (!empty($ticket->fields['time_to_resolve'])) {
                    $ttr = strtotime($ticket->fields['time_to_resolve']);
                    if ($date_solved <= $ttr) {
                        if ($rule = Rule::getRuleForEvent('sla_met')) {
                            if (!self::alreadyAwarded($users_id, 'sla_met', 'Ticket', $ticket->fields['id'])) {
                                Score::addXP($users_id, $rule['xp_value'], 'sla_met', 'Ticket', $ticket->fields['id'], sprintf(__('SLA cumprido no Ticket %d', 'gamification'), $ticket->fields['id']), $entities_id);
                            }
                        }
                    }
                }

                // Streak update (handled in Score::addXP implicitly or explicitly)
                $score = Score::getOrCreate($users_id, $entities_id);
                $new_streak = $score['current_streak'] + 1;
                $best_streak = max($score['best_streak'], $new_streak);
                global $DB;
                $DB->update(Score::$table, [
                    'current_streak' => $new_streak,
                    'best_streak'    => $best_streak
                ], ['users_id' => $users_id, 'entities_id' => $entities_id]);

            } 
            // Ticket Reopened (from SOLVED/CLOSED to anything else like INCOMING)
            elseif (($old_status == Ticket::SOLVED || $old_status == Ticket::CLOSED) && $new_status != Ticket::SOLVED && $new_status != Ticket::CLOSED) {
                if (Config::getConfig('enable_penalties')) {
                    if ($rule = Rule::getRuleForEvent('ticket_reopened')) {
                        Score::removeXP($users_id, abs($rule['xp_value']), 'ticket_reopened', 'Ticket', $ticket->fields['id'], sprintf(__('Ticket %d reaberto', 'gamification'), $ticket->fields['id']), $entities_id);
                    }
                }

                // Reset streak
                global $DB;
                $DB->update(Score::$table, [
                    'current_streak' => 0
                ], ['users_id' => $users_id, 'entities_id' => $entities_id]);
            }
        }
    }

    public static function onSatisfactionReceived(TicketSatisfaction $satisfaction): void
    {
        self::processSatisfaction($satisfaction);
    }

    public static function onSatisfactionUpdated(TicketSatisfaction $satisfaction): void
    {
        self::processSatisfaction($satisfaction);
    }

    private static function processSatisfaction(TicketSatisfaction $satisfaction): void
    {
        if (empty($satisfaction->fields['tickets_id']) || empty($satisfaction->fields['satisfaction'])) {
            return;
        }

        $users_id = self::getAssignedTechnician($satisfaction->fields['tickets_id']);
        if (!$users_id) {
            return;
        }
        $entities_id = self::getTicketEntity((int) $satisfaction->fields['tickets_id']);

        $rating = (int)$satisfaction->fields['satisfaction'];

        if ($rating === 5) {
            if ($rule = Rule::getRuleForEvent('satisfaction_max')) {
                if (!self::alreadyAwarded($users_id, 'satisfaction_max', 'Ticket', $satisfaction->fields['tickets_id'])) {
                    Score::addXP($users_id, $rule['xp_value'], 'satisfaction_max', 'Ticket', $satisfaction->fields['tickets_id'], sprintf(__('Satisfação 5 estrelas no Ticket %d', 'gamification'), $satisfaction->fields['tickets_id']), $entities_id);
                }
            }
        } elseif ($rating === 4) {
            if ($rule = Rule::getRuleForEvent('satisfaction_good')) {
                if (!self::alreadyAwarded($users_id, 'satisfaction_good', 'Ticket', $satisfaction->fields['tickets_id'])) {
                    Score::addXP($users_id, $rule['xp_value'], 'satisfaction_good', 'Ticket', $satisfaction->fields['tickets_id'], sprintf(__('Satisfação 4 estrelas no Ticket %d', 'gamification'), $satisfaction->fields['tickets_id']), $entities_id);
                }
            }
        }
    }

    private static function getTicketEntity(int $tickets_id): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 'entities_id',
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $tickets_id],
        ])->current();
        return $row ? (int) $row['entities_id'] : 0;
    }

    public static function onKBArticleCreated(KnowbaseItem $item): void
    {
        if (empty($item->fields['users_id'])) {
            return;
        }

        $users_id = $item->fields['users_id'];
        $entities_id = (int) ($item->fields['entities_id'] ?? 0);
        if ($rule = Rule::getRuleForEvent('kb_article_created')) {
            if (!self::alreadyAwarded($users_id, 'kb_article_created', 'KnowbaseItem', $item->fields['id'])) {
                Score::addXP($users_id, $rule['xp_value'], 'kb_article_created', 'KnowbaseItem', $item->fields['id'], sprintf(__('Artigo KB %d criado', 'gamification'), $item->fields['id']), $entities_id);
            }
        }
    }

    private static function getAssignedTechnician(int $tickets_id): ?int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 'users_id',
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => [
                'tickets_id' => $tickets_id,
                'type'       => \CommonITILActor::ASSIGN // 2
            ],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1
        ])->current();

        return $row ? (int)$row['users_id'] : null;
    }

    private static function alreadyAwarded(int $users_id, string $event_type, string $itemtype, int $items_id): bool
    {
        global $DB;
        $count = countElementsInTable('glpi_plugin_gamification_xptransactions', [
            'users_id'        => $users_id,
            'event_type'      => $event_type,
            'source_itemtype' => $itemtype,
            'source_items_id' => $items_id
        ]);
        return $count > 0;
    }

    public static function isAfterHours(string $datetime): bool
    {
        $start = Config::getConfig('business_hours_start') ?: '08:00';
        $end = Config::getConfig('business_hours_end') ?: '18:00';
        $days = explode(',', Config::getConfig('business_days') ?: '1,2,3,4,5');

        $ts = strtotime($datetime);
        $time = date('H:i', $ts);
        $day_of_week = date('N', $ts); // 1 (for Monday) through 7 (for Sunday)

        if (!in_array($day_of_week, $days)) {
            return true;
        }

        if ($time < $start || $time > $end) {
            return true;
        }

        return false;
    }

    private static function wasEscalated(int $tickets_id): bool
    {
        // A ticket is considered escalated if more than one individual technician
        // is simultaneously assigned to it at resolution time. Group routing is
        // intentionally excluded — adding/changing groups is administrative, not
        // a hand-off to another person.
        return countElementsInTable('glpi_tickets_users', [
            'tickets_id' => $tickets_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]) > 1;
    }
}
