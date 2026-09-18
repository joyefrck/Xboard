<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\User;
use App\Services\{TelegramBindingService, TelegramService};
use Illuminate\Container\Container;
use Illuminate\Support\Facades\{Cache, Facade};

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$bot = new class extends TelegramService {
    public int $calls = 0;
    public array $chat = [];
    public bool $fail = false;
    public function __construct() {}
    public function getChat(int $id): object {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('offline');
        return (object) ['result' => (object) array_merge(['id'=>$id,'type'=>'private'], $this->chat)];
    }
};
$app->instance(TelegramService::class, $bot);
$service = new TelegramBindingService();
$user = new User(); $user->id = 10;
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
check($service->account($user) === null && $bot->calls === 0, 'unbound users do not query Telegram');
$user->telegram_id = 123456789;
$bot->chat = ['username'=>'sample_account','first_name'=>'示例','last_name'=>'用户','bio'=>'excluded'];
$account = $service->account($user);
check($account === ['id'=>'123456789','username'=>'sample_account','name'=>'示例 用户'], 'only identity fields are returned');
check($service->account($user) === $account && $bot->calls === 1, 'successful results are cached');
$user->telegram_id = 234567890;
$bot->chat = ['first_name'=>'无用户名'];
check($service->account($user) === ['id'=>'234567890','username'=>null,'name'=>'无用户名'], 'rebind does not reuse the previous identity');
$user->telegram_id = 345678901; $bot->fail = true;
$fallback = ['id'=>'345678901','username'=>null,'name'=>null];
check($service->account($user) === $fallback, 'outage preserves the bound ID');
$calls = $bot->calls;
check($service->account($user) === $fallback && $bot->calls === $calls, 'failed lookups are briefly cached');
$bot->fail = false;
foreach ([['id'=>999], ['type'=>'group']] as $chat) {
    Cache::flush(); $bot->chat = $chat;
    check($service->account($user) === $fallback, 'unexpected chats cannot become account identity');
}
$user->telegram_id = null;
check($service->account($user) === null, 'unbinding hides cached identity');
echo "Telegram binding: {$checks} checks passed\n";
