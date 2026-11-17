<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\CtaManager;

use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSiteCtaTemplatesPromptInstaller;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use RuntimeException;
use Throwable;

/**
 * Proposes a BATCH of new Site CTA templates (append-only).
 * Does not reuse article.cta.generate / CtaAutomation pipeline.
 * Never writes the pool on failure.
 */
class SiteCtaTemplateGenerateService
{
    public const HOOK_KEY = DefaultSiteCtaTemplatesPromptInstaller::HOOK_KEY;

    public const ROUTING_POLICY = AiRoutingPolicy::QuickFree;

    public const EXISTING_SUMMARY_LIMIT = 12;

    public const EXISTING_SUMMARY_CHARS = 120;

    public function __construct(
        private readonly SiteCtaTemplateContextBuilder $contextBuilder,
        private readonly SiteCtaTemplateOutputValidator $validator,
        private readonly SiteCtaTemplatePoolService $pool,
        private readonly PromptHookBindingRunner $runner,
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     proposals: list<array{slot: int, intent: string, content: string}>,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     context: array<string, mixed>,
     *     current: array<string, mixed>,
     *     overwrite_candidates: list<int>,
     *     next_slot_seq: int,
     *     mode: string
     * }
     */
    public function propose(Site|int $site): array
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);
        $current = $this->pool->getPool($site);
        $context = $this->buildContext($site);
        $existingSummary = $this->existingCtaSummary($current['templates']);
        $existingContents = [];
        foreach ($current['templates'] as $template) {
            $existingContents[] = (string) $template['content'];
        }

        try {
            $prompt = $this->resolvePrompt();
            $result = $this->runner->execute($prompt, [
                'language' => $context['language'],
                'site_context' => $context['site_context'],
                'site_short_description' => $context['site_short_description'],
                'existing_ctas' => $existingSummary,
            ], [
                'locale' => $context['language'],
                'site_id' => (int) $site->id,
            ]);
            $output = $result['value'] ?? $result['output'] ?? $result['text'] ?? null;
        } catch (Throwable $exception) {
            Log::warning('site_cta_templates.generate_failed', [
                'site_id' => (int) $site->id,
                'error_type' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'proposals' => [],
                'errors' => ['generate_failed'],
                'warnings' => $context['warnings'],
                'context' => $context,
                'current' => $current,
                'overwrite_candidates' => [],
                'next_slot_seq' => (int) $current['next_slot_seq'],
                'mode' => 'append',
            ];
        }

        $validated = $this->validator->validate($output, $existingContents);
        if (! $validated['ok']) {
            return [
                'ok' => false,
                'proposals' => [],
                'errors' => $validated['errors'] !== [] ? $validated['errors'] : ['invalid_output'],
                'warnings' => $context['warnings'],
                'context' => $context,
                'current' => $current,
                'overwrite_candidates' => [],
                'next_slot_seq' => (int) $current['next_slot_seq'],
                'mode' => 'append',
            ];
        }

        return [
            'ok' => true,
            'proposals' => $validated['templates'],
            'errors' => [],
            'warnings' => $context['warnings'],
            'context' => $context,
            'current' => $current,
            // Append-only: never overwrite existing slots.
            'overwrite_candidates' => [],
            'next_slot_seq' => (int) $current['next_slot_seq'],
            'mode' => 'append',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $templates
     */
    public function existingCtaSummary(array $templates): string
    {
        if ($templates === []) {
            return '(none)';
        }
        $lines = [];
        $count = 0;
        foreach ($templates as $template) {
            $content = trim((string) ($template['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $count++;
            if ($count > self::EXISTING_SUMMARY_LIMIT) {
                $lines[] = '…and more existing CTAs omitted for token budget.';
                break;
            }
            $slotId = (string) ($template['slot_id'] ?? ('CTA_'.(int) ($template['slot'] ?? 0)));
            $snippet = mb_substr($content, 0, self::EXISTING_SUMMARY_CHARS);
            $lines[] = $slotId.': '.$snippet;
        }

        return $lines !== [] ? implode("\n", $lines) : '(none)';
    }

    /**
     * @return array{
     *     language: string,
     *     site_short_description: string,
     *     site_context: string,
     *     warnings: list<string>,
     *     available_contact_types: list<string>
     * }
     */
    protected function buildContext(Site $site): array
    {
        return $this->contextBuilder->build($site);
    }

    protected function resolvePrompt(): SeoPrompt
    {
        $boundId = $this->settings->getBoundPromptId(self::HOOK_KEY);
        if ($boundId !== null && $boundId > 0) {
            $bound = SeoPrompt::query()->find($boundId);
            if ($bound instanceof SeoPrompt && trim((string) ($bound->markdown_content ?? '')) !== '') {
                return $bound;
            }
        }

        $existing = SeoPrompt::query()->where('hook_key', self::HOOK_KEY)->orderBy('id')->first();
        if ($existing instanceof SeoPrompt && trim((string) ($existing->markdown_content ?? '')) !== '') {
            return $existing;
        }

        app(DefaultSiteCtaTemplatesPromptInstaller::class)->install();
        $installed = SeoPrompt::query()->where('hook_key', self::HOOK_KEY)->orderBy('id')->first();
        if (! $installed instanceof SeoPrompt) {
            throw new RuntimeException('Site CTA templates prompt is not configured.');
        }

        return $installed;
    }
}
