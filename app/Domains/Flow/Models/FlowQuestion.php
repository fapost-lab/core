<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Flow\Enums\FlowQuestionStatus;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;

/**
 * A question an `ask_person` node put to a contact (the addressee), with the buttons that may answer it.
 *
 * Kept outside the run's session so a press is recognised whatever happened to the run since. `token` is 128 random
 * bits, never derived from the id; a button's payload is the token and the button's index in `buttons`
 * ({@see \App\Domains\Flow\Support\FlowQuestionButton}). `late_answer_text` is frozen when the question is asked,
 * already in the run's language.
 *
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string                          $token
 * @property string                          $session_id
 * @property string                          $node_id
 * @property string                          $execution_key
 * @property string                          $contact_id
 * @property string|null                     $assistant_id
 * @property list<array{id: string, value: string}> $buttons
 * @property FlowQuestionStatus              $status
 * @property string|null                     $answer_button_id
 * @property \Illuminate\Support\Carbon|null $answered_at
 * @property \Illuminate\Support\Carbon      $expires_at
 * @property string|null                     $external_message_id
 * @property string|null                     $late_answer_text
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class FlowQuestion extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'token',
        'session_id',
        'node_id',
        'execution_key',
        'contact_id',
        'assistant_id',
        'buttons',
        'status',
        'answer_button_id',
        'answered_at',
        'expires_at',
        'external_message_id',
        'late_answer_text',
    ];

    /**
     * The value of the button that answered it, or null when none did.
     */
    public function answerValue(): ?string
    {
        foreach ($this->buttons as $button) {
            if (($button['id'] ?? null) === $this->answer_button_id) {
                return (string) ($button['value'] ?? '');
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'buttons'     => 'array',
            'status'      => FlowQuestionStatus::class,
            'answered_at' => 'datetime',
            'expires_at'  => 'datetime',
        ];
    }
}
