<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\Telegram\TelegramWebhookOptions;
use App\Filament\Resources\Assistants\Schemas\ChannelFormSchema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The Filament channel form still lists the Telegram update types and bounds itself, until the admin moves to the
 * console form. Until then two lists exist, and this keeps them the same.
 */
final class TelegramWebhookOptionsTest extends TestCase
{
    public function test_the_update_types_are_the_ones_the_filament_form_offers_in_the_same_order(): void
    {
        $this->assertSame(TelegramWebhookOptions::ALLOWED_UPDATES, array_keys($this->select()->getOptions()));
    }

    public function test_the_connection_bounds_are_the_ones_the_filament_form_has(): void
    {
        $input = collect($this->telegramFields())
            ->first(fn (object $component): bool => $component instanceof TextInput && 'config.max_connections' === $component->getName());

        $this->assertInstanceOf(TextInput::class, $input);
        $this->assertSame(TelegramWebhookOptions::MAX_CONNECTIONS_MIN, $input->getMinValue());
        $this->assertSame(TelegramWebhookOptions::MAX_CONNECTIONS_MAX, $input->getMaxValue());
        $this->assertSame(TelegramWebhookOptions::MAX_CONNECTIONS_DEFAULT, $input->getDefaultState());
    }

    private function select(): Select
    {
        $select = collect($this->telegramFields())
            ->first(fn (object $component): bool => $component instanceof Select && 'config.allowed_updates' === $component->getName());

        $this->assertInstanceOf(Select::class, $select);

        return $select;
    }

    /**
     * @return array<object>
     */
    private function telegramFields(): array
    {
        $components = ChannelFormSchema::configure(Schema::make())->getComponents(withActions: false, withHidden: true);
        $children   = new ReflectionProperty(Section::class, 'childComponents');
        $section    = collect($components)->first(fn (object $component): bool => $component instanceof Section);

        $this->assertInstanceOf(Section::class, $section);

        /** @var array<string, array<object>> $groups */
        $groups = $children->getValue($section);

        return $groups['default'];
    }
}
