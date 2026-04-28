<?php

declare(strict_types=1);

namespace App\Domains\Presale\Models;

use App\Domains\Presale\Enums\MessengerPreference;
use Database\Factories\PreSaleRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $company
 * @property string      $email
 * @property MessengerPreference $messenger_preference
 * @property string|null $message
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string      $locale
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereMessengerPreference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PreSaleRequest whereUserAgent($value)
 * @mixin \Eloquent
 */
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
