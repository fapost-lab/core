<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Rag\RagAdapterRegistry;
use App\Domains\Flow\State\FlowStateNamespace;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\DTO\RagQueryContext;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\JsonField;
use FAPost\Support\Builder\Schema\Fields\TextareaField;
use FAPost\Support\Builder\Schema\Fields\TextField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;
use LogicException;
use Throwable;

/**
 * Queries a RAG provider via {@see RagAdapterRegistry} and writes the
 * deterministic {@see \FAPost\Foundation\DTO\StructuredRagResult} into
 * {@code state.rag.*}. The condition node downstream branches on
 * {@code rag.found}/{@code rag.confidence}; send_message uses {@code rag.answer}.
 *
 * The provider is selected by literal `provider` config (registry key). The
 * full knowledge_base lookup → provider resolution path will land once KB
 * tables ship; at that point this handler will accept `knowledge_base_id`
 * only and resolve provider from the row.
 */
final class RagQueryNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'rag_query';

    public const string HANDLE_SUCCESS   = 'success';
    public const string HANDLE_NOT_FOUND = 'not_found';
    public const string HANDLE_ERROR     = 'error';

    public function __construct(
        private readonly RagAdapterRegistry $adapters,
        private readonly TemplateRenderer $templates,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'AI';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->required(['knowledge_base_id', 'query', 'provider'])
            ->section(
                Section::make('knowledge_base', 'Knowledge base')
                    ->icon('book-open')
                    ->fields([
                        TextField::make('knowledge_base_id')
                            ->label('Knowledge base')
                            ->required(),
                        TextField::make('provider')
                            ->label('Provider')
                            ->required()
                            ->help('Identifier of a registered RAG adapter (e.g. openai_assistants, pgvector).'),
                    ]),
            )
            ->section(
                Section::make('query', 'Query')
                    ->icon('magnifying-glass')
                    ->fields([
                        TextareaField::make('query')
                            ->label('Query')
                            ->required()
                            ->placeholder('{{flow.last_user_message}}'),
                    ]),
            )
            ->section(
                Section::make('options', 'Options')
                    ->icon('adjustments-horizontal')
                    ->collapsed()
                    ->fields([
                        JsonField::make('options')
                            ->label('Options')
                            ->default([]),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config          = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $knowledgeBaseId = is_string($config['knowledge_base_id'] ?? null) ? mb_trim($config['knowledge_base_id']) : '';
        $provider        = is_string($config['provider'] ?? null) ? mb_trim($config['provider']) : '';
        $rawQuery        = $config['query'] ?? null;
        $options         = is_array($config['options'] ?? null) ? $config['options'] : [];

        if ('' === $knowledgeBaseId) {
            throw new InvalidNodeConfigException('rag_query: missing knowledge_base_id');
        }

        if ('' === $provider) {
            throw new InvalidNodeConfigException('rag_query: missing provider');
        }

        $resolvedQuery = $this->templates->render($rawQuery, $context, $state);

        if (! is_string($resolvedQuery) || '' === $resolvedQuery) {
            throw new InvalidNodeConfigException('rag_query: query resolved to empty string');
        }

        try {
            $adapter = $this->adapters->get($provider);
        } catch (LogicException $exception) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::HANDLE_ERROR,
                metadata: ['error' => $exception->getMessage(), 'error_type' => 'unknown_provider'],
            );
        }

        $queryContext = new RagQueryContext(
            knowledgeBaseId: $knowledgeBaseId,
            tenantId: $context->tenantId,
            language: $context->resolvedLanguage,
            providerOptions: $options,
        );

        try {
            $result = $adapter->query($resolvedQuery, $queryContext);
        } catch (Throwable $exception) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::HANDLE_ERROR,
                metadata: ['error' => $exception->getMessage(), 'error_type' => 'adapter_failure'],
            );
        }

        $stateChanges = [];
        foreach ($result->toStateArray() as $key => $value) {
            $stateChanges[FlowStateNamespace::RAG . ".{$key}"] = $value;
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $result->found ? self::HANDLE_SUCCESS : self::HANDLE_NOT_FOUND,
            stateChanges: $stateChanges,
            metadata: [
                'provider'          => $provider,
                'knowledge_base_id' => $knowledgeBaseId,
                'found'             => $result->found,
                'confidence'        => $result->confidence->value,
            ],
        );
    }
}
