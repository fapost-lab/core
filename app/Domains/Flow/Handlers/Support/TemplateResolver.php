<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Support;

final class TemplateResolver
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function resolve(mixed $value, array $state): mixed
    {
        if ( ! is_string($value) || ! str_contains($value, '{{')) {
            return $value;
        }

        return (string) preg_replace_callback('/\{\{(.+?)\}\}/', static function (array $matches) use ($state): string {
            $path     = mb_trim((string) ($matches[1] ?? ''));
            $resolved = data_get($state, $path);

            return null === $resolved ? '' : (string) $resolved;
        }, $value);
    }
}
