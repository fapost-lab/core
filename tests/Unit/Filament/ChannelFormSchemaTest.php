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
}
