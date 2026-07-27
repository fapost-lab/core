<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts\Pages;

use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Assistant\Resources\Broadcasts\BroadcastResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class CreateBroadcast extends CreateRecord
{
    protected static string $resource = BroadcastResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['tenant_id']    = app(TenantContextInterface::class)->get()->getId();
        $data['assistant_id'] = (string) Filament::getTenant()->getKey();
        $data['created_by']   = null !== Auth::id() ? (string) Auth::id() : null;
        $data['status']       = BroadcastStatus::Draft->value;

        return Broadcast::create($data);
    }
}
