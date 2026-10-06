<?php
// app/Support/QueuePressure.php
//
// HOW BUSY AN RHU IS, and how full an event is: the levels the dashboard's
// facility queue map shows, so an alert never disagrees with the map.
//
// A copy of getQueueCongestionLevel, getEventCrowdingLevel, pressureLabel and
// pressureAction in the web admin's src/services/facilityHeatmap.ts. Change
// both together.

namespace App\Support;

use App\Models\QueueTicket;

final class QueuePressure
{
    public const LOW = 'low';
    public const MODERATE = 'moderate';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';

    private const RANK = [self::LOW => 0, self::MODERATE => 1, self::HIGH => 2, self::CRITICAL => 3];

    /** The queue statuses the map counts (QueueService::getLiveQueue). */
    private const ACTIVE_STATUSES = ['waiting', 'called', 'in_service'];

    public static function rank(string $level): int
    {
        return self::RANK[$level] ?? 0;
    }

    /**
     * Today's queue at one RHU, counted as the map counts it. Read-only: unlike
     * QueueService::getLiveQueue it does not reorder the queue, which a job
     * running every minute must not do.
     *
     * @return array{queue: int, waiting: int, in_service: int, priority: int}
     */
    public static function countsFor(int $rhuId): array
    {
        $tickets = QueueTicket::query()
            ->forRhu($rhuId)
            ->forToday()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get();

        $waiting = $tickets->where('status', 'waiting');

        return [
            'queue' => $tickets->count(),
            'waiting' => $waiting->count(),
            'in_service' => $tickets->where('status', 'in_service')->count(),
            'priority' => $waiting->filter(fn (QueueTicket $ticket) => self::isPriority($ticket))->count(),
        ];
    }

    public static function queueLevel(int $queue, int $waiting, int $inService, int $priority): string
    {
        if ($waiting >= 51) return self::CRITICAL;
        if ($waiting >= 26) return self::HIGH;
        if ($waiting >= 11) return self::MODERATE;

        if ($priority >= 15 || ($inService >= 12 && $waiting >= 26)) {
            return self::CRITICAL;
        }

        if ($priority >= 8 || $queue >= 30 || ($inService >= 8 && $waiting >= 11)) {
            return self::HIGH;
        }

        if ($priority >= 4 || $inService >= 8) {
            return self::MODERATE;
        }

        return self::LOW;
    }

    public static function eventLevel(?int $registrants, ?int $slots): string
    {
        if (!$slots || $slots <= 0 || $registrants === null) {
            return self::LOW;
        }

        $fill = $registrants / $slots;

        if ($fill >= 0.91) return self::CRITICAL;
        if ($fill >= 0.71) return self::HIGH;
        if ($fill >= 0.4) return self::MODERATE;

        return self::LOW;
    }

    public static function label(string $level): string
    {
        return match ($level) {
            self::CRITICAL => 'Over Capacity',
            self::HIGH => 'Heavy Queue',
            self::MODERATE => 'Moderate Queue',
            default => 'Low Queue',
        };
    }

    public static function action(string $level, int $priority, int $activeEvents = 0): string
    {
        if ($level === self::CRITICAL) return 'Open another service desk';
        if ($activeEvents > 0 && in_array($level, [self::HIGH, self::CRITICAL], true)) {
            return 'Prepare crowd control for active event';
        }
        if ($priority > 0 && in_array($level, [self::MODERATE, self::HIGH], true)) {
            return 'Call priority patients first';
        }
        if ($level === self::HIGH) return 'Send SMS advisory';
        if ($level === self::MODERATE) return 'Monitor queue and keep staff ready';

        return 'Continue normal queue monitoring';
    }

    /** As isPriorityTicket on the map. */
    private static function isPriority(QueueTicket $ticket): bool
    {
        $category = strtolower((string) ($ticket->priority_category ?? ''));
        $score = (int) ($ticket->priority_score ?? 0);

        return (bool) $ticket->is_emergency
            || (bool) $ticket->is_senior
            || (bool) $ticket->is_pregnant
            || (bool) $ticket->is_pwd
            || (bool) $ticket->is_pediatric
            || (bool) $ticket->is_bhw_endorsed
            || ($category !== '' && $category !== 'regular')
            || $score >= 35;
    }
}
