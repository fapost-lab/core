<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\RoleWriterService;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleFormSchema;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    public function form(Schema $schema): Schema
    {
        return RoleFormSchema::configure($schema, null);
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = Auth::user();

        return app(RoleWriterService::class)->create($actor, $data);
    }
}
