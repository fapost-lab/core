<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Pages;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Resources\Assistants\AssistantResource;
use App\Filament\Support\ChecksRecordLimitOnMount;
use App\Filament\Support\RecordLimit;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateAssistant extends CreateRecord
{
    use ChecksRecordLimitOnMount;

    protected static string $resource = AssistantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $tenant = app(TenantContextInterface::class)->get();

        try {
            return app(AssistantServiceInterface::class)->create($tenant, $data);
        } catch (RecordLimitReachedException $e) {
            RecordLimit::notifyReached($e, 'staff.assistants.limit');

            throw new Halt();
        }
    }

    protected function afterCreate(): void
    {
        $user = auth()->user();

        if ($user instanceof User && ! $user->isAdmin()) {
            $user->assistants()->syncWithoutDetaching([(string) $this->record->getKey()]);
        }
    }
}
