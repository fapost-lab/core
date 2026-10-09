<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Contact;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Services\ContactCard;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\State\Variables\VariableType;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\App;
use RuntimeException;
use Tests\TestCase;

/**
 * How a contact's attributes become the card the console shows: sections, sorted keys, and every value as text.
 */
final class ContactCardTest extends TestCase
{
    public function test_it_separates_the_profile_from_the_groups_and_sorts_keys_naturally(): void
    {
        $sections = $this->card([])->sections($this->contact([
            'name'      => 'Ivan',
            'age'       => 30,
            'survey_10' => ['b' => 'x', 'a' => 'y'],
            'survey_2'  => ['q' => 'z'],
        ], ['username' => 'ivan', 'first_name' => 'Ivan']));

        $this->assertSame([['key' => 'age', 'value' => '30'], ['key' => 'name', 'value' => 'Ivan']], $sections['profile']);
        $this->assertSame(['survey_2', 'survey_10'], array_column($sections['groups'], 'key'));
        $this->assertSame([['key' => 'a', 'value' => 'y'], ['key' => 'b', 'value' => 'x']], $sections['groups'][1]['fields']);
        $this->assertSame('2 fields', $sections['groups'][1]['fieldsLabel']);
        $this->assertSame('1 field', $sections['groups'][0]['fieldsLabel']);
        $this->assertSame([['key' => 'first_name', 'value' => 'Ivan'], ['key' => 'username', 'value' => 'ivan']], $sections['meta']);
    }

    public function test_numeric_keys_keep_a_natural_order_in_a_list(): void
    {
        $sections = $this->card([])->sections($this->contact(['g' => ['10' => 'c', '2' => 'b', '1' => 'a']]));

        $this->assertSame(['1', '2', '10'], array_column($sections['groups'][0]['fields'], 'key'));
    }

    public function test_a_list_at_the_root_is_one_profile_field_and_an_empty_array_is_an_empty_one(): void
    {
        $sections = $this->card([])->sections($this->contact([
            'interests' => ['chess', 'go', ['nested' => 1]],
            'none'      => [],
        ]));

        $this->assertSame([], $sections['groups']);
        $this->assertSame([
            ['key' => 'interests', 'value' => 'chess, go, {"nested":1}'],
            ['key' => 'none', 'value' => ''],
        ], $sections['profile']);
    }

    public function test_values_follow_the_declared_type(): void
    {
        $types = [
            'g:n_int'   => VariableType::Number,
            'g:n_float' => VariableType::Number,
            'g:n_text'  => VariableType::Number,
            'g:date'    => VariableType::Date,
            'g:bad'     => VariableType::Date,
            'g:json'    => VariableType::Json,
            'g:yes'     => VariableType::Confirm,
            'g:no'      => VariableType::Confirm,
            'g:maybe'   => VariableType::Confirm,
            'g:flag'    => VariableType::Confirm,
            'g:plain'   => VariableType::Text,
            'g:empty'   => VariableType::Number,
        ];

        $sections = $this->card($types)->sections($this->contact(['g' => [
            'n_int'   => 1234,
            'n_float' => '12.5',
            'n_text'  => 'abc',
            'date'    => '2026-03-05',
            'bad'     => 'not a date',
            'json'    => ['a' => 1],
            'yes'     => 'Y',
            'no'      => 'нет',
            'maybe'   => 'perhaps',
            'flag'    => true,
            'plain'   => 'text',
            'empty'   => '',
        ]]));

        $fields = array_column($sections['groups'][0]['fields'], 'value', 'key');

        $this->assertSame('1,234', $fields['n_int']);
        $this->assertSame('12.50', $fields['n_float']);
        $this->assertSame('abc', $fields['n_text']);
        $this->assertSame('05 Mar 2026', $fields['date']);
        $this->assertSame('not a date', $fields['bad']);
        $this->assertSame("{\n    \"a\": 1\n}", $fields['json']);
        $this->assertSame('Yes', $fields['yes']);
        $this->assertSame('No', $fields['no']);
        $this->assertSame('perhaps', $fields['maybe']);
        $this->assertSame('Yes', $fields['flag']);
        $this->assertSame('text', $fields['plain']);
        $this->assertSame('', $fields['empty']);
    }

    public function test_a_value_without_a_type_is_shown_as_stored(): void
    {
        $sections = $this->card([])->sections($this->contact(['g' => ['flag' => true, 'off' => false, 'list' => [1, 2], 'null' => null]]));

        $this->assertSame(
            ['flag' => 'true', 'list' => '[1,2]', 'null' => '', 'off' => 'false'],
            array_column($sections['groups'][0]['fields'], 'value', 'key'),
        );
    }

    public function test_a_registry_that_cannot_be_resolved_or_fails_means_no_types(): void
    {
        $container = new Container();
        $sections  = (new ContactCard($container))->sections($this->contact(['root' => 1234, 'g' => ['n' => 1234]]));

        $this->assertSame('1234', $sections['groups'][0]['fields'][0]['value']);
        $this->assertSame('1234', $sections['profile'][0]['value']);

        $container->instance(VariableSchemaRegistryInterface::class, $this->registry([], failing: true));
        $sections = (new ContactCard($container))->sections($this->contact(['root' => 1234, 'g' => ['n' => 1234]]));

        $this->assertSame('1234', $sections['groups'][0]['fields'][0]['value']);
        $this->assertSame('1234', $sections['profile'][0]['value']);
    }

    public function test_a_date_is_written_in_the_language_of_the_request(): void
    {
        App::setLocale('ru');

        $sections = $this->card([':when' => VariableType::Date, 'g:when' => VariableType::Date])
            ->sections($this->contact(['when' => '2026-03-05', 'g' => ['when' => '2026-03-05']]));

        $this->assertSame('05 мар 2026', $sections['profile'][0]['value']);
        $this->assertSame('05 мар 2026', $sections['groups'][0]['fields'][0]['value']);
    }

    public function test_the_groups_collapse_past_the_threshold(): void
    {
        $three = $this->card([])->sections($this->contact(['a' => ['x' => 1], 'b' => ['x' => 1], 'c' => ['x' => 1]]));
        $four  = $this->card([])->sections($this->contact(['a' => ['x' => 1], 'b' => ['x' => 1], 'c' => ['x' => 1], 'd' => ['x' => 1]]));

        $this->assertFalse($three['collapsed']);
        $this->assertTrue($four['collapsed']);
    }

    public function test_nothing_stored_gives_an_empty_card(): void
    {
        $this->assertSame(
            ['profile' => [], 'groups' => [], 'meta' => [], 'collapsed' => false],
            $this->card([])->sections($this->contact([])),
        );
    }

    public function test_the_count_of_fields_is_worded_by_the_language_rules(): void
    {
        App::setLocale('ru');

        $label = fn (int $count): string => $this->card([])->sections($this->contact(['g' => array_fill_keys(range(1, $count), 'v')]))['groups'][0]['fieldsLabel'];

        $this->assertSame('1 поле', $label(1));
        $this->assertSame('2 поля', $label(2));
        $this->assertSame('5 полей', $label(5));
        $this->assertSame('21 поле', $label(21));
        $this->assertSame('22 поля', $label(22));
        $this->assertSame('101 поле', $label(101));

        $this->assertSame('Да', trans('contact.values.yes'));
    }

    /**
     * @param  array<string, VariableType>  $types  by "group:key"
     */
    private function card(array $types): ContactCard
    {
        $container = new Container();
        $container->instance(VariableSchemaRegistryInterface::class, $this->registry($types));

        return new ContactCard($container);
    }

    /**
     * @param  array<string, VariableType>  $types
     */
    private function registry(array $types, bool $failing = false): VariableSchemaRegistryInterface
    {
        return new class ($types, $failing) implements VariableSchemaRegistryInterface {
            /**
             * @param  array<string, VariableType>  $types
             */
            public function __construct(private readonly array $types, private readonly bool $failing)
            {
            }

            public function get(string $storage, ?string $group, string $name): ?VariableType
            {
                if ($this->failing) {
                    throw new RuntimeException('Registry is down.');
                }

                return $this->types[($group ?? '') . ':' . $name] ?? null;
            }

            public function getAllForTenant(): array
            {
                return [];
            }

            public function getProperties(string $storage, ?string $group, string $name): array
            {
                return [];
            }

            public function invalidate(): void
            {
            }
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $meta
     */
    private function contact(array $attributes, array $meta = []): Contact
    {
        $contact = new Contact();
        $contact->setRawAttributes([
            'platform'   => PlatformEnum::Telegram->value,
            'meta'       => json_encode($meta),
            'attributes' => json_encode($attributes),
        ], true);

        return $contact;
    }
}
