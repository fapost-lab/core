<?php

declare(strict_types=1);

return [
    'rate_limit_per_minute' => (int) env('MESSAGING_RATE_LIMIT_PER_MINUTE', 30),
];
