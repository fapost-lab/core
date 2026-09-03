<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Assistant\Resources\Conversations\Pages\ViewConversation;
use Tests\TestCase;

final class ViewConversationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function reservedLivewireHooks(): array
    {
        return [
            'messages'             => ['messages'],
            'rules'                => ['rules'],
            'validationAttributes' => ['validationAttributes'],
        ];
    }
    /**
     * Livewire calls `$this->messages()` on the component while validating a
     * form (SupportValidation\HandlesValidation::getMessages), gated only by
     * `method_exists`. A same-named helper on the page — even a private one —
     * is picked up by that check but not callable from the parent trait's
     * scope, so every reply died with "Method ::messages does not exist".
     *
     * @param  string  $reserved
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reservedLivewireHooks')]
    public function test_page_does_not_shadow_reserved_livewire_hooks(string $reserved): void
    {
        $this->assertFalse(
            method_exists(ViewConversation::class, $reserved),
            "ViewConversation::{$reserved}() collides with a Livewire hook of the same name — rename the helper.",
        );
    }
}
