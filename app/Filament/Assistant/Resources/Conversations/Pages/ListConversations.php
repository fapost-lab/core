<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Conversations\Pages;

use App\Filament\Assistant\Resources\Conversations\ConversationResource;
use Filament\Resources\Pages\ListRecords;

final class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;
}
