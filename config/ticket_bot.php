<?php

return [
    // Only the SHA-256 digest of the bot's high-entropy bearer token is stored here.
    'token_sha256' => env('TICKET_BOT_TOKEN_SHA256'),
];
