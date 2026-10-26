<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Staff;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Role;
use Tests\TestCase;

final class RoleTitleTest extends TestCase
{
    public function test_every_system_role_has_a_translated_label(): void
    {
        foreach (RoleEnum::cases() as $role) {
            $this->assertStringNotContainsString('staff.roles.system.', $role->label());
        }

        $this->assertSame('Content manager', RoleEnum::ContentManager->label());
    }

    public function test_a_system_role_without_a_display_name_is_titled_by_its_label(): void
    {
        $this->assertSame('Content manager', (new Role(['name' => 'content_manager']))->title);
    }

    public function test_a_display_name_wins_and_a_custom_role_falls_back_to_its_name(): void
    {
        $this->assertSame('Boss', (new Role(['name' => 'admin', 'display_name' => 'Boss']))->title);
        $this->assertSame('night_shift', (new Role(['name' => 'night_shift']))->title);
    }
}
