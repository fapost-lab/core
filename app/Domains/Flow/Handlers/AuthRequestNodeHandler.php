<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Enums\AuthMethod;
use App\Domains\Flow\Enums\BranchOperator;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\Handlers\Support\OperatorComparator;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\StatePickerField;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;

/**
 * `auth_request` node — marks the contact authenticated inside a flow.
 *
 * The node is a flag setter, not a branch: the implemented `basic` challenge
 * compares a variable against an expected value using the shared operator set
 * ({@see BranchOperator}) and, on a pass, raises the canonical
 * {@code contact.is_authenticated} flag. It always continues through the single
 * `default` output — auth is enforced elsewhere (the flow-start access gate and
 * `branch` rules reading the flag). Writing the flag to {@code true} is
 * idempotent, so the handler is safe to retry. Richer challenges (phone/SMS)
 * plug in as new {@see AuthMethod} cases.
 */
final class AuthRequestNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'auth_request';

    public function __construct(
        private readonly OperandResolver $operands,
        private readonly OperatorComparator $comparator,
        private readonly TemplateRenderer $templates,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Contact';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->required(['method'])
            ->section(
                Section::make('challenge', (string) __('builder.nodes.auth_request.section'))
                    ->icon('shield-check')
                    ->fields([
                        SelectField::make('method')
                            ->label((string) __('builder.nodes.auth_request.method'))
                            ->required()
                            ->default(AuthMethod::Basic->value)
                            ->options(AuthMethod::options()),
                        StatePickerField::make('variable')
                            ->label((string) __('builder.nodes.auth_request.variable'))
                            ->placeholder('flow.code')
                            ->visibleWhen(['method' => AuthMethod::Basic->value]),
                        SelectField::make('operator')
                            ->label((string) __('builder.nodes.auth_request.operator'))
                            ->default(BranchOperator::Eq->value)
                            ->options(BranchOperator::options())
                            ->visibleWhen(['method' => AuthMethod::Basic->value]),
                        TextField::make('value')
                            ->label((string) __('builder.nodes.auth_request.value'))
                            ->placeholder('1234')
                            ->visibleWhen(['method' => AuthMethod::Basic->value]),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];

        $method = AuthMethod::tryFrom(is_string($config['method'] ?? null) ? $config['method'] : '')
            ?? AuthMethod::Basic;

        return match ($method) {
            AuthMethod::Basic => $this->executeBasic($config, $state, $context),
        };
    }

    /**
     * Basic challenge: resolve the operand, compare against the (templated)
     * expected value, and raise the auth flag on success.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeBasic(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $variablePath = is_string($config['variable'] ?? null) && '' !== $config['variable']
            ? $config['variable']
            : null;

        // Pass the whole config as the operand so a structured `left` (operand
        // picker) wins when present; fall back to the plain `variable` path.
        [$path, $value] = $this->operands->resolve($config, $variablePath, $state, $context);

        $operator = is_string($config['operator'] ?? null) ? $config['operator'] : BranchOperator::Eq->value;
        $expected = $this->templates->render($config['value'] ?? null, $context, $state);

        $passed = $this->comparator->matches($value, $operator, $expected);

        // The node is a flag setter, not a branch: it raises the auth flag when
        // the check passes and always continues through the single `default`
        // output. Auth is enforced elsewhere — the flow-start access gate and
        // `branch` rules reading `contact.is_authenticated`.
        if ($passed) {
            $this->raiseAuthFlag($context);
        }

        return NodeExecutionResult::executed(
            logResolved: null !== $path ? [$path => $value] : [],
            metadata: ['method' => AuthMethod::Basic->value, 'authenticated' => $passed],
        );
    }

    /**
     * Set the canonical {@code contact.is_authenticated} flag through the
     * immediate-write contact writer. Idempotent — writing true twice is a no-op.
     */
    private function raiseAuthFlag(NodeExecutionContext $context): void
    {
        $writer = $context->contactWriter;

        if (null === $writer) {
            throw new InvalidNodeConfigException(
                'auth_request: ContactWriter is unavailable in this NodeExecutionContext.',
            );
        }

        $writer->write('contact.is_authenticated', true);
    }
}
