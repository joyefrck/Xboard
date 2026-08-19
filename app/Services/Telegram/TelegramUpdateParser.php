<?php

namespace App\Services\Telegram;

class TelegramUpdateParser
{
    public function parse(array $data): ?object
    {
        if (isset($data['callback_query'])) {
            return $this->parseCallback($data['callback_query']);
        }

        if (!isset($data['message']['text'])) {
            return null;
        }

        return $this->parseMessage($data['message']);
    }

    private function parseMessage(array $message): object
    {
        $text = (string) $message['text'];
        $parts = explode(' ', $text);
        $command = explode('@', $parts[0], 2)[0];

        $parsed = (object) [
            'command' => $command,
            'args' => array_slice($parts, 1),
            'chat_id' => (int) $message['chat']['id'],
            'message_id' => (int) $message['message_id'],
            'message_type' => 'message',
            'text' => $text,
            'from_id' => (int) ($message['from']['id'] ?? $message['chat']['id']),
            'is_private' => ($message['chat']['type'] ?? '') === 'private',
        ];

        if (isset($message['reply_to_message']['text'])) {
            $parsed->message_type = 'reply_message';
            $parsed->reply_text = (string) $message['reply_to_message']['text'];
        }

        return $parsed;
    }

    private function parseCallback(array $callback): object
    {
        $message = $callback['message'] ?? [];
        $chat = $message['chat'] ?? [];

        return (object) [
            'command' => '',
            'args' => [],
            'chat_id' => (int) ($chat['id'] ?? ($callback['from']['id'] ?? 0)),
            'message_id' => (int) ($message['message_id'] ?? 0),
            'message_type' => 'callback_query',
            'text' => (string) ($message['text'] ?? ''),
            'is_private' => ($chat['type'] ?? 'private') === 'private',
            'callback_query_id' => (string) $callback['id'],
            'callback_data' => (string) ($callback['data'] ?? ''),
            'from_id' => (int) ($callback['from']['id'] ?? 0),
        ];
    }
}
