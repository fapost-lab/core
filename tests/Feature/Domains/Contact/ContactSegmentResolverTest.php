<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Contact;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Models\ContactTag;
use App\Domains\Contact\Services\ContactSegmentResolver;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableConditions(): array
    {
        return [
            'empty group list'    => [['type' => 'group', 'operator' => 'in', 'value' => []]],
            'blank tag'           => [['type' => 'tag', 'operator' => 'has', 'value' => '']],
            'empty language list' => [['type' => 'language', 'operator' => 'in', 'value' => []]],
            'malformed attr key'  => [['type' => 'attribute', 'key' => 'a-b; drop', 'operator' => 'eq', 'value' => 'x']],
            'blank attr value'    => [['type' => 'attribute', 'key' => 'city', 'operator' => 'eq', 'value' => '']],
            'unknown type'        => [['type' => 'nonsense', 'operator' => 'in', 'value' => ['x']]],
        ];
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

    public function test_group_in_matches_only_members(): void
    {
        $group  = $this->group();
        $member = $this->contact('en', []);
        $member->groups()->attach($group);
        $this->contact('en', []); // not a member

        $segment = $this->segment('all', [
            ['type' => 'group', 'operator' => 'in', 'value' => [$group->getKey()]],
        ]);

        $this->assertSame([(string) $member->getKey()], $this->resolver->resolveContactIds($segment));
    }

    public function test_group_not_in_excludes_members(): void
    {
        $group     = $this->group();
        $member    = $this->contact('en', []);
        $nonMember = $this->contact('en', []);
        $member->groups()->attach($group);

        $segment = $this->segment('all', [
            ['type' => 'group', 'operator' => 'not_in', 'value' => [$group->getKey()]],
        ]);

        $this->assertSame([(string) $nonMember->getKey()], $this->resolver->resolveContactIds($segment));
    }

    public function test_group_in_with_multiple_ids_matches_any_membership(): void
    {
        $groupA = $this->group('Group A');
        $groupB = $this->group('Group B');

        $inA = $this->contact('en', []);
        $inA->groups()->attach($groupA);

        $inB = $this->contact('en', []);
        $inB->groups()->attach($groupB);

        $this->contact('en', []); // in neither group

        $segment = $this->segment('all', [
            ['type' => 'group', 'operator' => 'in', 'value' => [$groupA->getKey(), $groupB->getKey()]],
        ]);

        $ids = $this->resolver->resolveContactIds($segment);

        $this->assertEqualsCanonicalizing([(string) $inA->getKey(), (string) $inB->getKey()], $ids);
    }

    public function test_all_match_intersects_group_and_tag(): void
    {
        $group = $this->group();

        $both = $this->contact('en', ['vip']);
        $both->groups()->attach($group);

        $groupOnly = $this->contact('en', []);
        $groupOnly->groups()->attach($group);

        $tagOnly = $this->contact('en', ['vip']); // not a member of the group

        $segment = $this->segment('all', [
            ['type' => 'group', 'operator' => 'in', 'value' => [$group->getKey()]],
            ['type' => 'tag', 'operator' => 'has', 'value' => 'vip'],
        ]);

        $this->assertSame([(string) $both->getKey()], $this->resolver->resolveContactIds($segment));
    }

    public function test_any_match_unions_group_and_tag(): void
    {
        $group = $this->group();

        $groupOnly = $this->contact('en', []);
        $groupOnly->groups()->attach($group);

        $tagOnly = $this->contact('en', ['vip']);

        $this->contact('en', []); // matches neither

        $segment = $this->segment('any', [
            ['type' => 'group', 'operator' => 'in', 'value' => [$group->getKey()]],
            ['type' => 'tag', 'operator' => 'has', 'value' => 'vip'],
        ]);

        $ids = $this->resolver->resolveContactIds($segment);

        $this->assertEqualsCanonicalizing([(string) $groupOnly->getKey(), (string) $tagOnly->getKey()], $ids);
    }

    /**
     * An unusable condition must narrow to nobody, never quietly vanish.
     * These rules pick broadcast audiences: dropping a condition would widen
     * the segment to every contact of the tenant and blast the whole base.
     *
     * @param  array<string, mixed>  $condition
     */
    #[DataProvider('unusableConditions')]
    public function test_unusable_condition_matches_nobody(array $condition): void
    {
        $this->contact('en', []);
        $this->contact('ru', ['vip']);

        $segment = $this->segment('all', [$condition]);

        $this->assertSame(0, $this->resolver->count($segment));
    }

    /**
     * Under `any` an unusable alternative contributes no matches, but must not
     * suppress the alternatives that are usable.
     */
    public function test_unusable_condition_does_not_suppress_other_any_branches(): void
    {
        $this->contact('en', []);
        $vip = $this->contact('ru', ['vip']);

        $segment = $this->segment('any', [
            ['type' => 'group', 'operator' => 'in', 'value' => []],
            ['type' => 'tag', 'operator' => 'has', 'value' => 'vip'],
        ]);

        $this->assertSame([(string) $vip->getKey()], $this->resolver->resolveContactIds($segment));
    }

    /**
     * A segment with no conditions at all is the deliberate "everyone" case and
     * must stay that way — it is not the same as an unusable condition.
     */
    public function test_segment_without_conditions_still_matches_everyone(): void
    {
        $this->contact('en', []);
        $this->contact('ru', ['vip']);

        $this->assertSame(2, $this->resolver->count($this->segment('all', [])));
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

    private function group(string $name = 'Group'): ContactGroup
    {
        return ContactGroup::query()->create([
            'tenant_id' => self::TENANT_ID,
            'name'      => $name,
        ]);
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
