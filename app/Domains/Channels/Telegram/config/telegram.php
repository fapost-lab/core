<?php

declare(strict_types=1);

return [
    // Bot API origin. Only a load test points it elsewhere (tools/loadtest/telegram-stub.php).
    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
];
