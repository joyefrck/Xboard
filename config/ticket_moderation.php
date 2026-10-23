<?php

return [
    'enabled' => env('TICKET_MODERATION_ENABLED', false),
    'api_key' => env('DEEPSEEK_API_KEY', ''),
    'model' => env('DEEPSEEK_MODERATION_MODEL', 'deepseek-flash'),
];
