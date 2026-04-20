<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface ContentTranslatorInterface
{
    public function translate(string $key, string $language): string;

    public function resolveField(array|string $content, string $language): string;

    public function invalidate(string $tenantId, string $language): void;
}
