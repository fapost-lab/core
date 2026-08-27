<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Deployment;

use RuntimeException;

/**
 * Reads and rewrites a dotenv file in place.
 *
 * An installer edits a file the operator owns and has almost certainly hand-tuned,
 * so this rewrites only the lines it is asked to and leaves everything else —
 * comments, blank lines, ordering, unrelated keys — byte for byte as it was.
 * Regenerating the file from a template would be simpler and would silently
 * discard whatever the operator had put there.
 */
final class EnvFile
{
    /** Values containing anything outside this set are quoted on write. */
    private const string BARE_VALUE = '/^[A-Za-z0-9_.\/:@-]*$/';

    public function __construct(
        private readonly string $path,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Current value of a key, or null when it is absent or empty.
     */
    public function get(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            if (! $this->matches($line, $key)) {
                continue;
            }

            $value = $this->unquote(mb_trim(explode('=', $line, 2)[1] ?? ''));

            return '' === $value ? null : $value;
        }

        return null;
    }

    /**
     * Apply the given key/value pairs, updating existing lines and appending the rest.
     *
     * @param  array<string, string>  $values
     * @param  string|null            $sectionComment  Heading placed above newly appended keys.
     */
    public function set(array $values, ?string $sectionComment = null): void
    {
        if (! $this->exists()) {
            throw new RuntimeException("Environment file not found: {$this->path}");
        }

        $lines    = $this->lines();
        $appended = [];

        foreach ($values as $key => $value) {
            $formatted = $key . '=' . $this->format($value);
            $replaced  = false;

            foreach ($lines as $index => $line) {
                if ($this->matches($line, $key)) {
                    $lines[$index] = $formatted;
                    $replaced      = true;

                    break;
                }
            }

            if (! $replaced) {
                $appended[] = $formatted;
            }
        }

        if ([] !== $appended) {
            $lines[] = '';

            if (null !== $sectionComment) {
                $lines[] = '# ' . $sectionComment;
            }

            $lines = [...$lines, ...$appended];
        }

        $this->write($lines);
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $contents = (string) file_get_contents($this->path);

        return explode("\n", mb_rtrim($contents, "\n"));
    }

    /**
     * @param  list<string>  $lines
     */
    private function write(array $lines): void
    {
        if (false === file_put_contents($this->path, implode("\n", $lines) . "\n")) {
            throw new RuntimeException("Could not write environment file: {$this->path}");
        }
    }

    /**
     * Whether a line assigns the given key, ignoring commented-out assignments.
     */
    private function matches(string $line, string $key): bool
    {
        $trimmed = mb_ltrim($line);

        if (str_starts_with($trimmed, '#')) {
            return false;
        }

        return 1 === preg_match('/^' . preg_quote($key, '/') . '\s*=/', $trimmed);
    }

    /**
     * Quote anything that would not survive a bare assignment.
     */
    private function format(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        if (1 === preg_match(self::BARE_VALUE, $value)) {
            return $value;
        }

        return '"' . str_replace('"', '\"', $value) . '"';
    }

    private function unquote(string $value): string
    {
        if (mb_strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[mb_strlen($value) - 1];

            if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                return mb_substr($value, 1, -1);
            }
        }

        return $value;
    }
}
