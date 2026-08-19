<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramService
{
    private const MAX_ATTEMPTS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 10;
    private const DEFAULT_RETRY_DELAY_SECONDS = 1;
    private const MAX_RETRY_AFTER_SECONDS = 5;

    protected HttpClientInterface $http;
    protected string $apiUrl;

    public function __construct(
        ?string $token = null,
        ?HttpClientInterface $http = null,
        TelegramBotProfile $profile = TelegramBotProfile::GENERAL,
        ?TelegramCredentialService $credentials = null
    ) {
        $credentials ??= app(TelegramCredentialService::class);
        $botToken = $token ?? $credentials->getToken($profile);
        if (blank($botToken)) {
            throw new ApiException('Telegram 机器人令牌未配置');
        }
        $this->apiUrl = "https://api.telegram.org/bot{$botToken}/";

        $this->http = $http ?? new NativeHttpClient([
            'headers' => [
                'Accept' => 'application/json',
            ],
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            'max_duration' => self::REQUEST_TIMEOUT_SECONDS,
        ]);
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '', array $options = []): void
    {
        $text = $parseMode === 'markdown' ? str_replace('_', '\_', $text) : $text;

        $this->request('sendMessage', $this->normalizeParams(array_filter(
            array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode ?: null,
            ], $options),
            fn($value) => $value !== null
        )));
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void
    {
        $this->request('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text ?: null,
            'show_alert' => $showAlert,
        ], fn($value) => $value !== null));
    }

    public function editMessageText(int $chatId, int $messageId, string $text, string $parseMode = '', array $options = []): void
    {
        $text = $parseMode === 'markdown' ? str_replace('_', '\_', $text) : $text;

        $this->request('editMessageText', $this->normalizeParams(array_filter(
            array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode ?: null,
            ], $options),
            fn($value) => $value !== null
        )));
    }

    public function approveChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function getMe(): object
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url, array $options = []): object
    {
        $result = $this->request('setWebhook', $this->normalizeParams(array_merge([
            'url' => $url,
        ], $options)));
        return $result;
    }

    public function getWebhookInfo(): object
    {
        return $this->request('getWebhookInfo');
    }

    /**
     * 注册 Bot 命令列表
     */
    public function registerBotCommands(?array $commands = null): void
    {
        try {
            $commands ??= HookManager::filter('telegram.bot.commands', []);

            if (empty($commands)) {
                Log::warning('没有找到任何 Telegram Bot 命令');
                return;
            }

            $this->request('setMyCommands', [
                'commands' => json_encode($commands),
                'scope' => json_encode(['type' => 'default'])
            ]);

            Log::info('Telegram Bot 命令注册成功', [
                'commands_count' => count($commands),
                'commands' => $commands
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram Bot 命令注册失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 获取当前注册的命令列表
     */
    public function getMyCommands(): object
    {
        return $this->request('getMyCommands');
    }

    /**
     * 删除所有命令
     */
    public function deleteMyCommands(): object
    {
        return $this->request('deleteMyCommands');
    }

    public function sendMessageWithAdmin(string $message, bool $isStaff = false, array $options = []): void
    {
        $query = User::where('telegram_id', '!=', null);
        $query->where(
            fn($q) => $q->where('is_admin', 1)
                ->when($isStaff, fn($q) => $q->orWhere('is_staff', 1))
        );
        $users = $query->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message, $options);
        }
    }

    protected function normalizeParams(array $params): array
    {
        foreach (['reply_markup', 'allowed_updates', 'commands', 'scope'] as $jsonKey) {
            if (isset($params[$jsonKey]) && is_array($params[$jsonKey])) {
                $params[$jsonKey] = json_encode($params[$jsonKey], JSON_UNESCAPED_UNICODE);
            }
        }

        return $params;
    }

    protected function request(string $method, array $params = []): object
    {
        try {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                try {
                    $response = $this->http->request('GET', $this->apiUrl . $method, [
                        'query' => $params,
                    ]);
                    $status = $response->getStatusCode();
                    $content = $response->getContent(false);
                } catch (TransportExceptionInterface $e) {
                    if ($attempt < self::MAX_ATTEMPTS) {
                        $this->sleepBeforeRetry(self::DEFAULT_RETRY_DELAY_SECONDS);
                        continue;
                    }

                    throw $e;
                }

                $data = json_decode($content);

                if ($status === 429 && $attempt < self::MAX_ATTEMPTS) {
                    $retryAfter = is_object($data)
                        ? $this->telegramRetryAfterSeconds($data)
                        : self::DEFAULT_RETRY_DELAY_SECONDS;
                    $this->sleepBeforeRetry($retryAfter);
                    continue;
                }

                if ($status >= 500 && $attempt < self::MAX_ATTEMPTS) {
                    $this->sleepBeforeRetry(self::DEFAULT_RETRY_DELAY_SECONDS);
                    continue;
                }

                if (!is_object($data)) {
                    throw new ApiException('无效的 Telegram API 响应');
                }

                if ($status < 200 || $status >= 300) {
                    $description = $data->description ?? null;
                    throw new ApiException($description
                        ? "Telegram API 错误: {$description}"
                        : "HTTP 请求失败: {$status}");
                }

                if (!isset($data->ok)) {
                    throw new ApiException('无效的 Telegram API 响应');
                }

                if (!$data->ok) {
                    $description = $data->description ?? '未知错误';
                    throw new ApiException("Telegram API 错误: {$description}");
                }

                return $data;
            }

            throw new ApiException('Telegram API 重试次数已耗尽');
        } catch (\Throwable $e) {
            Log::error('Telegram API 请求失败', [
                'method' => $method,
                'params' => $this->redactParams($params),
                'error' => $e->getMessage(),
            ]);

            throw new ApiException("Telegram 服务错误: {$e->getMessage()}");
        }
    }

    private function telegramRetryAfterSeconds(object $data): int
    {
        $retryAfter = (int)($data->parameters->retry_after ?? self::DEFAULT_RETRY_DELAY_SECONDS);

        return min(
            self::MAX_RETRY_AFTER_SECONDS,
            max(self::DEFAULT_RETRY_DELAY_SECONDS, $retryAfter)
        );
    }

    private function sleepBeforeRetry(int $seconds): void
    {
        usleep($seconds * 1_000_000);
    }

    private function redactParams(array $params): array
    {
        foreach (['secret_token', 'text'] as $sensitiveKey) {
            if (array_key_exists($sensitiveKey, $params)) {
                $params[$sensitiveKey] = '[REDACTED]';
            }
        }

        return $params;
    }
}
