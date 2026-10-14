<?php
namespace App\Services;

use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A single attempt; never shares the business Telegram service retry loop. */
class SubscriptionBehaviorTelegramSender
{
    private HttpClientInterface $http;
    private \Closure $token;
    public function __construct(?HttpClientInterface $http = null, ?callable $token = null)
    {
        $this->http = $http ?? new NativeHttpClient(['timeout' => 15, 'max_duration' => 20, 'max_redirects' => 0]);
        $this->token = \Closure::fromCallable($token ?? fn() => app(TelegramCredentialService::class)->getToken(TelegramBotProfile::TICKET));
    }

    public function assertConfigured(): void
    {
        $token = ($this->token)();
        if (!is_string($token) || trim($token) === '') throw new \RuntimeException('ticket_bot_credentials_missing');
    }

    public function send(string $chatId, string $text, bool $document, string $caption): string
    {
        $token = ($this->token)();
        if (!is_string($token) || trim($token) === '') throw new \RuntimeException('ticket_bot_credentials_missing');
        $url = 'https://api.telegram.org/bot' . $token . '/' . ($document ? 'sendDocument' : 'sendMessage');
        if ($document) {
            $form = new FormDataPart(['chat_id' => $chatId, 'caption' => $caption, 'document' => new DataPart($text, 'subscription-behavior.txt', 'text/plain; charset=utf-8')]);
            $options = ['headers' => $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToIterable()];
        } else {
            $options = ['body' => ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => 'true']];
        }
        try {
            $response = $this->http->request('POST', $url, $options);
            $body = json_decode($response->getContent(false), true, 32, JSON_THROW_ON_ERROR);
            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300 && ($body['ok'] ?? null) === true && is_int($body['result']['message_id'] ?? null)) return 'sent';
            if (($body['ok'] ?? null) === false && is_int($body['error_code'] ?? null)) return 'pending';
            return 'uncertain';
        } catch (\Throwable) {
            // Timeouts, invalid responses and transport failures cannot prove non-delivery.
            return 'uncertain';
        }
    }
}
