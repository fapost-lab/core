<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use App\Domains\Flow\Contracts\HttpClientInterface;
use App\Domains\Flow\DTOs\HttpResponse;
use App\Domains\Flow\Exceptions\HttpTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

final readonly class LaravelHttpClient implements HttpClientInterface
{
    public function __construct(
        private Factory $http,
    ) {
    }

    public function post(string $url, array $payload, array $headers = [], int $timeout = 10): HttpResponse
    {
        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new HttpTransportException($exception->getMessage(), (int)$exception->getCode(), $exception);
        }

        $body = $response->json();

        return new HttpResponse(
            status: $response->status(),
            body: is_array($body) ? $body : [],
        );
    }
}
