<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use Illuminate\Support\Facades\Log;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleCtaRun;

/**
 * Repairs CTA executions that already stored real routing metadata but failed to insert PromptResult.
 * Does not call a model and does not invent token usage.
 */
final class CtaExecutionHistoryWriter
{
    public function ensurePrompt(): ?int
    {
        $existing = SeoPrompt::query()->where('hook_key', CtaTextGenerator::HOOK_KEY)->value('id');
        if ($existing !== null && (int) $existing > 0) {
            return (int) $existing;
        }

        try {
            $prompt = new SeoPrompt();
            $prompt->fill([
                'name' => 'CTA Automation',
                'title' => 'CTA Automation',
                'markdown_content' => 'Hook article.cta.generate. Body is compiled from the versioned hook JSON.',
                'description' => 'Persistent history parent for CTA copy generation.',
                'hook_key' => CtaTextGenerator::HOOK_KEY,
                'hook_version' => '0.1.0',
                'tools' => 'default',
                'is_active' => true,
                'user_id' => $this->userId(),
                'settings' => ['is_system_default' => true, 'ownership' => 'cta_history'],
            ]);
            $prompt->save();

            return (int) $prompt->getKey();
        } catch (\Throwable $exception) {
            Log::warning('cta.history_prompt_failed', ['error_type' => $exception::class]);

            return null;
        }
    }

    public function repairArticle(SeoArticle $article): void
    {
        $runs = SeoArticleCtaRun::query()
            ->where('article_id', (int) $article->getKey())
            ->whereNull('prompt_result_id')
            ->whereNotNull('model')
            ->get();
        foreach ($runs as $run) {
            $this->repairRun($article, $run);
        }
        $linked = SeoArticleCtaRun::query()
            ->where('article_id', (int) $article->getKey())
            ->whereNotNull('prompt_result_id')
            ->whereNotNull('model')
            ->get();
        foreach ($linked as $run) {
            $this->alignSnapshot($run);
        }
    }

    public function repairRun(SeoArticle $article, SeoArticleCtaRun $run): ?int
    {
        if ((int) ($run->prompt_result_id ?? 0) > 0) {
            return (int) $run->prompt_result_id;
        }
        if (trim((string) ($run->model ?? '')) === '' && (int) ($run->connection_id ?? 0) <= 0) {
            return null;
        }
        $promptId = $this->ensurePrompt();
        if ($promptId === null) {
            return null;
        }

        try {
            $result = PromptResult::query()->create([
                'prompt_id' => $promptId,
                'user_id' => $this->userId($article),
                'site_id' => (int) ($run->site_id ?? $article->site_id ?? 0),
                'status' => 'completed',
                'canonical_prompt_key' => CtaTextGenerator::HOOK_KEY,
                'stage' => CtaTextGenerator::HOOK_KEY,
                'correlation_id' => (string) $run->id,
                'input_snapshot' => [
                    'hook_key' => CtaTextGenerator::HOOK_KEY,
                    'article_id' => (int) $article->getKey(),
                    'cta_run_id' => (string) $run->id,
                    'prompt_version' => (string) ($run->prompt_version ?? '0.1.0'),
                    'provider' => $run->provider,
                    'connection_id' => $run->connection_id,
                    'model' => $run->model,
                    'candidate_model' => $run->model,
                ],
                'output_text' => json_encode($run->changes, JSON_UNESCAPED_UNICODE) ?: null,
                'token_usage' => ($run->input_tokens !== null || $run->output_tokens !== null) ? [
                    'prompt_tokens' => $run->input_tokens,
                    'completion_tokens' => $run->output_tokens,
                ] : null,
                'started_at' => $run->generation_started_at,
                'finished_at' => $run->generation_completed_at ?? $run->updated_at,
            ]);
            $run->prompt_result_id = (int) $result->getKey();
            $run->save();

            return (int) $result->getKey();
        } catch (\Throwable $exception) {
            Log::warning('cta.history_persist_failed', [
                'error_type' => $exception::class,
                'run_id' => (string) $run->id,
            ]);

            return null;
        }
    }

    /**
     * Copy a stored model into the history candidate field. Does not change Free/Paid classification.
     */
    private function alignSnapshot(SeoArticleCtaRun $run): void
    {
        $model = trim((string) ($run->model ?? ''));
        if ($model === '' || (int) ($run->prompt_result_id ?? 0) <= 0) {
            return;
        }
        try {
            $result = PromptResult::query()->find((int) $run->prompt_result_id);
            if ($result === null) {
                return;
            }
            $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
            if (($snapshot['hook_key'] ?? '') !== CtaTextGenerator::HOOK_KEY) {
                return;
            }
            if (trim((string) ($snapshot['candidate_model'] ?? '')) !== '') {
                return;
            }
            $snapshot['candidate_model'] = $model;
            $result->input_snapshot = $snapshot;
            $result->save();
        } catch (\Throwable $exception) {
            Log::warning('cta.history_persist_failed', [
                'error_type' => $exception::class,
                'run_id' => (string) $run->id,
            ]);
        }
    }

    private function userId(?SeoArticle $article = null): int
    {
        $authId = auth()->id();
        if ($authId !== null && (int) $authId > 0) {
            return (int) $authId;
        }
        $owner = (int) ($article->user_id ?? 0);
        if ($owner > 0) {
            return $owner;
        }

        return 1;
    }
}
