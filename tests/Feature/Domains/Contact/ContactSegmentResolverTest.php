<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Contact;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Models\ContactTag;
use App\Domains\Contact\Services\ContactSegmentResolver;
use Tests\Feature\FeatureTestCase;

final class ContactSegmentResolverTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private ContactSegmentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(ContactSegmentResolver::class);
    }

    public function test_all_match_intersects_tag_and_language(): void
    {
        $match = $this->contact('en', ['vip']);
        $this->contact('ru', ['vip']);   // wrong language
        $this->contact('en', []);        // missing tag

        $segment = $this->segment('all', [
            ['type' => 'tag', 'operator' => 'has', 'value' => 'vip'],
            ['type' => 'language', 'operator' => 'in', 'value' => ['en']],
        ]);

        $ids = $this->resolver->resolveContactIds($segment);

        $this->assertSame([(string) $match->getKey()], $ids);
    }

    public function test_any_match_unions_conditions(): void
    {
        $vip = $this->contact('ru', ['vip']);
        $en  = $this->contact('en', []);
        $this->contact('de', ['other']); // matches neither

        $segment = $this->segment('any', [
            ['type' => 'tag', 'operator' => 'has', 'value' => 'vip'],
            ['type' => 'language', 'operator' => 'eq', 'value' => 'en'],
        ]);

        $ids = $this->resolver->resolveContactIds($segment);

        $this->assertEqualsCanonicalizing([(string) $vip->getKey(), (string) $en->getKey()], $ids);
    }

    public function test_not_has_tag_excludes_tagged_contacts(): void
    {
        $clean = $this->contact('en', []);
        $this->contact('en', ['unsubscribed']);

        $segment = $this->segment('all', [
            ['type' => 'tag', 'operator' => 'not_has', 'value' => 'unsubscribed'],
        ]);

        $this->assertSame([(string) $clean->getKey()], $this->resolver->resolveContactIds($segment));
    }

    public function test_attribute_equality_condition(): void
    {
        $gold = $this->contactWithAttributes(['tier' => 'gold']);
        $this->contactWithAttributes(['tier' => 'silver']);

        $segment = $this->segment('all', [
            ['type' => 'attribute', 'key' => 'tier', 'operator' => 'eq', 'value' => 'gold'],
        ]);

        $this->assertSame([(string) $gold->getKey()], $this->resolver->resolveContactIds($segment));
    }

    public function test_attribute_exists_condition_on_nested_path(): void
    {
        $withCity = $this->contactWithAttributes(['profile' => ['city' => 'Kyiv']]);
        $this->contactWithAttributes(['profile' => ['name' => 'A']]); // no city

        $segment = $this->segment('all', [
            ['type' => 'attribute', 'key' => 'profile.city', 'operator' => 'exists'],
        ]);

        $this->assertSame([(string) $withCity->getKey()], $this->resolver->resolveContactIds($segment));
    }

    public function test_empty_rules_match_all_tenant_contacts(): void
    {
        $this->contact('en', []);
        $this->contact('ru', ['vip']);

        $segment = $this->segment('all', []);

        $this->assertSame(2, $this->resolver->count($segment));
    }

    public function test_refresh_count_persists_snapshot(): void
    {
        $this->contact('en', ['vip']);
        $segment = $this->segment('all', [['type' => 'tag', 'operator' => 'has', 'value' => 'vip']]);

        $count = $this->resolver->refreshCount($segment);

        $this->assertSame(1, $count);
        $segment->refresh();
        $this->assertSame(1, $segment->cached_count);
        $this->assertNotNull($segment->cached_count_at);
    }

    /**
     * @param  list<string>  $tags
     */
    private function contact(string $language, array $tags): Contact
    {
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create([
            'language' => $language,
            'platform' => PlatformEnum::Telegram,
        ]);

        foreach ($tags as $tag) {
            ContactTag::query()->create([
                'contact_id' => $contact->getKey(),
                'tag'        => $tag,
                'tagged_by'  => null,
                'tagged_at'  => now(),
            ]);
        }

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contactWithAttributes(array $attributes): Contact
    {
        return Contact::factory()->forTenant(self::TENANT_ID)->create(['attributes' => $attributes]);
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    private function segment(string $match, array $conditions): ContactSegment
    {
        return ContactSegment::query()->create([
            'tenant_id' => self::TENANT_ID,
            'name'      => 'Segment',
            'rules'     => ['match' => $match, 'conditions' => $conditions],
        ]);
    }
}
