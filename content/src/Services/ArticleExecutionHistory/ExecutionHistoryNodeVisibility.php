<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleExecutionHistory;

/**
 * Presentation-only semantic classification for Execution History simplified view.
 * Does not mutate workflow definitions or execution semantics.
 */
final class ExecutionHistoryNodeVisibility
{
    public const SEMANTIC_CONTEXT = 'context';

    public const SEMANTIC_ROUTING = 'routing';

    public const SEMANTIC_EXECUTION = 'execution';

    /**
     * @param  array<string, mixed>  $node
     */
    public static function classifyNode(array $node): string
    {
        $type = trim((string) ($node['type'] ?? ''));

        return match ($type) {
            'article', 'user_input' => self::SEMANTIC_CONTEXT,
            'article_filter' => self::SEMANTIC_ROUTING,
            'prompt', 'filter', 'action', 'end' => self::SEMANTIC_EXECUTION,
            default => self::SEMANTIC_EXECUTION,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{semantic: string, collapsible: bool}
     */
    public static function describeNode(array $node, ?array $execution): array
    {
        $semantic = self::classifyNode($node);

        return [
            'semantic' => $semantic,
            'collapsible' => self::isSafeToCollapse($semantic, $execution),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $execution
     */
    public static function isSafeToCollapse(string $semantic, ?array $execution): bool
    {
        if ($semantic === self::SEMANTIC_EXECUTION) {
            return false;
        }

        if ($execution === null) {
            return $semantic === self::SEMANTIC_CONTEXT || $semantic === self::SEMANTIC_ROUTING;
        }

        $status = strtolower(trim((string) ($execution['status'] ?? '')));
        if (in_array($status, ['failed', 'running', 'retrying'], true)) {
            return false;
        }

        if ($status === 'skipped') {
            $skipReason = trim((string) ($execution['skip_reason'] ?? ''));
            if ($skipReason !== '' && $skipReason !== 'not_reachable') {
                return false;
            }
        }

        if ($status === 'failed' || trim((string) ($execution['message'] ?? '')) !== '' && $status === 'failed') {
            return false;
        }

        return $semantic === self::SEMANTIC_CONTEXT || $semantic === self::SEMANTIC_ROUTING;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $executionByNodeId
     * @return array<string, array{semantic: string, collapsible: bool}>
     */
    public static function classifyWorkflowNodes(array $nodes, array $executionByNodeId): array
    {
        $map = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }
            $execution = is_array($executionByNodeId[$nodeId] ?? null) ? $executionByNodeId[$nodeId] : null;
            $map[$nodeId] = self::describeNode($node, $execution);
        }

        return $map;
    }
}
