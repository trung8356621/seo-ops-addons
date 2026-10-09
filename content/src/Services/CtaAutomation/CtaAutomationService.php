<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\Site;
use Omnichannel\Addons\Content\Models\SeoArticle;

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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(SeoArticle $article, string $html, string $mode = 'improve'): array
    {
        $mode = $mode === 'regenerate' ? 'regenerate' : 'improve';
        $sourceHash = hash('sha256', $html);
        $detected = $this->legacy->detect($html);
        $sectionRows = $this->sections->extract($html);
        $site = $this->site($article);
        $enabled = $this->shortcodes->enabledAliases($site);

        try {
            $plan = $this->planner->plan($this->plannerPayload($article, $sectionRows, $detected));
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage(), $detected);
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

        $generated = ['ok' => true, 'ctas' => [], 'errors' => []];
        if ($placements !== []) {
            $generated = $this->generator->generate(
                $this->language($article),
                $this->tone($article),
                $this->businessContext($site),
                $placements,
                $sectionRows,
            );
            if (! $generated['ok']) {
                return [
                    'success' => false,
                    'status' => 'validation_failed',
                    'message' => 'CTA generation failed validation.',
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
                'replacement' => null,
                'intent' => null,
                'alias' => null,
                'position' => null,
            ];
        }
        $origin = $mode === 'regenerate' ? 'generated' : 'improved';
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
            ];
        }

        $token = (string) Str::uuid();
        Cache::put($this->cacheKey($token), [
            'article_id' => (int) $article->getKey(),
            'source_hash' => $sourceHash,
            'changes' => $changes,
            'mode' => $mode,
        ], now()->addMinutes(30));

        return [
            'success' => true,
            'status' => 'ready',
            'preview_token' => $token,
            'source_hash' => $sourceHash,
            'summary' => $this->summary($detected, $removals, $changes, $review),
            'changes' => $changes,
            'review' => $review,
            'debug' => [
                'skipped' => $plan['skipped'] ?? [],
                'enabled_aliases' => $enabled,
            ],
        ];
    }

    /**
     * @param  list<string>  $approvedIds
     * @return array<string, mixed>
     */
    public function apply(SeoArticle $article, string $html, string $token, array $approvedIds): array
    {
        $cached = Cache::get($this->cacheKey($token));
        if (! is_array($cached) || (int) ($cached['article_id'] ?? 0) !== (int) $article->getKey()) {
            return ['success' => false, 'status' => 'preview_expired', 'message' => 'CTA preview expired. Run Improve CTA again.'];
        }
        if (hash('sha256', $html) !== (string) ($cached['source_hash'] ?? '')) {
            return ['success' => false, 'status' => 'stale_preview', 'message' => 'Article changed after the CTA preview.'];
        }

        $approved = array_fill_keys($approvedIds, true);
        $operations = [];
        $site = $this->site($article);
        $changes = is_array($cached['changes'] ?? null) ? $cached['changes'] : [];
        $insertIds = [];
        foreach ($changes as $change) {
            if (! is_array($change) || ! isset($approved[(string) ($change['id'] ?? '')])) {
                continue;
            }
            if (($change['kind'] ?? '') === 'remove') {
                $operations[] = [
                    'id' => $change['id'],
                    'kind' => 'remove',
                    'text' => (string) ($change['original'] ?? ''),
                ];
            }
            if (($change['kind'] ?? '') === 'insert') {
                $insertIds[] = (string) ($change['placement_id'] ?? '');
            }
        }
        foreach ($insertIds as $placementId) {
            if ($placementId !== '') {
                $operations[] = [
                    'id' => 'clear_'.$placementId,
                    'kind' => 'remove_managed',
                    'placement_id' => $placementId,
                ];
            }
        }
        if ($insertIds !== []) {
            $operations[] = ['id' => 'clear_auto', 'kind' => 'remove_managed', 'placement_id' => ''];
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
            $operations[] = [
                'id' => $change['id'],
                'kind' => 'insert',
                'section_id' => (string) $change['section_id'],
                'html' => $this->renderer->blockHtml(
                    (string) ($change['placement_id'] ?? ''),
                    (string) $change['section_id'],
                    (string) ($change['intent'] ?? ''),
                    $alias !== '' ? $alias : null,
                    (string) ($change['origin'] ?? 'improved'),
                    (string) ($change['replacement'] ?? ''),
                    $link,
                ),
            ];
        }

        $rendered = $this->renderer->apply($html, $operations);

        return [
            'success' => true,
            'status' => 'applied',
            'html' => $rendered['html'],
            'applied' => $rendered['applied'],
            'skipped' => $rendered['skipped'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attachGenerated(SeoArticle $article): array
    {
        $html = (string) ($article->body ?? '');
        $preview = $this->preview($article, $html, 'regenerate');
        if (($preview['success'] ?? false) !== true) {
            return [
                'status' => 'failed',
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
            return ['status' => 'empty', 'message' => 'No CTA placement.'];
        }
        $applied = $this->apply($article, $html, (string) $preview['preview_token'], $ids);
        if (($applied['success'] ?? false) !== true) {
            return ['status' => 'failed', 'message' => (string) ($applied['message'] ?? 'cta_failed')];
        }
        $article->body = (string) $applied['html'];
        $article->save();

        return ['status' => 'applied', 'placements' => count($ids)];
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
    private function failure(string $message, array $detected): array
    {
        return [
            'success' => false,
            'status' => 'semantic_failed',
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

    private function cacheKey(string $token): string
    {
        return 'cta-automation-preview:'.$token;
    }
}
