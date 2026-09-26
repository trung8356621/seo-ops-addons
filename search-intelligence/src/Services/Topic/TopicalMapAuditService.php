<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use App\Models\Site;
use InvalidArgumentException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResult;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeResult;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapOverview;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use Throwable;

/**
 * AI Topical Map Audit — Prompt registry + live canonical context
 * (Site Knowledge + Keyword Landscape + GSC when available)
 * + structural Topical Map projection + existing Topic Tags.
 * One execution = audit findings + tag suggestions. Does not create Topics or articles.
 * Does not auto-run after Recluster.
 */
final class TopicalMapAuditService
{
    public const HOOK_KEY = DefaultTopicalMapAuditPromptInstaller::HOOK_KEY;

    public const HOOK_VERSION = DefaultTopicalMapAuditPromptInstaller::HOOK_VERSION;

    public const EMPTY_MAP_MESSAGE = 'Topical Map has no Topics. Add Topics before running AI Audit.';

    public function __construct(
        private readonly TopicalMapReadModel $topicalMap,
        private readonly TopicalMapAuditContextBuilder $auditContext,
        private readonly PromptHookCallerBridge $promptHookBridge,
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SitePrimaryLanguageService $primaryLanguage,
        private readonly SiteDomainPromptContextService $domainPromptContext,
        private readonly TopicalMapAuditResultParser $parser,
        private readonly TopicUserTagService $topicTags,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   payload: array<string, mixed>|null,
     *   prompt_result_id: int|null,
     *   tag_apply: array{
     *     ok: bool,
     *     error: ?string,
     *     tag_count: int,
     *     topic_tagged_count: int,
     *     ai_assignment_count: int,
     *     untagged_topics: int
     *   }|null
     * }
     */
    public function audit(int $siteId, ?int $actorId = null, ?string $languageCode = null): array
    {
        if ($siteId <= 0) {
            return $this->fail('Site is required.');
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return $this->fail('Site not found.');
        }

        $overview = $this->topicalMap->overview($siteId);
        if ($overview->topicCount <= 0 || $overview->topics === []) {
            return $this->fail(self::EMPTY_MAP_MESSAGE);
        }

        $structural = $this->structuralProjection($overview);
        $allowedTopicRefs = array_values(array_filter(array_map(
            static fn (array $t): string => (string) ($t['topic_ref'] ?? ''),
            $structural['topics'],
        )));

        $mapJson = json_encode($structural, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($mapJson) || $mapJson === '') {
            return $this->fail('Failed to encode Topical Map overview.');
        }

        $existingTags = $this->topicTags->listForSite($siteId);
        $existingTagsPayload = array_map(
            static fn (array $tag): array => [
                'name' => (string) ($tag['name'] ?? ''),
                'slug' => (string) ($tag['slug'] ?? ''),
                'topic_count' => (int) ($tag['topic_count'] ?? 0),
            ],
            $existingTags,
        );
        $existingTagsJson = json_encode($existingTagsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($existingTagsJson)) {
            $existingTagsJson = '[]';
        }

        $periodKey = now()->format('Y-m');
        $mcpMarkdown = $this->auditContext->buildMarkdown($site, $periodKey);

        $language = $this->resolveEffectivePromptLanguage($site, $languageCode);

        $domainPayload = $this->domainPromptContext->getForSite($siteId);
        $companyShort = trim((string) ($domainPayload['company_short_identity'] ?? ''));
        $shortDescription = trim((string) ($domainPayload['short_description'] ?? ''));

        try {
            $run = $this->runPrompt(
                siteId: $siteId,
                actorId: $actorId,
                mcpMarkdown: $mcpMarkdown,
                topicalMapJson: $mapJson,
                existingTopicTagsJson: $existingTagsJson,
                primaryLanguage: $language,
                siteDomain: (string) ($site->domain ?? ''),
                periodKey: $periodKey,
                companyShortIdentity: $companyShort,
                shortDescription: $shortDescription,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            return $this->failFromThrowable($e);
        }

        $parsed = $this->parser->parse($run['value'], $allowedTopicRefs, $existingTags);
        if (! $parsed['ok']) {
            return $this->fail($parsed['message'], $run['prompt_result_id']);
        }

        $payload = $parsed['payload'] ?? [];
        $tagSuggestions = is_array($payload['tag_suggestions'] ?? null) ? $payload['tag_suggestions'] : [
            'taxonomy' => [],
            'assignments' => [],
        ];
        $taxonomy = is_array($tagSuggestions['taxonomy'] ?? null) ? $tagSuggestions['taxonomy'] : [];
        $assignments = is_array($tagSuggestions['assignments'] ?? null) ? $tagSuggestions['assignments'] : [];

        $tagApply = $this->topicTags->syncAiSuggestions(
            $siteId,
            $taxonomy,
            $assignments,
            $overview->topicCount,
        );

        if (! ($tagApply['ok'] ?? false)) {
            return [
                'ok' => true,
                'message' => 'Audit succeeded but Tag apply failed: '.(string) ($tagApply['error'] ?? 'unknown'),
                'payload' => $payload,
                'prompt_result_id' => $run['prompt_result_id'],
                'tag_apply' => $tagApply,
            ];
        }

        return [
            'ok' => true,
            'message' => '',
            'payload' => $payload,
            'prompt_result_id' => $run['prompt_result_id'],
            'tag_apply' => $tagApply,
        ];
    }

    /**
     * Structural Topical Map projection for the prompt — canonical topic_ref list.
     * Omits chart/frontend-only fields (lazy children flags, coordinates, chart config).
     *
     * @return array{
     *   site_id: int,
     *   summary: array{topic_count: int, total_articles: int, total_keywords: int, source_updated_at: string|null},
     *   topics: list<array{
     *     topic_ref: string,
     *     name: string,
     *     mcp: float|int|string|null,
     *     dna_count: int,
     *     article_count: int,
     *     keyword_count: int,
     *     coverage: string,
     *     status: string
     *   }>
     * }
     */
    public function structuralProjection(TopicalMapOverview $overview): array
    {
        $topics = [];
        foreach ($overview->topics as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $topics[] = [
                'topic_ref' => TopicalMapAuditContracts::topicRef($id),
                'name' => trim((string) ($row['name'] ?? '')),
                'mcp' => $row['mcp'] ?? null,
                'dna_count' => (int) ($row['dna_count'] ?? 0),
                'article_count' => (int) ($row['article_count'] ?? 0),
                'keyword_count' => (int) ($row['keyword_count'] ?? 0),
                'coverage' => trim((string) ($row['coverage'] ?? '')),
                'status' => trim((string) ($row['status'] ?? '')),
            ];
        }

        return [
            'site_id' => $overview->siteId,
            'summary' => [
                'topic_count' => $overview->topicCount,
                'total_articles' => $overview->totalArticles,
                'total_keywords' => $overview->totalKeywords,
                'source_updated_at' => $overview->sourceUpdatedAt,
            ],
            'topics' => $topics,
        ];
    }

    /**
     * @return array{value: mixed, prompt_result_id: int|null}
     */
    private function runPrompt(
        int $siteId,
        ?int $actorId,
        string $mcpMarkdown,
        string $topicalMapJson,
        string $existingTopicTagsJson,
        string $primaryLanguage,
        string $siteDomain,
        string $periodKey,
        string $companyShortIdentity,
        string $shortDescription,
    ): array {
        $context = array_filter([
            'site_id' => $siteId,
            'actor_id' => $actorId !== null && $actorId > 0 ? $actorId : null,
            'locale' => $primaryLanguage !== '' ? $primaryLanguage : null,
            'site_locale' => $primaryLanguage !== '' ? $primaryLanguage : null,
        ], static fn (mixed $v): bool => $v !== null);

        $input = [
            'mcp_markdown' => $mcpMarkdown,
            'topical_map_json' => $topicalMapJson,
            'existing_topic_tags_json' => $existingTopicTagsJson,
            'primary_language' => $primaryLanguage,
            'site_domain' => $siteDomain,
            'period_key' => $periodKey,
            'company_short_identity' => $companyShortIdentity,
            'short_description' => $shortDescription,
        ];

        $envelope = PromptHookExecutionInput::fromArray([
            'context' => $context,
            'input' => $input,
            'previous_outputs' => [],
            'settings' => [],
        ]);

        $promptResultId = null;
        $legacyVariables = $input;

        $value = $this->promptHookBridge->run(
            hookKey: self::HOOK_KEY,
            version: self::HOOK_VERSION,
            envelope: $envelope,
            legacyExecute: function () use ($legacyVariables, &$promptResultId, $siteId): mixed {
                $promptId = $this->workflowSettings->getBoundPromptId(self::HOOK_KEY);
                if ($promptId === null) {
                    throw new InvalidArgumentException(
                        'Topical Map Audit prompt is not bound. Run seo:prompt:install-default-topical-map-audit or bind it in Settings → Prompt Hooks.',
                    );
                }
                $prompt = SeoPrompt::query()->find($promptId);
                if (! $prompt instanceof SeoPrompt) {
                    throw new InvalidArgumentException('Topical Map Audit prompt record is missing.');
                }

                $result = $this->promptRunner->run($prompt, $legacyVariables);
                $promptResultId = $result->id !== null ? (int) $result->id : null;
                $this->bindPromptResultSite($promptResultId, $siteId);

                return (string) ($result->output_text ?? '');
            },
            mapHookResult: function (PromptHookRuntimeResult $runtimeResult) use (&$promptResultId, $siteId): mixed {
                $metaId = $runtimeResult->meta['prompt_result_id'] ?? null;
                if (is_numeric($metaId) && (int) $metaId > 0) {
                    $promptResultId = (int) $metaId;
                }
                $this->bindPromptResultSite($promptResultId, $siteId);

                return $runtimeResult->output['value'] ?? null;
            },
        );

        $this->bindPromptResultSite($promptResultId, $siteId);

        return [
            'value' => $value,
            'prompt_result_id' => ($promptResultId !== null && $promptResultId > 0) ? $promptResultId : null,
        ];
    }

    /**
     * Keywords workspace: explicit selected language wins when valid for the site.
     * Otherwise preserve Settings/primary resolution (normal sites + Topical Map entry).
     */
    public function resolveEffectivePromptLanguage(Site $site, ?string $languageCode = null): string
    {
        $explicit = trim((string) ($languageCode ?? ''));
        if ($explicit !== '') {
            try {
                $options = $this->primaryLanguage->formLanguageOptions($site);
                if (isset($options[$explicit])) {
                    return $explicit;
                }
            } catch (Throwable) {
                // Fall through to primary.
            }
        }

        try {
            $resolved = $this->primaryLanguage->resolvePrimaryLanguage($site);

            return is_string($resolved) ? trim($resolved) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * PromptRunner historically persists site_id=0. Bind the real site after run so
     * Topics latest-audit reads can scope by site_id without planner-run fallback.
     */
    private function bindPromptResultSite(?int $promptResultId, int $siteId): void
    {
        if ($promptResultId === null || $promptResultId <= 0 || $siteId <= 0) {
            return;
        }

        try {
            SeoPromptResult::query()
                ->whereKey($promptResultId)
                ->where(function ($q): void {
                    $q->where('site_id', 0)->orWhereNull('site_id');
                })
                ->update(['site_id' => $siteId]);
        } catch (Throwable) {
            // Non-fatal: read path still has planner / domain fallbacks.
        }
    }

    /**
     * Prefer PromptRunner's existing PromptResult + user_message over raw AI_ROUTES_EXHAUSTED.
     * One click must still produce exactly one PromptResult (already persisted by PromptRunner).
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   payload: null,
     *   prompt_result_id: int|null,
     *   tag_apply: null
     * }
     */
    private function failFromThrowable(Throwable $e): array
    {
        $promptResultId = null;
        if ($e instanceof PromptRunException) {
            $fromContext = (int) ($e->context['prompt_result_id'] ?? 0);
            if ($fromContext > 0) {
                $promptResultId = $fromContext;
            }
        }

        $detail = $e instanceof PromptRunException
            ? trim($e->userMessage())
            : trim($e->getMessage());
        if ($detail === '') {
            $detail = 'AI routing failed.';
        }

        // Avoid duplicating the technical classification when a primary user message exists.
        if (str_starts_with($detail, 'AI_ROUTES_EXHAUSTED')) {
            $detail = 'all available AI routes failed before a valid audit JSON was produced.';
        }

        return $this->fail('AI Audit failed: '.$detail, $promptResultId);
    }

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   payload: null,
     *   prompt_result_id: int|null,
     *   tag_apply: null
     * }
     */
    private function fail(string $message, ?int $promptResultId = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'payload' => null,
            'prompt_result_id' => $promptResultId,
            'tag_apply' => null,
        ];
    }
}
