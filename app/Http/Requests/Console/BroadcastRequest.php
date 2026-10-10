<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastService;
use App\Domains\Broadcasting\Support\BroadcastMessage;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The fields of a broadcast, for creating one (no `record` in the route) and for changing one. Sending is not among
 * them: a draft is started only from its own confirmation, with the revision that was shown.
 */
final class BroadcastRequest extends FormRequest
{
    /** The longest message text per language; Telegram refuses more and the recipient would fail. */
    public const int MAX_MESSAGE_LENGTH = 4096;

    /**
     * @return array<string, string>
     */
    public static function attributeNames(): array
    {
        return [
            'name'              => trans('console.broadcasts.fields.name'),
            'message'           => trans('console.broadcasts.fields.message'),
            'target_type'       => trans('console.broadcasts.fields.target'),
            'target_tags'       => trans('console.broadcasts.fields.tags'),
            'target_segment_id' => trans('console.broadcasts.fields.segment'),
        ];
    }

    /**
     * Changing needs `update` on the broadcast in the route (a broadcast of another assistant is a 404), creating needs `create`.
     */
    public function authorize(BroadcastService $broadcasts): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        if (null === $this->route('record')) {
            return $user->can('create', Broadcast::class);
        }

        return $user->can('update', $broadcasts->findForAssistant((string) $this->route('record')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(BroadcastService $broadcasts, TenantContextInterface $tenants): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            'message'   => ['required', 'array'],
            'message.*' => ['nullable', 'string', 'max:' . self::MAX_MESSAGE_LENGTH],
            ...BroadcastAudienceRules::rules($broadcasts, $tenants),
        ];
    }

    /**
     * Checks that need the whole message: only languages the form has a tab for, and text in the base language.
     * The base language is required because a recipient whose resolved text is blank is skipped, so a message without
     * it would burn the whole run.
     *
     * @return list<callable(Validator): void>
     */
    public function after(BroadcastService $broadcasts): array
    {
        return [
            function (Validator $validator) use ($broadcasts): void {
                $message = $this->input('message');

                if (! is_array($message)) {
                    return;
                }

                $existing = null !== $this->route('record')
                    ? $broadcasts->findForAssistant((string) $this->route('record'))->message
                    : null;

                $allowed = $broadcasts->languages(is_array($existing) ? $existing : null);

                // A blank entry for a language the form has no tab for is dropped when saving, so it is no error.
                foreach (BroadcastMessage::clean($message) ?? [] as $language => $text) {
                    if (! in_array((string) $language, $allowed, true)) {
                        $validator->errors()->add('message.' . $language, trans('console.broadcasts.errors.unknown_language'));
                    }
                }

                $base = $broadcasts->baseLanguage();

                if (! BroadcastMessage::hasBaseLanguageText(BroadcastMessage::clean($message), $base) && ! $validator->errors()->has('message.' . $base)) {
                    $validator->errors()->add('message.' . $base, trans('console.broadcasts.errors.base_language', ['language' => mb_strtoupper($base)]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::attributeNames();
    }

    /**
     * @return array{name: string, message: array<string, mixed>, target_type: string, target_tags?: list<string>|null, target_segment_id?: string|null}
     */
    public function fields(): array
    {
        /** @var array{name: string, message: array<string, mixed>, target_type: string, target_tags?: list<string>|null, target_segment_id?: string|null} $data */
        $data = $this->validated();

        return $data;
    }
}
