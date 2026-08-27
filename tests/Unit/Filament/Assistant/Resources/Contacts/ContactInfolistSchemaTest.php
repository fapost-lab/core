<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Assistant\Resources\Contacts;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use App\Filament\Assistant\Resources\Contacts\Schemas\ContactInfolistSchema;
use ReflectionClass;
use Tests\TestCase;

final class ContactInfolistSchemaTest extends TestCase
{
    public function test_root_attribute_keys_excludes_nested_groups(): void
    {
        $contact = $this->makeContact([
            'name'      => 'Иван',
            'phone'     => '+79991234567',
            'survey_q1' => ['score' => 8, 'comment' => 'good'],
        ]);

        $this->assertSame(
            ['name', 'phone'],
            $this->invoke('rootAttributeKeys', $contact),
        );
    }

    public function test_group_keys_returns_only_nested_object_attributes(): void
    {
        $contact = $this->makeContact([
            'name'      => 'X',
            'survey_q1' => ['score' => 8],
            'address'   => ['city' => 'Moscow'],
        ]);

        $this->assertSame(['survey_q1', 'address'], $this->invoke('groupKeys', $contact));
    }

    public function test_should_collapse_groups_when_more_than_three(): void
    {
        $contact = $this->makeContact([
            'a' => ['x' => 1],
            'b' => ['x' => 1],
            'c' => ['x' => 1],
            'd' => ['x' => 1],
        ]);

        $this->assertTrue($this->invoke('shouldCollapseGroups', $contact));
    }

    public function test_should_not_collapse_groups_when_three_or_fewer(): void
    {
        $contact = $this->makeContact([
            'a' => ['x' => 1],
            'b' => ['x' => 1],
            'c' => ['x' => 1],
        ]);

        $this->assertFalse($this->invoke('shouldCollapseGroups', $contact));
    }

    public function test_empty_attributes_yields_no_root_or_group_entries(): void
    {
        $contact = $this->makeContact([]);

        $this->assertSame([], $this->invoke('rootAttributeKeys', $contact));
        $this->assertSame([], $this->invoke('groupKeys', $contact));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeContact(array $attributes, array $meta = []): Contact
    {
        $contact = new Contact();
        $contact->setRawAttributes([
            'platform'   => PlatformEnum::Telegram->value,
            'meta'       => json_encode($meta),
            'attributes' => json_encode($attributes),
        ], true);

        return $contact;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionClass(ContactInfolistSchema::class);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs(null, $args);
    }
}
