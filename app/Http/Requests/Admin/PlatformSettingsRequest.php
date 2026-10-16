<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\PlatformSettings;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Fapost\Foundation\Settings\DTO\PlatformSettingDefinition;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving the platform settings shown on the screen: every field is optional (a missing one is left alone, an empty one
 * resets the setting to its default), is checked for its type here and cast to it, and the domain then checks the
 * limits.
 *
 * Input names are the setting keys with dots replaced by underscores, because a dot would nest the input.
 */
final class PlatformSettingsRequest extends FormRequest
{
    /**
     * The screen exists in single mode only, and only for an administrator who is not the platform support user. This
     * is not a Gate ability on purpose: administrators pass every ability through `Gate::before`.
     */
    public static function guard(?User $user): void
    {
        abort_unless(TenancyResolutionMode::Single === TenancyResolutionMode::fromConfig(), 404);
        abort_unless(null !== $user && $user->isAdmin() && ! $user->isPlatformSupport(), 403);
    }

    /**
     * The setting key as a form field name.
     */
    public static function fieldName(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        self::guard($user instanceof User ? $user : null);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->shown() as $definition) {
            $rules[self::fieldName($definition->key)] = ['nullable', match ($definition->type) {
                PlatformSettingType::Integer => 'integer',
                PlatformSettingType::Boolean => 'boolean',
                default                      => 'string',
            }];
        }

        return $rules;
    }

    /**
     * The submitted settings by key, cast to their types; null resets the setting.
     *
     * @return array<string, int|bool|string|null>
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes   = [];

        foreach ($this->shown() as $definition) {
            $field = self::fieldName($definition->key);

            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $value = $validated[$field];

            $changes[$definition->key] = null === $value ? null : match ($definition->type) {
                PlatformSettingType::Integer => (int) $value,
                PlatformSettingType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default                      => (string) $value,
            };
        }

        return $changes;
    }

    /**
     * The settings the screen lists: all of them but those that only make sense in host mode.
     *
     * @return list<PlatformSettingDefinition>
     */
    public function shown(): array
    {
        return array_values(array_filter(
            $this->container->make(PlatformSettings::class)->definitions(),
            static fn (PlatformSettingDefinition $definition): bool => ! $definition->hostModeOnly,
        ));
    }
}
