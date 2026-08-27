<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Contacts\Pages;

use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Assistant\Resources\Contacts\ContactResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * Manual tagging + group assignment for operators. Tags reuse the tenant
     * tag vocabulary as suggestions and let staff create new tags, with writes
     * attributed to the acting staff user via {@code tagged_by}. Group
     * assignment syncs the {@see Contact::groups()} pivot directly — groups
     * have no per-write attribution, unlike tags.
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

            Action::make('manageGroups')
                ->label(__('contact.groups.manage'))
                ->icon(Heroicon::OutlinedUserGroup)
                ->visible(fn (): bool => Auth::user()?->can('update', $this->getRecord()) ?? false)
                ->fillForm(fn (): array => ['groups' => $this->currentGroupIds()])
                ->schema([
                    Select::make('groups')
                        ->label(__('contact.groups.label'))
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => $this->tenantGroupOptions()),
                ])
                ->action(function (array $data): void {
                    /** @var Contact $record */
                    $record = $this->getRecord();

                    $record->groups()->sync(
                        is_array($data['groups'] ?? null) ? array_values($data['groups']) : [],
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

    /**
     * @return list<string>
     */
    private function currentGroupIds(): array
    {
        /** @var Contact $record */
        $record = $this->getRecord();

        return $record->groups()->pluck('contact_groups.id')->all();
    }

    /**
     * @return array<string, string>
     */
    private function tenantGroupOptions(): array
    {
        return ContactGroup::query()
            ->where('tenant_id', app(TenantContextInterface::class)->get()->getId())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function contactTags(): ContactTagRepositoryInterface
    {
        return app(ContactTagRepositoryInterface::class);
    }
}
