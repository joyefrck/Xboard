<?php

return [
    // Only the SHA-256 digest of the bot's high-entropy bearer token is stored here.
    'token_sha256' => env('TICKET_BOT_TOKEN_SHA256'),
    'operators' => json_decode((string) env('TICKET_BOT_OPERATORS', '{}'), true) ?: [],
    'access_plan_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('TICKET_BOT_ACCESS_PLAN_IDS', ''))), 'ctype_digit')),
];
