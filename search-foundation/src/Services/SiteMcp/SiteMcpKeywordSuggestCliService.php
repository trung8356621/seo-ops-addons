<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use Omnichannel\Addons\SearchIntelligence\Services\AiKeywordDiscoveryService;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use App\Models\Site;
use Throwable;

/**
 * Agent Keyword CLI helper — site-scoped suggest using Site MCP Knowledge Profile.
 *
 * Does not touch Capability Registry / CommandBus.
 */
final class SiteMcpKeywordSuggestCliService
{
    public function __construct(
        private readonly SiteMcpDraft $draftStore,
        private readonly SiteMcpContextAssembler $assembler,
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly AiKeywordDiscoveryService $discovery,
        private readonly ?SiteMcpTopicalProfileService $topicalProfile = null,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     keywords: list<string>,
     *     lines: list<string>,
     *     context_preview: string,
     *     message: string
     * }
     */
    public function suggest(
        Site $site,
        string $keyword = '',
        int $limit = 10,
        bool $useSiteMcp = true,
    ): array {
        $limit = max(1, min(50, $limit));
        $keyword = trim($keyword);

        $official = $this->promptContext->getRawPayloadForSite($site);
        $draft = $this->draftStore->get($site) ?? SiteMcpDraft::empty();
        $draft['site']['company_short_identity'] = trim((string) ($official['company_short_identity'] ?? $draft['site']['company_short_identity'] ?? ''));
        $draft['site']['short_description'] = trim((string) ($official['short_description'] ?? $draft['site']['short_description'] ?? ''));
        $draft['site']['website_type'] = trim((string) ($site->getMeta('seo_domain_type') ?? $draft['site']['website_type'] ?? 'news'));

        $profile = $this->liveTopicalProfile($site);
        if (($profile['topics'] ?? []) !== []) {
            $draft['keyword_context']['topical_profile'] = $profile;
            $draft['keyword_context']['main_topics'] = SiteMcpClusterTopicalProfileBuilder::topicNames($profile);
            $draft['keyword_context']['main_topic_records'] = SiteMcpClusterTopicalProfileBuilder::toMainTopicRecords($profile);
        }

        $mainTopics = $this->mainTopics($draft, $official);
        $contextPreview = '';
        if ($useSiteMcp) {
            $contextPreview = $this->assembler->keywordContext($draft, $mainTopics)['text'];
        }

        if ($keyword === '') {
            if (! $useSiteMcp) {
                return [
                    'ok' => false,
                    'keywords' => [],
                    'lines' => [],
                    'context_preview' => $contextPreview,
                    'message' => "Thiếu --keyword.\nHoặc bật --use-site-mcp=\"yes\" khi site đã có Main Topics.",
                ];
            }

            $keywords = array_slice($mainTopics, 0, $limit);
            if ($keywords === []) {
                return [
                    'ok' => false,
                    'keywords' => [],
                    'lines' => [],
                    'context_preview' => $contextPreview,
                    'message' => "Use Site MCP = yes nhưng chưa có Topical profile từ Keyword Clusters.\nChạy Tách lại cluster / tạo planned cluster, rồi Generate Site MCP Draft.",
                ];
            }

            return $this->okResult($keywords, $contextPreview, 'Main Topics từ Site MCP (seed trống).');
        }

        try {
            $suggestions = $this->discovery->discover(
                $keyword,
                'mixed',
                'vietnam',
                $useSiteMcp ? $contextPreview : '',
                $limit,
            );
        } catch (Throwable $e) {
            // Fallback deterministic: seed + main topics.
            $fallback = [$keyword, ...array_filter(
                $mainTopics,
                static fn (string $t): bool => mb_strtolower($t) !== mb_strtolower($keyword),
            )];
            $keywords = array_slice(array_values(array_unique($fallback)), 0, $limit);

            return $this->okResult(
                $keywords,
                $contextPreview,
                'AI discovery unavailable — fallback seed + Main Topics. '.$e->getMessage(),
            );
        }

        $keywords = [];
        foreach ($suggestions as $row) {
            $kw = is_array($row) ? trim((string) ($row['keyword'] ?? '')) : trim((string) $row);
            if ($kw !== '') {
                $keywords[] = $kw;
            }
            if (count($keywords) >= $limit) {
                break;
            }
        }

        return $this->okResult($keywords, $contextPreview, 'Gợi ý keyword theo seed'.($useSiteMcp ? ' + Site MCP' : '').'.');
    }

    /**
     * @return array{
     *     source: string,
     *     built_at: string,
     *     total_clustered_keywords: int,
     *     topics: list<array<string, mixed>>
     * }
     */
    private function liveTopicalProfile(Site $site): array
    {
        $service = $this->topicalProfile
            ?? (app()->bound(SiteMcpTopicalProfileService::class) ? app(SiteMcpTopicalProfileService::class) : null);
        if (! $service instanceof SiteMcpTopicalProfileService) {
            return [
                'source' => SiteMcpClusterTopicalProfileBuilder::SOURCE,
                'built_at' => gmdate('c'),
                'total_clustered_keywords' => 0,
                'topics' => [],
            ];
        }

        return $service->get($site);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $official
     * @return list<string>
     */
    private function mainTopics(array $draft, array $official): array
    {
        $keyword = is_array($draft['keyword_context'] ?? null) ? $draft['keyword_context'] : [];
        $fromDraft = is_array($keyword['main_topics'] ?? null) ? $keyword['main_topics'] : [];
        $out = [];
        foreach ($fromDraft as $topic) {
            $topic = trim((string) $topic);
            if ($topic !== '') {
                $out[] = $topic;
            }
        }

        // Legacy fallback only when cluster topical profile is empty.
        if ($out === []) {
            foreach (is_array($official['links'] ?? null) ? $official['links'] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $topic = trim((string) ($row['keyword'] ?? ''));
                if ($topic !== '') {
                    $out[] = $topic;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $keywords
     * @return array{ok: bool, keywords: list<string>, lines: list<string>, context_preview: string, message: string}
     */
    private function okResult(array $keywords, string $contextPreview, string $message): array
    {
        $lines = ['Gợi ý keyword', ''];
        $i = 1;
        foreach ($keywords as $kw) {
            $lines[] = $i.'. '.$kw;
            $i++;
        }
        if ($contextPreview !== '') {
            $lines[] = '';
            $lines[] = $contextPreview;
        }

        return [
            'ok' => true,
            'keywords' => array_values($keywords),
            'lines' => $lines,
            'context_preview' => $contextPreview,
            'message' => $message,
        ];
    }
}
