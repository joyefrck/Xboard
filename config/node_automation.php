<?php

return [
    'binary' => env('NODE_AUTOMATION_BINARY', '/usr/local/bin/sing-box'),
    'timeout_binary' => env('NODE_AUTOMATION_TIMEOUT_BINARY', '/usr/bin/timeout'),
    'version' => '1.14.2',
    'targets' => ['https://www.gstatic.com/generate_204', 'https://cp.cloudflare.com/generate_204'],
    'request_timeout' => 8,
    'failure_threshold' => 3,
    'cooldown_seconds' => 600,
    'max_backups' => 10,
];
