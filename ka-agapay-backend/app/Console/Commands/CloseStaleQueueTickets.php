<?php
// app/Console/Commands/CloseStaleQueueTickets.php
//
// Closes the tickets yesterday left behind.
//
// A queue is a thing that happens on a day. Somebody takes a number, waits,
// is called, and either is served or goes home. What actually happens at a
// busy RHU is that the shift ends with tickets still open: called and never
// answered, in service when the doctor left, waiting when the doors closed.
// Nothing ever closed those, so they stayed "waiting" for good.
//
// The visible damage was a dashboard reporting ten people waiting beside a
// queue desk that correctly showed none. The real damage is that every count
// built on open tickets -- the sidebar badge, the priority list, the average
// wait -- drifted further from the truth every day, and staff learn to ignore
// a number that is always wrong.
//
// A ticket from a previous day is a person who is not in the building. That is
// a no-show, recorded as one, with a note saying it was closed automatically
// so nobody reads it as a judgement on the patient.

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CloseStaleQueueTickets extends Command
{
    protected $signature = 'queue:close-stale
                            {--days=0 : Close tickets issued this many days before today (0 = everything before today)}
                            {--dry-run : Report what would be closed without changing anything}';

    protected $description = "Close queue tickets left open from a previous day so today's counts mean something";

    public function handle(): int
    {
        if (!Schema::hasTable('queue_tickets')) {
            $this->warn('No queue_tickets table; nothing to do.');

            return self::SUCCESS;
        }

        $cutoff = today()->subDays(max(0, (int) $this->option('days')));

        $query = DB::table('queue_tickets')
            ->whereIn('status', ['waiting', 'called', 'in_service'])
            ->whereDate('issued_at', '<', $cutoff);

        $stale = (clone $query)->count();

        if ($stale === 0) {
            $this->info('No stale queue tickets.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$stale} ticket(s) would be closed as no-show (issued before {$cutoff->toDateString()}).");

            return self::SUCCESS;
        }

        $updates = ['status' => 'no_show', 'updated_at' => now()];

        // Written only where the column exists, so this cannot break on an
        // older schema. The note matters: a nurse reading the record later
        // should see that the system closed it, not a colleague.
        if (Schema::hasColumn('queue_tickets', 'notes')) {
            $updates['notes'] = DB::raw(
                "CONCAT(COALESCE(notes, ''), ' [Closed automatically: ticket left open from a previous day.]')"
            );
        }

        if (Schema::hasColumn('queue_tickets', 'service_ended_at')) {
            $updates['service_ended_at'] = now();
        }

        $closed = $query->update($updates);

        $this->info("Closed {$closed} stale queue ticket(s) as no-show.");
        logger()->info("[queue:close-stale] Closed {$closed} ticket(s) left open before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
