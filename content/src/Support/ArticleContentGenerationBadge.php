<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptResultOwnershipResolver;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;

/**
 * Editor [FREE] badge — only when the article's actual content generation used FREE + SPLIT.
 *
 * SSOT: PromptResult.input_snapshot fields stamped by GenerationShapeResolver / route_cost_auto:
 * - generation_shape (sectioned = SPLIT, single_pass = SINGLE)
 * - shape_decision_cost_class | primary_is_free (free | paid)
 *
 * Missing / ambiguous legacy metadata → badge hidden (no guess).
 */
final class ArticleContentGenerationBadge
{
    /**
     * @return array{
     *     show_free_badge: bool,
     *     route_cost: string|null,
     *     generation_shape: string|null,
     *     generation_shape_source: string|null
     * }
     */
    public static function empty(): array
    {
        return [
            'show_free_badge' => false,
            'route_cost' => null,
            'generation_shape' => null,
            'generation_shape_source' => null,
        ];
    }

    /**
     * Classify a persisted PromptResult snapshot (no DB).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     show_free_badge: bool,
     *     route_cost: string|null,
     *     generation_shape: string|null,
     *     generation_shape_source: string|null
     * }
     */
    public static function fromSnapshot(array $snapshot): array
    {
        if (! self::isContentBodyGenerationSnapshot($snapshot)) {
            return self::empty();
        }

        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];

        $shape = ArticleGenerationShape::tryFromMixed(
            $snapshot['generation_shape']
            ?? $variables['generation_shape']
            ?? null,
        );

        // pass_mode multiple_pass is a derived mirror of sectioned — only when shape missing.
        if ($shape === null) {
            $passMode = strtolower(trim((string) (
                $snapshot['pass_mode']
                ?? $variables['pass_mode']
                ?? ''
            )));
            if (in_array($passMode, ['multiple_pass', 'sectioned', 'sectioned_free'], true)
                || ! empty($snapshot['sectioned_free_orchestrator'])
            ) {
                $shape = ArticleGenerationShape::Sectioned;
            } elseif (in_array($passMode, ['single_pass', 'single'], true)) {
                $shape = ArticleGenerationShape::SinglePass;
            }
        }

        $costClass = strtolower(trim((string) (
            $snapshot['shape_decision_cost_class']
            ?? $variables['shape_decision_cost_class']
            ?? ''
        )));
        if ($costClass === '') {
            if (array_key_exists('primary_is_free', $snapshot) || array_key_exists('primary_is_free', $variables)) {
                $costClass = ! empty($snapshot['primary_is_free'] ?? $variables['primary_is_free'])
                    ? 'free'
                    : 'paid';
            } elseif (array_key_exists('is_free', $snapshot)) {
                $costClass = ! empty($snapshot['is_free']) ? 'free' : 'paid';
            } elseif (array_key_exists('is_free_candidate', $snapshot)
                || array_key_exists('is_free_candidate', $variables)
            ) {
                $costClass = ! empty($snapshot['is_free_candidate'] ?? $variables['is_free_candidate'])
                    ? 'free'
                    : 'paid';
            }
        }

        if ($costClass !== '' && ! in_array($costClass, ['free', 'paid'], true)) {
            $costClass = '';
        }

        $source = trim((string) (
            $snapshot['generation_shape_source']
            ?? $variables['generation_shape_source']
            ?? ''
        ));

        // Both dimensions must be proven — never infer FREE from shape alone or SPLIT from cost alone.
        if ($shape === null || $costClass === '') {
            return self::empty();
        }

        return [
            'show_free_badge' => $shape->isSectioned() && $costClass === 'free',
            'route_cost' => $costClass,
            'generation_shape' => $shape->value,
            'generation_shape_source' => $source !== '' ? $source : null,
        ];
    }

    /**
     * Resolve from the latest successful content-body PromptResult for an article.
     *
     * @return array{
     *     show_free_badge: bool,
     *     route_cost: string|null,
     *     generation_shape: string|null,
     *     generation_shape_source: string|null
     * }
     */
    public static function forArticleId(int $articleId): array
    {
        if ($articleId <= 0) {
            return self::empty();
        }

        return self::forArticleIds([$articleId])[$articleId] ?? self::empty();
    }

    /**
     * Batch variant of {@see forArticleId()} — fixed query count (no N+1).
     *
     * @param  list<int>  $articleIds
     * @return array<int, array{
     *     show_free_badge: bool,
     *     route_cost: string|null,
     *     generation_shape: string|null,
     *     generation_shape_source: string|null
     * }>
     */
    public static function forArticleIds(array $articleIds): array
    {
        $articleIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $articleIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($articleIds === []) {
            return [];
        }

        $out = [];
        foreach ($articleIds as $articleId) {
            $out[$articleId] = self::empty();
        }

        $linked = SeoPromptResultLink::query()
            ->whereIn('article_id', $articleIds)
            ->orderByDesc('id')
            ->get(['id', 'article_id', 'prompt_result_id']);

        /** @var array<int, list<int>> $linkedIdsByArticle */
        $linkedIdsByArticle = [];
        $allLinkedResultIds = [];
        foreach ($linked as $link) {
            $articleId = (int) ($link->article_id ?? 0);
            $resultId = (int) ($link->prompt_result_id ?? 0);
            if ($articleId <= 0 || $resultId <= 0 || ! isset($out[$articleId])) {
                continue;
            }
            $linkedIdsByArticle[$articleId][] = $resultId;
            $allLinkedResultIds[$resultId] = true;
        }

        $resultsById = [];
        if ($allLinkedResultIds !== []) {
            $resultsById = PromptResult::query()
                ->whereIn('id', array_keys($allLinkedResultIds))
                ->whereIn('status', ['completed', 'success'])
                ->get(['id', 'status', 'input_snapshot', 'created_at'])
                ->keyBy(static fn (PromptResult $row): int => (int) $row->id)
                ->all();
        }

        $missingArticleIds = [];
        foreach ($articleIds as $articleId) {
            $candidates = [];
            foreach ($linkedIdsByArticle[$articleId] ?? [] as $resultId) {
                $row = $resultsById[$resultId] ?? null;
                if ($row instanceof PromptResult) {
                    $candidates[] = $row;
                }
            }
            if ($candidates === []) {
                $missingArticleIds[] = $articleId;
                continue;
            }
            $out[$articleId] = self::classifyCandidates($candidates);
        }

        if ($missingArticleIds !== []) {
            $ownership = app(ArticlePromptResultOwnershipResolver::class);
            foreach ($missingArticleIds as $articleId) {
                $fallback = $ownership->constrainToSnapshotArticle(
                    PromptResult::query()
                        ->whereIn('status', ['completed', 'success'])
                        ->orderByDesc('id')
                        ->limit(40),
                    $articleId,
                )->get(['id', 'status', 'input_snapshot', 'created_at'])->all();
                if ($fallback !== []) {
                    $out[$articleId] = self::classifyCandidates($fallback);
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<PromptResult>  $candidates
     * @return array{
     *     show_free_badge: bool,
     *     route_cost: string|null,
     *     generation_shape: string|null,
     *     generation_shape_source: string|null
     * }
     */
    private static function classifyCandidates(array $candidates): array
    {
        usort($candidates, static function (PromptResult $a, PromptResult $b): int {
            return self::contentPriority(is_array($b->input_snapshot) ? $b->input_snapshot : [])
                <=> self::contentPriority(is_array($a->input_snapshot) ? $a->input_snapshot : [])
                ?: ((int) $b->id <=> (int) $a->id);
        });

        foreach ($candidates as $result) {
            if (! $result instanceof PromptResult) {
                continue;
            }
            $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
            $classified = self::fromSnapshot($snapshot);
            if ($classified['generation_shape'] !== null && $classified['route_cost'] !== null) {
                return $classified;
            }
        }

        return self::empty();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function isContentBodyGenerationSnapshot(array $snapshot): bool
    {
        if (! empty($snapshot['sectioned_free_orchestrator'])) {
            return true;
        }

        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];
        $hook = strtolower(trim((string) (
            $snapshot['hook_key']
            ?? $variables['hook_key']
            ?? ''
        )));

        if ($hook !== '') {
            if (str_contains($hook, 'outline') || str_contains($hook, 'vocabulary')) {
                return false;
            }
            if (str_contains($hook, 'content.generate')
                || str_contains($hook, 'content.rewrite')
                || str_contains($hook, 'section.generate')
            ) {
                return true;
            }
        }

        // Shape stamped on a writing run without a clear non-content hook.
        $shape = ArticleGenerationShape::tryFromMixed(
            $snapshot['generation_shape'] ?? $variables['generation_shape'] ?? null,
        );

        return $shape !== null && $hook === '';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function contentPriority(array $snapshot): int
    {
        if (! empty($snapshot['sectioned_free_orchestrator'])) {
            return 30;
        }
        $hook = strtolower(trim((string) (
            $snapshot['hook_key']
            ?? (is_array($snapshot['variables'] ?? null) ? ($snapshot['variables']['hook_key'] ?? '') : '')
            ?? ''
        )));
        if (str_contains($hook, 'content.generate')) {
            return 20;
        }
        if (str_contains($hook, 'content.rewrite')) {
            return 15;
        }
        if (str_contains($hook, 'section.generate')) {
            return 10;
        }

        return 0;
    }
}
