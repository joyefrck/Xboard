<?php
namespace App\Console\Commands;

use App\Services\SubscriptionBehaviorSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB};

class SubscriptionBehaviorSnapshot extends Command
{
    protected $signature = 'behavior:snapshot {--mode=identities} {--ids=} {--at=}';
    protected $description = 'Emit whitelisted subscription behavior identities or daily traffic totals';
    public function handle(): int
    {
        try {
            $atText = (string) ($this->option('at') ?: time());
            if (!preg_match('/^[1-9][0-9]{0,10}$/D', $atText) || (int) $atText > time() + 300) throw new \InvalidArgumentException('invalid_time');
            $config = config('subscription_behavior') ?? require config_path('subscription_behavior.php');
            $service = new SubscriptionBehaviorSnapshotService(DB::connection(), Cache::store('redis'), $config);
            if ($this->option('mode') === 'identities') $result = $service->identities((int) $atText);
            elseif ($this->option('mode') === 'traffic') {
                $raw = (string) $this->option('ids');
                if (strlen($raw) > 100000 || ($raw !== '' && !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $raw))) throw new \InvalidArgumentException('invalid_ids');
                $ids = $raw === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $raw))));
                if (count($ids) > 10000 || ($ids && min($ids) <= 0)) throw new \InvalidArgumentException('invalid_ids');
                $result = $service->traffic($ids, (int) $atText);
            } else throw new \InvalidArgumentException('invalid_mode');
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->line('{"status":"failed","error":"snapshot_failed"}');
            return self::FAILURE;
        }
    }
}
