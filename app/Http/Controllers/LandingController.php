<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

final class LandingController extends Controller
{
    public function index(): View
    {
        return view('landing', [
            'hcaptchaSiteKey' => config('services.hcaptcha.site_key'),
        ]);
    }
}
