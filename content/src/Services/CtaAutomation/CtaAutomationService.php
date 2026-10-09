<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use App\Models\Site;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleCtaRun;

/**
 * Shared Improve / Regenerate / generated-article CTA pipeline.
 */
final class CtaAutomationService
{
    public function __construct(
        private readonly CtaArticleSections $sections,
        private readonly LegacyCtaDetector $legacy,
        private readonly SemanticCtaPlanClient $planner,
        private readonly CtaShortcodeRegistry $shortcodes,
        private readonly CtaTextGenerator $generator,
        private readonly CtaBlockRenderer $renderer,
        private readonly CtaRunStore $runs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(SeoArticle $article, string $html, string $mode = 'improve', ?string $idempotencyKey = null): array
    {
        $mode = in_array($mode, ['generate', 'improve', 'regenerate'], true) ? $mode : 'improve';
        $existing = $this->runs->findIdempotent($article, $idempotencyKey);
        if ($existing instanceof SeoArticleCtaRun) {
            return $this->runs->payload($existing, $html);
        }
        $detected = $this->legacy->detect($html);
        $sectionRows = $this->sections->extract($html);
        $site = $this->site($article);
        $enabled = $this->shortcodes->enabledAliases($site);

        try {
            $plan = $this->planner->plan($this->plannerPayload($article, $sectionRows, $detected));
        } catch (\Throwable $exception) {
            return $this->failure($article, $html, $mode, $idempotencyKey, $exception, $detected);
        }

        $legacyById = [];
        foreach ($plan['legacy'] ?? [] as $row) {
            if (is_array($row)) {
                $legacyById[(string) ($row['candidate_id'] ?? '')] = $row;
            }
        }

        $review = [];
        $removals = [];
        if ($mode === 'improve') {
            foreach ($detected as $candidate) {
                $semantic = $legacyById[$candidate['candidate_id']] ?? null;
                $class = (string) ($semantic['classification'] ?? 'uncertain');
                if ($candidate['confidence'] === 'high' && $class === 'promotional') {
                    $removals[] = $candidate;
                    continue;
                }
                if ($candidate['confidence'] === 'uncertain' || $class === 'uncertain') {
                    $review[] = [
                        'id' => $candidate['candidate_id'],
                        'section_id' => $candidate['section_id'],
                        'heading' => $candidate['heading'],
                        'text' => $candidate['text'],
                        'reason' => (string) ($semantic['reason'] ?? 'manual_review'),
                    ];
                }
            }
        }

        $placements = $this->guardPlacements(
            is_array($plan['placements'] ?? null) ? $plan['placements'] : [],
            $sectionRows,
        );
        $used = [];
        foreach ($placements as $index => $placement) {
            $alias = $this->shortcodes->assign((string) $placement['intent'], $enabled, $used);
            $placements[$index]['alias'] = $alias;
            if ($alias !== null) {
                $used[] = $alias;
            }
        }

        $run = $this->runs->create($article, [
            'mode' => $mode,
            'status' => 'generating',
            'source_fingerprint' => CtaHtmlFingerprint::hash($html),
            'idempotency_key' => trim((string) $idempotencyKey) !== '' ? trim((string) $idempotencyKey) : null,
            'generation_started_at' => now(),
            'plan' => [
                'placements' => $placements,
                'legacy' => $plan['legacy'] ?? [],
                'sections' => array_map(static fn (array $section): array => [
                    'section_id' => $section['section_id'],
                    'heading' => $section['heading'],
                    'word_count' => $section['word_count'],
                ], $sectionRows),
            ],
            'review' => $review,
            'summary' => $this->summary($detected, $removals, [], $review),
            'prompt_hook' => CtaTextGenerator::HOOK_KEY,
            'prompt_version' => '0.1.0',
        ]);

        $generated = ['ok' => true, 'ctas' => [], 'errors' => [], 'execution' => []];
        if ($placements !== []) {
            $generated = $this->generator->generate(
                $this->language($article),
                $this->tone($article),
                $this->businessContext($site),
                $placements,
                $sectionRows,
                [
                    'article_id' => (int) $article->getKey(),
                    'site_id' => (int) ($article->site_id ?? 0),
                    'user_id' => (int) ($article->user_id ?? 0),
                    'run_id' => (string) $run->id,
                ],
            );
            if (! $generated['ok']) {
                $codes = array_column(is_array($generated['errors'] ?? null) ? $generated['errors'] : [], 'code');
                $run->status = 'failed';
                $run->error_code = in_array('no_free_model', $codes, true)
                    ? 'no_free_model'
                    : (in_array('paid_route_blocked', $codes, true) ? 'paid_route_blocked' : 'validation_failed');
                $run->generation_completed_at = now();
                $run->save();

                return [
                    'success' => false,
                    'status' => 'validation_failed',
                    'run_id' => (string) $run->id,
                    'message' => match ($run->error_code) {
                        'no_free_model' => 'Không có model Free khả dụng. Kết quả CTA đã lưu vẫn được giữ.',
                        'paid_route_blocked' => 'CTA từ chối model trả phí. Kết quả CTA đã lưu vẫn được giữ.',
                        default => 'CTA generation failed validation.',
                    },
                    'errors' => $generated['errors'],
                    'summary' => $this->summary($detected, [], [], $review),
                    'review' => $review,
                ];
            }
        }

        $textById = [];
        foreach ($generated['ctas'] as $cta) {
            $textById[$cta['placement_id']] = $cta['text'];
        }
        $headingBySection = [];
        foreach ($sectionRows as $section) {
            $headingBySection[$section['section_id']] = $section['heading'];
        }

        $changes = [];
        foreach ($removals as $candidate) {
            $changes[] = [
                'id' => 'remove_'.$candidate['candidate_id'],
                'kind' => 'remove',
                'section_id' => $candidate['section_id'],
                'heading' => $candidate['heading'],
                'original' => $candidate['text'],
                'occurrence' => (int) ($candidate['occurrence'] ?? 1),
                'replacement' => null,
                'intent' => null,
                'alias' => null,
                'position' => null,
            ];
        }
        $origin = $mode === 'improve' ? 'improved' : 'generated';
        foreach ($placements as $placement) {
            $placementId = (string) $placement['placement_id'];
            $text = (string) ($textById[$placementId] ?? '');
            if ($text === '') {
                continue;
            }
            $changes[] = [
                'id' => 'insert_'.$placementId,
                'kind' => 'insert',
                'section_id' => $placement['section_id'],
                'heading' => $headingBySection[$placement['section_id']] ?? '',
                'original' => null,
                'replacement' => $text,
                'intent' => $placement['intent'],
                'alias' => $placement['alias'],
                'position' => 'section_end',
                'placement_id' => $placementId,
                'origin' => $origin,
                'style' => $this->renderer->readStylePresets($html)[$placementId] ?? $this->defaultStyle((string) $placement['intent']),
            ];
        }

        $summary = $this->summary($detected, $removals, $changes, $review);
        $selected = [];
        $styles = [];
        foreach ($changes as $change) {
            $selected[(string) $change['id']] = true;
            if (($change['kind'] ?? '') === 'insert') {
                $styles[(string) $change['id']] = (string) ($change['style'] ?? 'soft');
            }
        }
        $execution = is_array($generated['execution'] ?? null) ? $generated['execution'] : [];
        $run->status = 'ready';
        $run->generation_completed_at = now();
        $run->changes = $changes;
        $run->summary = $summary;
        $run->selections = ['selected' => $selected, 'styles' => $styles];
        $run->connection_id = $execution['connection_id'] ?? null;
        $run->provider = $execution['provider'] ?? null;
        $run->model = $execution['model'] ?? null;
        $run->execution_id = $execution['execution_id'] ?? null;
        $run->prompt_result_id = $execution['prompt_result_id'] ?? null;
        $run->input_tokens = $execution['input_tokens'] ?? null;
        $run->output_tokens = $execution['output_tokens'] ?? null;
        $run->prompt_hook = $execution['prompt_hook'] ?? CtaTextGenerator::HOOK_KEY;
        $run->prompt_version = $execution['prompt_version'] ?? '0.1.0';
        $run->save();

        return $this->runs->payload($run, $html) + [
            'success' => true,
            'status' => 'ready',
            'debug' => [
                'skipped' => $plan['skipped'] ?? [],
                'enabled_aliases' => $enabled,
            ],
        ];
    }

    /**
     * @param  list<string>  $approvedIds
     * @param  array<string, string>  $styleOverrides
     * @return array<string, mixed>
     */
    public function apply(SeoArticle $article, string $html, string $token, array $approvedIds, array $styleOverrides = [], bool $acknowledgeStale = false): array
    {
        $run = $this->runs->findForArticle($article, $token);
        if (! $run instanceof SeoArticleCtaRun || ! in_array((string) $run->status, ['ready', 'stale'], true)) {
            return ['success' => false, 'status' => 'preview_expired', 'message' => 'CTA preview expired. Run Improve CTA again.'];
        }
        $stored = (string) $run->source_fingerprint;
        $fingerprintMatches = hash_equals($stored, hash('sha256', $html))
            || hash_equals($stored, CtaHtmlFingerprint::hash($html));
        $targetsMatch = CtaHtmlFingerprint::targetsMatch(
            $html,
            is_array($run->plan) ? $run->plan : [],
            is_array($run->changes) ? $run->changes : [],
        );
        if (! $fingerprintMatches && ! $targetsMatch) {
            $this->runs->markStale($run);

            return [
                'success' => false,
                'status' => 'stale_preview',
                'message' => 'Bài viết đã thay đổi sau khi tạo CTA. Kết quả cũ vẫn được lưu nhưng cần kiểm tra lại trước khi áp dụng.',
            ];
        }

        $approved = array_fill_keys($approvedIds, true);
        $operations = [];
        $site = $this->site($article);
        $preservedStyles = $this->renderer->readStylePresets($html);
        $changes = is_array($run->changes) ? $run->changes : [];
        foreach ($changes as $change) {
            if (! is_array($change) || ($change['kind'] ?? '') !== 'remove' || ! isset($approved[(string) ($change['id'] ?? '')])) {
                continue;
            }
            $operations[] = [
                'id' => $change['id'],
                'kind' => 'remove',
                'section_id' => (string) ($change['section_id'] ?? ''),
                'text' => (string) ($change['original'] ?? ''),
                'occurrence' => (int) ($change['occurrence'] ?? 1),
            ];
        }
        foreach ($changes as $change) {
            if (! is_array($change) || ($change['kind'] ?? '') !== 'insert' || ! isset($approved[(string) $change['id']])) {
                continue;
            }
            $alias = isset($change['alias']) ? (string) $change['alias'] : null;
            $link = $alias !== null && $alias !== '' ? $this->shortcodes->renderAlias($alias, $site) : null;
            if ($alias !== null && $alias !== '' && $link === null) {
                return [
                    'success' => false,
                    'status' => 'unresolved_shortcode',
                    'message' => 'CTA shortcode could not be resolved.',
                    'errors' => [['placement_id' => $change['placement_id'] ?? '', 'code' => 'unresolved_shortcode']],
                ];
            }
            $placementId = (string) ($change['placement_id'] ?? '');
            $requestedStyle = (string) ($styleOverrides[(string) $change['id']] ?? '');
            $style = in_array($requestedStyle, ['soft', 'consultation', 'conversion'], true)
                ? $requestedStyle
                : ($preservedStyles[$placementId] ?? (string) ($change['style'] ?? $this->defaultStyle((string) ($change['intent'] ?? ''))));
            $operations[] = [
                'id' => $change['id'],
                'kind' => 'insert',
                'section_id' => (string) $change['section_id'],
                'placement_id' => $placementId,
                'html' => $this->renderer->blockHtml(
                    $placementId,
                    (string) $change['section_id'],
                    (string) ($change['intent'] ?? ''),
                    $alias !== '' ? $alias : null,
                    (string) ($change['origin'] ?? 'improved'),
                    (string) ($change['replacement'] ?? ''),
                    $style,
                    (string) $run->id,
                ),
            ];
        }

        $rendered = $this->renderer->apply($html, $operations);
        if (($rendered['ok'] ?? false) !== true) {
            if ($this->approvedInsertsPresent($html, $changes, $approved)) {
                return [
                    'success' => true,
                    'status' => 'pending_editor',
                    'run_id' => (string) $run->id,
                    'html' => $html,
                    'applied' => [],
                    'skipped' => [],
                ];
            }
            if (! $targetsMatch) {
                $this->runs->markStale($run);
            }

            return [
                'success' => false,
                'status' => 'apply_incomplete',
                'message' => 'Không áp dụng được thao tác CTA đã chọn. Nội dung bài không đổi.',
                'skipped' => $rendered['skipped'] ?? [],
            ];
        }

        $this->runs->saveSelections($run, $approved, $styleOverrides);

        return [
            'success' => true,
            'status' => 'pending_editor',
            'run_id' => (string) $run->id,
            'html' => $rendered['html'],
            'applied' => $rendered['applied'],
            'skipped' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmApplied(SeoArticle $article, string $runId, string $html): array
    {
        $run = $this->runs->findForArticle($article, $runId);
        if (! $run instanceof SeoArticleCtaRun) {
            return ['success' => false, 'status' => 'preview_expired', 'message' => 'CTA run was not found.'];
        }
        if ((string) $run->status === 'applied') {
            return ['success' => true, 'status' => 'applied', 'run_id' => (string) $run->id];
        }
        $changes = is_array($run->changes) ? $run->changes : [];
        $selected = is_array($run->selections['selected'] ?? null) ? $run->selections['selected'] : [];
        foreach ($changes as $change) {
            if (! is_array($change) || ($change['kind'] ?? '') !== 'insert') {
                continue;
            }
            if (($selected[(string) ($change['id'] ?? '')] ?? false) !== true) {
                continue;
            }
            $placementId = (string) ($change['placement_id'] ?? '');
            if ($placementId !== '' && ! str_contains($html, 'data-cta-placement="'.$placementId.'"')) {
                return ['success' => false, 'status' => 'editor_rejected', 'message' => 'Editor did not accept the CTA document.'];
            }
        }
        $this->runs->markApplied($run);

        return ['success' => true, 'status' => 'applied', 'run_id' => (string) $run->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function attachGenerated(SeoArticle $article): array
    {
        $html = (string) ($article->body ?? '');
        $preview = $this->preview($article, $html, 'generate');
        if (($preview['success'] ?? false) !== true) {
            return [
                'status' => 'failed',
                'run_id' => $preview['run_id'] ?? null,
                'message' => (string) ($preview['message'] ?? 'cta_failed'),
                'errors' => $preview['errors'] ?? [],
            ];
        }
        $ids = [];
        foreach ($preview['changes'] ?? [] as $change) {
            if (is_array($change) && ($change['kind'] ?? '') === 'insert') {
                $ids[] = (string) $change['id'];
            }
        }
        if ($ids === []) {
            return ['status' => 'empty', 'run_id' => $preview['run_id'] ?? null, 'message' => 'No CTA placement.'];
        }
        $applied = $this->apply($article, $html, (string) ($preview['run_id'] ?? ''), $ids, [], true);
        if (($applied['success'] ?? false) !== true) {
            return ['status' => 'failed', 'run_id' => $preview['run_id'] ?? null, 'message' => (string) ($applied['message'] ?? 'cta_failed')];
        }
        $article->body = (string) $applied['html'];
        $article->save();
        $this->confirmApplied($article, (string) ($preview['run_id'] ?? ''), (string) $applied['html']);

        return ['status' => 'applied', 'run_id' => $preview['run_id'] ?? null, 'mode' => 'generate', 'placements' => count($ids)];
    }

    public function latestRun(SeoArticle $article): ?SeoArticleCtaRun
    {
        try {
            (new CtaExecutionHistoryWriter())->repairArticle($article);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('cta.history_persist_failed', [
                'error_type' => $exception::class,
                'article_id' => (int) $article->getKey(),
            ]);
        }

        return $this->runs->latest($article);
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, bool>  $approved
     */
    private function approvedInsertsPresent(string $html, array $changes, array $approved): bool
    {
        $required = 0;
        foreach ($changes as $change) {
            if (! is_array($change) || ($change['kind'] ?? '') !== 'insert' || ! isset($approved[(string) ($change['id'] ?? '')])) {
                continue;
            }
            $placementId = (string) ($change['placement_id'] ?? '');
            if ($placementId === '') {
                continue;
            }
            $required++;
            if (! str_contains($html, 'data-cta-placement="'.$placementId.'"')) {
                return false;
            }
        }

        return $required > 0;
    }

    /**
     * @return list<SeoArticleCtaRun>
     */
    public function recentRuns(SeoArticle $article): array
    {
        return $this->runs->recent($article);
    }

    public function findRun(SeoArticle $article, string $id): ?SeoArticleCtaRun
    {
        return $this->runs->findForArticle($article, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function runPayload(SeoArticleCtaRun $run, ?string $html = null, ?string $fingerprint = null): array
    {
        return $this->runs->payload($run, $html, $fingerprint);
    }

    /**
     * @return array<string, mixed>
     */
    public function runSummary(SeoArticleCtaRun $run, ?string $html = null, ?string $fingerprint = null): array
    {
        return $this->runs->summary($run, $html, $fingerprint);
    }

    /**
     * @param  array<string, mixed>  $selected
     * @param  array<string, mixed>  $styles
     */
    public function saveRunSelections(SeoArticleCtaRun $run, array $selected, array $styles): void
    {
        $flags = [];
        foreach ($selected as $id => $on) {
            $flags[(string) $id] = (bool) $on;
        }
        $cleanStyles = [];
        foreach ($styles as $id => $style) {
            $cleanStyles[(string) $id] = (string) $style;
        }
        $this->runs->saveSelections($run, $flags, $cleanStyles);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $detected
     * @return array<string, mixed>
     */
    private function plannerPayload(SeoArticle $article, array $sections, array $detected): array
    {
        $words = 0;
        $cleanSections = [];
        foreach ($sections as $section) {
            $words += (int) $section['word_count'];
            $cleanSections[] = [
                'section_id' => $section['section_id'],
                'heading' => $section['heading'],
                'position_ratio' => $section['position_ratio'],
                'start_word' => $section['start_word'],
                'word_count' => $section['word_count'],
                'content' => mb_substr($this->redact((string) $section['content']), 0, 1500),
            ];
        }
        $legacy = [];
        foreach ($detected as $candidate) {
            $legacy[] = [
                'candidate_id' => $candidate['candidate_id'],
                'section_id' => $candidate['section_id'],
                'text' => mb_substr($this->redact((string) $candidate['text']), 0, 500),
                'structural_signal' => $candidate['structural_signal'],
            ];
        }

        return [
            'language' => $this->language($article),
            'article_word_count' => $words,
            'article_type' => 'article',
            'sections' => $cleanSections,
            'legacy_candidates' => $legacy,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $placements
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function guardPlacements(array $placements, array $sections): array
    {
        $known = [];
        foreach ($sections as $section) {
            $known[$section['section_id']] = $section;
        }
        $accepted = [];
        $seen = [];
        foreach ($placements as $placement) {
            if (! is_array($placement)) {
                continue;
            }
            $sectionId = (string) ($placement['section_id'] ?? '');
            $placementId = (string) ($placement['placement_id'] ?? '');
            $intent = (string) ($placement['intent'] ?? '');
            if ($placementId === '' || isset($seen[$placementId]) || ! isset($known[$sectionId])) {
                continue;
            }
            if (! in_array($intent, ['product_discovery', 'service_discovery', 'comparison', 'consultation', 'conversion'], true)) {
                continue;
            }
            $start = (int) ($known[$sectionId]['start_word'] ?? 0);
            $tooClose = false;
            foreach ($accepted as $row) {
                $other = (int) ($known[$row['section_id']]['start_word'] ?? 0);
                if (abs($start - $other) < 180) {
                    $tooClose = true;
                    break;
                }
            }
            if ($tooClose || count($accepted) >= 5) {
                continue;
            }
            $seen[$placementId] = true;
            $placement['section_id'] = $sectionId;
            $placement['placement_id'] = $placementId;
            $placement['intent'] = $intent;
            $accepted[] = $placement;
        }

        return $accepted;
    }

    /**
     * @param  list<array<string, mixed>>  $detected
     * @param  list<array<string, mixed>>  $removals
     * @param  list<array<string, mixed>>  $changes
     * @param  list<array<string, mixed>>  $review
     * @return array<string, int>
     */
    private function summary(array $detected, array $removals, array $changes, array $review): array
    {
        $inserts = 0;
        foreach ($changes as $change) {
            if (($change['kind'] ?? '') === 'insert') {
                $inserts++;
            }
        }

        return [
            'legacy_detected' => count($detected),
            'replacements' => count($removals),
            'insertions' => $inserts,
            'needs_review' => count($review),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $detected
     * @return array<string, mixed>
     */
    private function failure(SeoArticle $article, string $html, string $mode, ?string $idempotencyKey, \Throwable $exception, array $detected): array
    {
        $message = $exception->getMessage();
        $code = str_starts_with($message, 'semantic_')
            ? strtok($message, ' ')
            : 'semantic_plan_failed';
        $code = is_string($code) && $code !== '' ? $code : 'semantic_plan_failed';
        $run = $this->runs->create($article, [
            'mode' => $mode,
            'status' => 'failed',
            'error_code' => $code,
            'source_fingerprint' => CtaHtmlFingerprint::hash($html),
            'idempotency_key' => trim((string) $idempotencyKey) !== '' ? trim((string) $idempotencyKey) : null,
            'generation_started_at' => now(),
            'generation_completed_at' => now(),
            'summary' => $this->summary($detected, [], [], []),
            'changes' => [],
            'review' => [],
        ]);

        return [
            'success' => false,
            'status' => 'semantic_failed',
            'run_id' => (string) $run->id,
            'error_code' => $code,
            'message' => $message,
            'summary' => $this->summary($detected, [], [], []),
            'changes' => [],
            'review' => [],
        ];
    }

    private function site(SeoArticle $article): Site|int|null
    {
        $id = (int) ($article->site_id ?? 0);

        return $id > 0 ? $id : null;
    }

    private function language(SeoArticle $article): string
    {
        $language = strtolower(trim((string) ($article->language ?? $article->locale ?? 'vi')));

        return $language !== '' ? $language : 'vi';
    }

    private function tone(SeoArticle $article): string
    {
        $tone = trim((string) ($article->tone ?? ''));

        return $tone !== '' ? $tone : 'informative';
    }

    private function businessContext(Site|int|null $site): string
    {
        if (! $site instanceof Site) {
            if (! is_int($site)) {
                return 'general';
            }
            $site = Site::query()->find($site);
        }
        $name = trim((string) ($site->name ?? ''));

        return $name !== '' ? $name : 'general';
    }

    private function redact(string $text): string
    {
        $text = preg_replace('#https?://\S+#i', '[link]', $text) ?? $text;
        $text = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', '[email]', $text) ?? $text;
        $text = preg_replace('/\+?\d[\d\s.\-]{7,}\d/u', '[phone]', $text) ?? $text;

        return $text;
    }

    private function defaultStyle(string $intent): string
    {
        return match ($intent) {
            'consultation' => 'consultation',
            'conversion' => 'conversion',
            default => 'soft',
        };
    }
}
