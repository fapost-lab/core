<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Contacts\Pages;

use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\Contact;
use App\Filament\Assistant\Resources\Contacts\ContactResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * Manual tagging for operators. Reuses the tenant tag vocabulary as
     * suggestions and lets staff create new tags; writes are attributed to the
     * acting staff user via {@code tagged_by}.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageTags')
                ->label(__('contact.tags.manage'))
                ->icon(Heroicon::OutlinedTag)
                ->visible(fn (): bool => Auth::user()?->can('update', $this->getRecord()) ?? false)
                ->fillForm(fn (): array => ['tags' => $this->currentTags()])
                ->schema([
                    TagsInput::make('tags')
                        ->label(__('contact.tags.label'))
                        ->suggestions($this->contactTags()->distinctTags()),
                ])
                ->action(function (array $data): void {
                    /** @var Contact $record */
                    $record = $this->getRecord();

                    $this->contactTags()->syncForContact(
                        (string) $record->getKey(),
                        is_array($data['tags'] ?? null) ? array_values($data['tags']) : [],
                        (string) Auth::id(),
                    );
                }),
        ];
    }

    /**
     * @return list<string>
     */
    private function currentTags(): array
    {
        /** @var Contact $record */
        $record = $this->getRecord();

        return $record->tags()->orderBy('tag')->pluck('tag')->all();
    }

    private function contactTags(): ContactTagRepositoryInterface
    {
        return app(ContactTagRepositoryInterface::class);
    }
}
