<?php

return [
    'binary' => env('NODE_AUTOMATION_BINARY', '/usr/local/bin/sing-box'),
    'mihomo_binary' => env('NODE_AUTOMATION_MIHOMO_BINARY', '/usr/local/bin/mihomo'),
    'timeout_binary' => env('NODE_AUTOMATION_TIMEOUT_BINARY', '/usr/bin/timeout'),
    'version' => '1.14.2',
    'targets' => ['https://www.gstatic.com/generate_204', 'https://cp.cloudflare.com/generate_204'],
    'request_timeout' => 8,
    'failure_threshold' => 3,
    'cooldown_seconds' => 600,
    'max_backups' => 10,
    // Only the SHA-256 digest of the AI assistant's bearer token is stored; unset disables the bot API.
    'bot_token_sha256' => env('NODE_AUTOMATION_BOT_TOKEN_SHA256'),
    // Name recorded on operations the bot starts, shown in the admin switch history.
    'bot_actor' => 'hermes',
];
