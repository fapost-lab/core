<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tma;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Temporary HTTP-layer stub for Telegram Mini App forms.
 *
 * This lives in App\Http\Controllers only for Task 17.8 scaffolding and should
 * move into a dedicated domain controller when real form runtime logic is implemented.
 */
final class TmaFormController extends Controller
{
    public function show(string $formId): JsonResponse
    {
        return response()->json([
            'form_id' => $formId,
            'title'   => 'Employee survey',
            'fields'  => [
                [
                    'id'          => 'f1',
                    'type'        => 'text',
                    'label'       => 'Full name',
                    'required'    => true,
                    'placeholder' => 'Enter your name',
                ],
                [
                    'id'       => 'f2',
                    'type'     => 'select',
                    'label'    => 'Department',
                    'required' => true,
                    'options'  => ['HR', 'Engineering', 'Sales'],
                ],
                [
                    'id'       => 'f3',
                    'type'     => 'checkbox',
                    'label'    => 'I agree to the terms',
                    'required' => true,
                ],
            ],
        ]);
    }

    public function submit(Request $request, string $formId): JsonResponse
    {
        return response()->json([
            'form_id'  => $formId,
            'status'   => 'accepted',
            'received' => [
                'answers' => $request->input('answers', []),
            ],
        ]);
    }
}
