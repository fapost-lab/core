<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

final readonly class HttpResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        private int $status,
        private array $body,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return $this->body;
    }
}
