<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills a tenant with enough content that the admin screens show something.
 *
 * For documentation walkthroughs and manual review, not for tests: it targets a
 * real tenant schema and leaves the records behind. Everything it creates is
 * marked `demo` in its metadata so the set can be found and removed again.
 *
 * The channel row is inserted directly rather than through the model. Saving a
 * Channel registers its webhook with the provider, which cannot succeed for a
 * token that belongs to no real bot — and a channel is the keystone: without one
 * there are no conversations and no messages to show.
 */
final class DemoContentSeeder extends Seeder
{
    private const MARK = ['demo' => true];

    public function run(): void
    {
        $tenant = Tenant::query()->firstOrFail();

        app(TenantSwitcher::class)->runForTenant($tenant, function () use ($tenant): void {
            $tenantId  = (string) $tenant->id;
            $assistant = Assistant::query()->firstOrFail();

            $this->command?->info("Tenant {$tenant->slug} · assistant {$assistant->name}");

            $channelId = $this->channel($tenantId, (string) $assistant->id);
            $contacts  = $this->contacts($tenantId);
            $messages  = $this->conversations($tenantId, (string) $assistant->id, $channelId, $contacts);

            $this->command?->info('  contacts      ' . $contacts->count());
            $this->command?->info('  conversations ' . $contacts->count());
            $this->command?->info('  messages      ' . $messages);
        });
    }

    private function channel(string $tenantId, string $assistantId): string
    {
        $existing = DB::table('channels')->value('id');

        if (null !== $existing) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('channels')->insert([
            'id'                    => $id,
            'assistant_id'          => $assistantId,
            'tenant_id'             => $tenantId,
            'type'                  => 'telegram',
            'token'                 => 'demo:0000000000:AA-not-a-real-token',
            'secret_token'          => Str::random(32),
            'webhook_public_hash'   => Str::random(48),
            'telegram_bot_username' => 'demo_assistant_bot',
            'config'                => json_encode(self::MARK, JSON_THROW_ON_ERROR),
            'is_active'             => true,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return $id;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Contact>
     */
    private function contacts(string $tenantId): \Illuminate\Support\Collection
    {
        if (DB::table('contacts')->exists()) {
            return Contact::query()->limit(6)->get();
        }

        $people = [
            ['Marta',  'Kowalska', 'pl'],
            ['Ivan',   'Petrenko', 'uk'],
            ['Sophie', 'Lambert',  'fr'],
            ['Daniel', 'Okafor',   'en'],
            ['Aiko',   'Tanaka',   'en'],
            ['Tomás',  'Herrera',  'es'],
        ];

        return collect($people)->map(fn (array $p): Contact => Contact::factory()->create([
            'tenant_id'  => $tenantId,
            'language'   => $p[2],
            'attributes' => ['first_name' => $p[0], 'last_name' => $p[1]],
            'meta'       => self::MARK,
        ]));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Contact>  $contacts
     */
    private function conversations(
        string $tenantId,
        string $assistantId,
        string $channelId,
        \Illuminate\Support\Collection $contacts,
    ): int {
        if (DB::table('conversations')->exists()) {
            return 0;
        }

        $scripts = [
            ['Hi! Do you ship to Poland?', 'We do — delivery to Poland takes 3–5 working days.'],
            ['Where is my order 41982?', 'Order 41982 left the warehouse yesterday and is out for delivery.'],
            ['Bonjour, avez-vous un catalogue ?', 'Yes — here is the current catalogue.'],
            ['Can I change my delivery address?', 'Of course. What is the new address?'],
            ['Do you have this in size M?', 'Size M is in stock. Shall I reserve one for you?'],
            ['¿Cuál es el horario de atención?', 'We answer every day between 9:00 and 18:00 CET.'],
        ];

        $messages = 0;

        foreach ($contacts->values() as $i => $contact) {
            [$inbound, $outbound] = $scripts[$i % count($scripts)];

            $conversationId = (string) Str::uuid7();
            $at             = now()->subHours(($contacts->count() - $i) * 3);

            DB::table('conversations')->insert([
                'id'                   => $conversationId,
                'tenant_id'            => $tenantId,
                'assistant_id'         => $assistantId,
                'contact_id'           => $contact->id,
                'channel_id'           => $channelId,
                'platform'             => 'telegram',
                'status'               => 'open',
                'owner_type'           => 'bot',
                'last_message_at'      => $at->copy()->addMinute(),
                'last_inbound_at'      => $at,
                'last_outbound_at'     => $at->copy()->addMinute(),
                'last_message_preview' => Str::limit($outbound, 80),
                'unread_count'         => 0,
                'message_count'        => 2,
                'meta'                 => json_encode(self::MARK, JSON_THROW_ON_ERROR),
                'created_at'           => $at,
                'updated_at'           => $at->copy()->addMinute(),
            ]);

            foreach ([['in', $inbound, $at], ['out', $outbound, $at->copy()->addMinute()]] as [$direction, $text, $when]) {
                DB::table('conversation_messages')->insert([
                    'id'              => (string) Str::uuid7(),
                    'tenant_id'       => $tenantId,
                    'conversation_id' => $conversationId,
                    'contact_id'      => $contact->id,
                    'assistant_id'    => $assistantId,
                    'channel_id'      => $channelId,
                    'direction'       => $direction,
                    'sender_type'     => 'in' === $direction ? 'contact' : 'bot',
                    'content_type'    => 'text',
                    'text'            => $text,
                    'status'          => 'delivered',
                    'origin'          => 'demo',
                    'created_at'      => $when,
                ]);
                $messages++;
            }
        }

        return $messages;
    }
}
