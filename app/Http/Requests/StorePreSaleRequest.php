<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domains\Presale\Enums\MessengerPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StorePreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:255'],
            'company'              => ['required', 'string', 'max:255'],
            'email'                => ['required', 'email:rfc', 'max:255'],
            'messenger_preference' => ['required', Rule::enum(MessengerPreference::class)],
            'message'              => ['nullable', 'string', 'max:5000'],
            'h-captcha-response'   => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'h-captcha-response.required' => 'Please complete the captcha verification.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->verifyCaptcha($validator);
            },
        ];
    }

    private function verifyCaptcha(Validator $validator): void
    {
        $response = Http::asForm()->post(
            config('services.hcaptcha.verify_url'),
            [
                'secret'   => config('services.hcaptcha.secret_key'),
                'response' => $this->input('h-captcha-response'),
                'remoteip' => $this->ip(),
            ]
        );

        if ( ! $response->successful() || ! $response->json('success')) {
            $validator->errors()->add('h-captcha-response', 'Captcha verification failed. Please try again.');
        }
    }
}
