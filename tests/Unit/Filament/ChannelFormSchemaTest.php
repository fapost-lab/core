<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Resources\Assistants\Schemas\ChannelFormSchema;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use ReflectionProperty;
use Tests\TestCase;

final class ChannelFormSchemaTest extends TestCase
{
    public function test_channel_form_contains_telegram_specific_webhook_fields(): void
    {
        $schema = ChannelFormSchema::configure(Schema::make());

        $components      = $schema->getComponents(withActions: false, withHidden: true);
        $childComponents = new ReflectionProperty(Section::class, 'childComponents');
        $childComponents->setAccessible(true);

        $telegramConfigGroup = collect($components)
            ->first(fn (object $component): bool => $component instanceof Section && __('staff.channels.fields.config') === $component->getHeading());

        $this->assertInstanceOf(Section::class, $telegramConfigGroup);
        $this->assertSame(['default' => 'full'], $telegramConfigGroup->getColumnSpan());

        /** @var array<string, array<object>> $groupChildren */
        $groupChildren  = $childComponents->getValue($telegramConfigGroup);
        $telegramFields = $groupChildren['default'];
        $telegramNames  = array_map(
            fn (object $component): string => $component->getName(),
            $telegramFields,
        );

        $this->assertContains('config.allowed_updates', $telegramNames);
        $this->assertContains('config.max_connections', $telegramNames);
        $this->assertNotNull(
            collect($components)->first(fn (object $component): bool => $component instanceof KeyValue && 'config_kv' === $component->getName())
        );
        $this->assertNotNull(
            collect($telegramFields)->first(fn (object $component): bool => $component instanceof Select && 'config.allowed_updates' === $component->getName())
        );
        $this->assertNotNull(
            collect($telegramFields)->first(fn (object $component): bool => $component instanceof TextInput && 'config.max_connections' === $component->getName())
        );
    }

    public function test_webhook_hash_is_shown_read_only_and_never_written_back(): void
    {
        $components = ChannelFormSchema::configure(Schema::make())->getComponents(withActions: false, withHidden: true);

        $hash = collect($components)
            ->first(fn (object $component): bool => $component instanceof TextInput && 'webhook_public_hash' === $component->getName());

        $this->assertInstanceOf(TextInput::class, $hash);
        $this->assertTrue($hash->isReadOnly());
        $this->assertFalse($hash->isDehydrated());
    }

    public function test_secret_token_offers_a_generate_action(): void
    {
        $components = ChannelFormSchema::configure(Schema::make())->getComponents(withActions: false, withHidden: true);

        $secretToken = collect($components)
            ->first(fn (object $component): bool => $component instanceof TextInput && 'secret_token' === $component->getName());

        $this->assertInstanceOf(TextInput::class, $secretToken);
        // The revealable password field contributes its own show/hide actions.
        $this->assertContains(
            'generateSecretToken',
            array_map(fn (object $action): string => $action->getName(), $secretToken->getSuffixActions()),
        );
    }

    public function test_allowed_updates_offers_bulk_selection_actions(): void
    {
        $components = ChannelFormSchema::configure(Schema::make())->getComponents(withActions: false, withHidden: true);

        $childComponents = new ReflectionProperty(Section::class, 'childComponents');
        $childComponents->setAccessible(true);

        $section = collect($components)
            ->first(fn (object $component): bool => $component instanceof Section && __('staff.channels.fields.config') === $component->getHeading());

        /** @var array<string, array<object>> $groupChildren */
        $groupChildren = $childComponents->getValue($section);

        $allowedUpdates = collect($groupChildren['default'])
            ->first(fn (object $component): bool => $component instanceof Select && 'config.allowed_updates' === $component->getName());

        $this->assertInstanceOf(Select::class, $allowedUpdates);
        $this->assertSame(
            ['selectAllAllowedUpdates', 'clearAllowedUpdates'],
            array_map(fn (object $action): string => $action->getName(), $allowedUpdates->getHintActions()),
        );
    }
}
