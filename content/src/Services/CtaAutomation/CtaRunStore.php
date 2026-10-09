<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use Illuminate\Support\Str;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleCtaRun;

/**
 * Durable article-scoped CTA runs. Cache is not the source of truth.
 */
final class CtaRunStore
{
    public function findIdempotent(SeoArticle $article, ?string $key): ?SeoArticleCtaRun
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        return SeoArticleCtaRun::query()
            ->where('article_id', (int) $article->getKey())
            ->where('idempotency_key', $key)
            ->first();
    }

    public function findForArticle(SeoArticle $article, string $id): ?SeoArticleCtaRun
    {
        return SeoArticleCtaRun::query()
            ->where('article_id', (int) $article->getKey())
            ->whereKey($id)
            ->first();
    }

    public function latest(SeoArticle $article): ?SeoArticleCtaRun
    {
        return SeoArticleCtaRun::query()
            ->where('article_id', (int) $article->getKey())
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return list<SeoArticleCtaRun>
     */
    public function recent(SeoArticle $article, int $limit = 8): array
    {
        return SeoArticleCtaRun::query()
            ->where('article_id', (int) $article->getKey())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(SeoArticle $article, array $attributes): SeoArticleCtaRun
    {
        $run = new SeoArticleCtaRun();
        $run->id = (string) Str::uuid();
        $run->article_id = (int) $article->getKey();
        $run->site_id = (int) ($article->site_id ?? 0) ?: null;
        $run->fill($attributes);
        $run->save();

        return $run;
    }

    /**
     * @param  array<string, bool>  $selected
     * @param  array<string, string>  $styles
     */
    public function saveSelections(SeoArticleCtaRun $run, array $selected, array $styles): void
    {
        $run->selections = [
            'selected' => $selected,
            'styles' => $styles,
        ];
        $run->save();
    }

    public function markApplied(SeoArticleCtaRun $run): void
    {
        $run->status = 'applied';
        $run->applied_at = now();
        $run->save();
    }

    public function markStale(SeoArticleCtaRun $run): void
    {
        if ($run->status === 'applied') {
            return;
        }
        $run->status = 'stale';
        $run->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(SeoArticleCtaRun $run, ?string $html = null, ?string $fingerprint = null): array
    {
        $summary = is_array($run->summary) ? $run->summary : [];
        $current = $fingerprint !== null && $fingerprint !== ''
            ? $fingerprint
            : ($html !== null ? hash('sha256', $html) : null);

        return [
            'run_id' => (string) $run->id,
            'mode' => (string) $run->mode,
            'status' => (string) $run->status,
            'error_code' => $run->error_code,
            'created_at' => optional($run->created_at)->toIso8601String(),
            'applied_at' => optional($run->applied_at)->toIso8601String(),
            'summary' => $summary,
            'stale' => $current !== null && $current !== (string) $run->source_fingerprint,
            'execution' => [
                'prompt_hook' => $run->prompt_hook,
                'prompt_version' => $run->prompt_version,
                'connection_id' => $run->connection_id,
                'provider' => $run->provider,
                'model' => $run->model,
                'execution_id' => $run->execution_id,
                'prompt_result_id' => $run->prompt_result_id,
                'input_tokens' => $run->input_tokens,
                'output_tokens' => $run->output_tokens,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(SeoArticleCtaRun $run, ?string $html = null, ?string $fingerprint = null): array
    {
        $selections = is_array($run->selections) ? $run->selections : [];

        return array_merge($this->summary($run, $html, $fingerprint), [
            'success' => $run->status !== 'failed',
            'preview_token' => (string) $run->id,
            'source_fingerprint' => (string) $run->source_fingerprint,
            'changes' => is_array($run->changes) ? $run->changes : [],
            'review' => is_array($run->review) ? $run->review : [],
            'selections' => [
                'selected' => is_array($selections['selected'] ?? null) ? $selections['selected'] : [],
                'styles' => is_array($selections['styles'] ?? null) ? $selections['styles'] : [],
            ],
        ]);
    }
}
