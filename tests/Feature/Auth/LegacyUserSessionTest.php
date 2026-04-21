<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Auth;
use Tests\Feature\FeatureTestCase;

final class LegacyUserSessionTest extends FeatureTestCase
{
    public function test_legacy_numeric_session_user_id_redirects_to_login_instead_of_crashing(): void
    {
        $sessionAuthKey = Auth::guard()->getName();

        $this->withSession([$sessionAuthKey => 1])
            ->get(route('filament.admin.pages.dashboard'))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }
}
