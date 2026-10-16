<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Tenancy\Services\PlatformSettings;
use Fapost\Foundation\Settings\DTO\PlatformSettingDefinition;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;
use Fapost\Foundation\Settings\Exceptions\InvalidPlatformSettingsException;
use LogicException;

/**
 * Stores a value for one platform-wide setting. The value is parsed by the setting's type and checked by
 * {@see PlatformSettings}; a refused value is reported and nothing is written.
 */
final class SetPlatformSettingCommand extends PlatformSettingCommand
{
    /** @var string */
    protected $signature = 'platform:settings:set {key : Setting key} {value : New value}';

    /** @var string */
    protected $description = 'Store a value for a platform-wide setting';

    public function handle(PlatformSettings $settings): int
    {
        $key = (string) $this->argument('key');

        try {
            $definition = $settings->definition($key);
        } catch (LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $parsed = $this->parse($definition, (string) $this->argument('value'));

        if (null === $parsed) {
            $this->components->error(sprintf('"%s" %s', $key, match ($definition->type) {
                PlatformSettingType::Integer => 'must be a whole number.',
                default                      => 'must be true or false.',
            }));

            return self::FAILURE;
        }

        try {
            $settings->update([$key => $parsed[0]], $this->actor());
        } catch (InvalidPlatformSettingsException $exception) {
            foreach ($exception->violations as $violatedKey => $message) {
                $this->components->error(sprintf('"%s" %s', $violatedKey, $message));
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('"%s" is now %s.', $key, is_bool($parsed[0]) ? ($parsed[0] ? 'true' : 'false') : $parsed[0]));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int|bool|string}|null null when the text is not of the setting's type
     */
    private function parse(PlatformSettingDefinition $definition, string $raw): ?array
    {
        return match ($definition->type) {
            PlatformSettingType::Integer => 1 === preg_match('/^-?\d+$/', $raw) ? [(int) $raw] : null,
            PlatformSettingType::Boolean => match (mb_strtolower($raw)) {
                'true', '1', 'yes', 'on'  => [true],
                'false', '0', 'no', 'off' => [false],
                default                   => null,
            },
            PlatformSettingType::Text,
            PlatformSettingType::Choice => [$raw],
        };
    }
}
