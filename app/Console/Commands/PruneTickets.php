<?php

namespace App\Console\Commands;

use App\Services\TicketRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PruneTickets extends Command
{
    protected $signature = 'tickets:prune {--dry-run : Preview without deleting any data}';

    protected $description = 'Delete tickets, messages and images after 14 days of inactivity';

    public function handle(TicketRetentionService $retention): int
    {
        $cutoff = time() - TicketRetentionService::RETENTION_DAYS * 86400;
        if ($this->option('dry-run')) {
            $this->line(json_encode($retention->preview($cutoff), JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }

        // Also serialize direct invocations with the scheduler and other app instances.
        $lock = Cache::lock('ticket-retention:prune', 6 * 3600);
        if (!$lock->get()) {
            $this->warn('Ticket retention is already running');
            return self::FAILURE;
        }
        try {
            $result = $retention->prune($cutoff);
            Log::info('Ticket retention cleanup completed', $result);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return $result['failed_attachments'] === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
