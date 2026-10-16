<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Tenancy\Services\PlatformSettings;
use Fapost\Foundation\Settings\DTO\PlatformSettingDefinition;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;
use Illuminate\Console\Command;

/**
 * Lists every platform-wide setting with its effective value and where the value comes from. Reads only.
 */
final class ListPlatformSettingsCommand extends Command
{
    /** @var string */
    protected $signature = 'platform:settings';

    /** @var string */
    protected $description = 'List the platform-wide settings with their value, source, default and allowed range';

    public function handle(PlatformSettings $settings): int
    {
        $values = [];

        foreach ($settings->values() as $value) {
            $values[$value->key] = $value;
        }

        $rows = [];

        foreach ($settings->definitions() as $definition) {
            $value = $values[$definition->key];

            $rows[] = [
                $definition->key,
                $this->render($value->value),
                $value->stored ? 'stored' : 'default',
                $this->render($definition->default),
                $this->allowed($definition),
                $definition->hostModeOnly ? 'host mode only' : '',
            ];
        }

        $this->table(['Key', 'Value', 'Source', 'Default', 'Allowed', 'Note'], $rows);

        return self::SUCCESS;
    }

    private function render(int|bool|string|null $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            null === $value => '-',
            default         => (string) $value,
        };
    }

    private function allowed(PlatformSettingDefinition $definition): string
    {
        $unit = null === $definition->unit ? '' : ' ' . $definition->unit;

        return match ($definition->type) {
            PlatformSettingType::Integer => sprintf('%s..%s%s', $definition->min ?? '*', $definition->max ?? '*', $unit),
            PlatformSettingType::Boolean => 'true, false',
            PlatformSettingType::Choice  => implode(', ', $definition->choices),
            PlatformSettingType::Text    => null === $definition->max ? 'text' : sprintf('text, up to %d characters', $definition->max),
        };
    }
}
