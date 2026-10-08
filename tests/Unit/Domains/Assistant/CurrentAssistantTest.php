<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Assistant;

use App\Domains\Assistant\Exceptions\CurrentAssistantNotResolvedException;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\CurrentAssistant;
use Filament\Facades\Filament;
use Tests\TestCase;

final class CurrentAssistantTest extends TestCase
{
    public function test_get_throws_when_nothing_was_set_even_if_filament_has_a_tenant(): void
    {
        Filament::setCurrentPanel('assistant');
        Filament::setTenant(new Assistant(), isQuiet: true);

        $currentAssistant = new CurrentAssistant();

        $this->assertFalse($currentAssistant->isResolved());
        $this->expectException(CurrentAssistantNotResolvedException::class);

        $currentAssistant->get();
    }

    public function test_get_returns_the_assistant_that_was_set(): void
    {
        $assistant        = new Assistant();
        $currentAssistant = new CurrentAssistant();

        $currentAssistant->set($assistant);

        $this->assertTrue($currentAssistant->isResolved());
        $this->assertSame($assistant, $currentAssistant->get());
    }

    public function test_reset_forgets_the_assistant(): void
    {
        $currentAssistant = new CurrentAssistant();
        $currentAssistant->set(new Assistant());

        $currentAssistant->reset();

        $this->assertFalse($currentAssistant->isResolved());
    }
}
