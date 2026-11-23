<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

use App\Domains\Staff\Contracts\LocalizedDatabaseNotification;
use App\Domains\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells one administrator that a tenant API token expires soon (`adr-20261119-tenant-api-tokens` §9), so it can be
 * rotated before integrations stop working. Never names the token's secret, only its name and last four characters.
 *
 * Sent with `notifyNow` from {@see \App\Domains\Staff\Services\ApiTokenExpiryNotifier} inside the tenant's switch, never
 * queued. The bell renders it again in the language of the page from the stored name and date.
 */
final class ApiTokenExpiringNotification extends Notification implements LocalizedDatabaseNotification
{
    public function __construct(
        private readonly string $tokenName,
        private readonly string $lastFour,
        private readonly CarbonImmutable $expiresAt,
        private readonly string $url,
    ) {
    }

    public static function localize(array $data, string $locale): array
    {
        $name      = $data['token_name'] ?? null;
        $lastFour  = $data['last_four'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;

        if (! is_string($name) || ! is_string($lastFour) || ! is_string($expiresAt)) {
            return [];
        }

        return self::text($name, $lastFour, CarbonImmutable::parse($expiresAt), $locale);
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        $text = self::text($this->tokenName, $this->lastFour, $this->expiresAt, $notifiable->preferredLocale());

        return [
            'title'      => $text['title'],
            'body'       => $text['body'],
            'status'     => 'warning',
            'action'     => ['label' => $text['actionLabel'], 'url' => $this->url],
            'kind'       => 'api_token_expiring',
            'token_name' => $this->tokenName,
            'last_four'  => $this->lastFour,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $text = self::text($this->tokenName, $this->lastFour, $this->expiresAt, $notifiable->preferredLocale());

        return (new MailMessage())
            ->subject($text['title'])
            ->line($text['body'])
            ->action($text['actionLabel'], $this->url);
    }

    /**
     * @return array{title: string, body: string, actionLabel: string}
     */
    private static function text(string $name, string $lastFour, CarbonImmutable $expiresAt, string $locale): array
    {
        return [
            'title' => (string) trans('staff.api_tokens.expiring.title', ['name' => $name], $locale),
            'body'  => (string) trans('staff.api_tokens.expiring.body', [
                'name'      => $name,
                'last_four' => $lastFour,
                'date'      => $expiresAt->utc()->locale($locale)->isoFormat('LL'),
            ], $locale),
            'actionLabel' => (string) trans('staff.api_tokens.expiring.action', [], $locale),
        ];
    }
}
