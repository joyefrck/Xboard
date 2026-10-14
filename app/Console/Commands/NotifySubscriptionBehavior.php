<?php
namespace App\Console\Commands;

use App\Services\{SubscriptionBehaviorAlertService, SubscriptionBehaviorTelegramSender};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB};

class NotifySubscriptionBehavior extends Command
{
    protected $signature = 'behavior:notify {--dry-run} {--test}';
    protected $description = 'Send one merged subscription behavior alert to the explicitly configured owner';
    public function handle(): int
    {
        try {
            $config = config('subscription_behavior') ?? require config_path('subscription_behavior.php');
            $service = new SubscriptionBehaviorAlertService(DB::connection(), Cache::store('redis'), new SubscriptionBehaviorTelegramSender(), $config);
            if ($this->option('test')) $result = $service->test((bool) $this->option('dry-run'));
            else {
                $raw = stream_get_contents(STDIN, $config['input_max_bytes'] + 1);
                if ($raw === false || strlen($raw) > $config['input_max_bytes']) throw new \InvalidArgumentException('input_too_large');
                $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($input)) throw new \InvalidArgumentException('invalid_input');
                $result = $service->handle($input, (bool) $this->option('dry-run'));
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return in_array($result['status'], ['pending', 'uncertain'], true) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            // Never print exception text: DB/HTTP exceptions may contain email or credentials.
            $this->line('{"status":"failed","error":"notification_failed"}');
            return self::FAILURE;
        }
    }
}
