<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\DTOs\HttpResponse;
use App\Domains\Flow\Exceptions\HttpTransportException;

interface HttpClientInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     *
     * @throws HttpTransportException
     */
    public function post(string $url, array $payload, array $headers = [], int $timeout = 10): HttpResponse;
}
