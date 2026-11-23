<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Issues of a Core node handler are shown in the admin UI language, translated by code from `builder.node_issues`;
 * a Solution's issues, and a code without a translation, keep the handler's own message.
 */
final class ValidateFlowServiceIssueTranslationTest extends TestCase
{
    public function test_a_core_issue_is_translated_by_code_and_filled_in_from_its_params(): void
    {
        App::setLocale('ru');

        $issue = $this->issueOf('issue_probe', 'webhook_response_invalid_status');

        $this->assertSame('webhook_response_invalid_status', $issue->code);
        $this->assertSame('Статус ответа должен быть целым числом от 100 до 599.', $issue->message);
        $this->assertSame('nodes.n.config.status', $issue->path);
    }

    public function test_the_translation_for_the_node_type_wins_over_one_for_the_code(): void
    {
        App::setLocale('ru');

        $this->assertSame(
            "Тип адресата 'tags' не поддерживается; используйте 'contact'.",
            $this->issueOf('ask_person', 'addressee_kind_unknown')->message,
        );
    }

    public function test_a_code_without_a_translation_keeps_the_handler_message(): void
    {
        App::setLocale('ru');

        $this->assertSame('Something only this probe says.', $this->issueOf('issue_probe', 'probe_only_code')->message);
    }

    public function test_an_issue_of_a_solution_keeps_its_own_message_even_for_a_code_core_translates(): void
    {
        App::setLocale('ru');

        $this->assertSame('The Solution words it its own way.', $this->issueOf('probe.check', 'webhook_response_invalid_status')->message);
    }

    public function test_the_english_translation_is_the_message_the_handler_wrote(): void
    {
        App::setLocale('en');

        $this->assertSame('The response status must be a whole number from 100 to 599.', $this->issueOf('issue_probe', 'webhook_response_invalid_status')->message);
    }

    public function test_every_locale_has_the_same_issue_keys_and_placeholders(): void
    {
        $shape = function (string $locale): array {
            $lines = trans('builder.node_issues', [], $locale);
            $this->assertIsArray($lines);

            $flat = [];
            array_walk_recursive($lines, static function (string $line, string $key) use (&$flat): void {
                preg_match_all('/:[a-z_]+/', $line, $matches);
                $placeholders = array_unique($matches[0]);
                sort($placeholders);
                $flat[] = $key . ' ' . implode(',', $placeholders);
            });
            sort($flat);

            return $flat;
        };

        $this->assertSame($shape('en'), $shape('ru'));
        $this->assertSame($shape('en'), $shape('uk'));
    }

    private function issueOf(string $type, string $code): FlowValidationErrorDto
    {
        $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
        $registry->register(IssueProbeNode::class);
        $registry->register(AskPersonIssueProbeNode::class);
        $registry->register(SolutionIssueProbeNode::class, 'probe');

        $service = new ValidateFlowService(
            registry: $registry,
            dataAccessors: $this->createStub(DataAccessorRegistryInterface::class),
            triggerValidator: $this->createStub(FlowTriggerConfigValidatorInterface::class),
            tenantEvents: $this->createStub(TenantEventRepositoryInterface::class),
            tenantContext: $this->createStub(TenantContextInterface::class),
        );

        $result  = $service->execute(['n' => ['id' => 'n', 'type' => $type, 'version' => 1, 'config' => ['emit' => $code]]]);
        $matches = array_values(array_filter($result->errors, static fn (FlowValidationErrorDto $error): bool => $code === $error->code));

        $this->assertCount(1, $matches);

        return $matches[0];
    }
}

/**
 * A node that reports the issue its config names, worded as the real handlers word it.
 */
abstract class IssueProbeHandler extends AbstractVersionedHandler implements NodeConfigValidatorInterface
{
    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed('default');
    }

    public function validate(array $node): array
    {
        $code = is_array($node['config'] ?? null) ? ($node['config']['emit'] ?? '') : '';

        return [match ($code) {
            'webhook_response_invalid_status' => NodeConfigIssue::error($code, $this->statusMessage(), 'status', ['min' => 100, 'max' => 599]),
            'addressee_kind_unknown'          => NodeConfigIssue::error($code, "The addressee kind 'tags' is not supported; use 'contact'.", 'addressee.kind', ['kind' => 'tags']),
            default                           => NodeConfigIssue::error((string) $code, 'Something only this probe says.'),
        }];
    }

    protected function statusMessage(): string
    {
        return 'The response status must be a whole number from 100 to 599.';
    }
}

/**
 * A Core node: registered without an owner.
 */
final class IssueProbeNode extends IssueProbeHandler
{
    public const string TYPE = 'issue_probe';
}

final class AskPersonIssueProbeNode extends IssueProbeHandler
{
    public const string TYPE = 'ask_person';
}

final class SolutionIssueProbeNode extends IssueProbeHandler
{
    public const string TYPE = 'probe.check';

    protected function statusMessage(): string
    {
        return 'The Solution words it its own way.';
    }
}
