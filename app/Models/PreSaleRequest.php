<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessengerPreference;
use Database\Factories\PreSaleRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PreSaleRequest extends Model
{
    /** @use HasFactory<PreSaleRequestFactory> */
    use HasFactory;

    protected $table = 'presale_requests';

    protected $fillable = [
        'name',
        'company',
        'email',
        'messenger_preference',
        'message',
        'ip_address',
        'user_agent',
        'locale',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'messenger_preference' => MessengerPreference::class,
        ];
    }
}
