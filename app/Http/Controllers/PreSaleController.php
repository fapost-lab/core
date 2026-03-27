<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Presale\Models\PreSaleRequest;
use App\Http\Requests\StorePreSaleRequest;
use App\Mail\NewPresaleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

final class PreSaleController extends Controller
{
    public function store(StorePreSaleRequest $request): JsonResponse
    {
        $presale = PreSaleRequest::create([
            ...$request->safe()->except(['h-captcha-response']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'locale'     => app()->getLocale(),
        ]);

        Mail::to(config('mail.from.address'))
            ->queue(new NewPresaleRequest($presale));

        return response()->json(['success' => true]);
    }
}
