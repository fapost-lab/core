<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Conversation\Services\ConversationInbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * An operator's reply: the text, an optional attachment, and the id of the submission. The id stays the same while the
 * operator retries one draft, so a double click, a retried request or a replayed form sends one message
 * ({@see ConversationInbox::reply()}); a request without one is refused.
 *
 * The attachment takes the media library's own limits ({@see \App\Http\Requests\Media\UploadFileRequest}: size and MIME
 * allowlist from `config/media.php`), under the inbox's own ceiling of {@see self::ATTACHMENT_MAX_KB}.
 */
final class ReplyConversationRequest extends FormRequest
{
    /** The ceiling the Filament composer had: provider limits are tighter, this rejects nonsense early. */
    public const int ATTACHMENT_MAX_KB = 20480;

    public const int TEXT_MAX = 4096;

    /**
     * The attachment size limit in kilobytes: the media library's, capped by the inbox's ceiling.
     */
    public static function attachmentMaxKb(): int
    {
        $library = (int) max(1, (int) config('media.max_size_bytes', 100 * 1024 * 1024) / 1024);

        return min($library, self::ATTACHMENT_MAX_KB);
    }

    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        $types = config('media.allowed_mime_types', []);

        return is_array($types) ? array_values(array_filter($types, 'is_string')) : [];
    }

    /**
     * Needs `reply` on the thread in the route (another assistant's thread is a 404).
     */
    public function authorize(ConversationInbox $inbox, CurrentAssistantInterface $assistant): bool
    {
        return $this->user()?->can('reply', $inbox->find($assistant->get(), (string) $this->route('record'))) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $mimes = self::allowedMimeTypes();

        return [
            'request_id' => ['required', 'string', 'uuid'],
            'text'       => ['nullable', 'required_without:attachment', 'string', 'max:' . self::TEXT_MAX],
            'attachment' => [
                'nullable',
                'file',
                'max:' . self::attachmentMaxKb(),
                ...([] !== $mimes ? ['mimetypes:' . implode(',', $mimes)] : []),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required_without' => (string) __('conversation.reply.required'),
            'attachment.max'        => (string) __('conversation.reply.attachment_too_large'),
        ];
    }

    public function requestId(): string
    {
        return mb_strtolower((string) $this->validated('request_id'));
    }

    public function text(): string
    {
        return mb_trim((string) ($this->validated('text') ?? ''));
    }

    public function attachment(): ?UploadedFile
    {
        $file = $this->file('attachment');

        return $file instanceof UploadedFile ? $file : null;
    }
}
