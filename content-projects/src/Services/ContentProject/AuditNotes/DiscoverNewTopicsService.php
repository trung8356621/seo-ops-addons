<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

use App\Models\Site;
use InvalidArgumentException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeResult;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultDiscoverNewTopicsPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscape;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;

/**
 * SEO Audit — Discover New Topics (temporary candidates only).
 *
 * Landscape via KeywordLandscapeGateway only. No Topic DB writes. No articles.
 */
final class DiscoverNewTopicsService
{
    public const HOOK_KEY = DefaultDiscoverNewTopicsPromptInstaller::HOOK_KEY;

    public const HOOK_VERSION = DefaultDiscoverNewTopicsPromptInstaller::HOOK_VERSION;

    public const DEFAULT_COUNT = 8;

    public const MAX_COUNT = 20;

    public function __construct(
        private readonly KeywordLandscapeGateway $landscape,
        private readonly DiscoverNewTopicsResultParser $parser,
        private readonly DiscoverNewTopicsDuplicateFilter $duplicateFilter,
        private readonly PromptHookCallerBridge $promptHookBridge,
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SitePrimaryLanguageService $primaryLanguage,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   topics: list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}>,
     *   rejected_count: int,
     *   prompt_result_id: int|null
     * }
     */
    public function discover(int $siteId, ?int $actorId = null, int $count = self::DEFAULT_COUNT): array
    {
        if ($siteId <= 0) {
            return $this->fail('Site is required.');
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return $this->fail('Site not found.');
        }

        $count = max(1, min(self::MAX_COUNT, $count));
        $landscape = $this->landscape->forSite($siteId, true);
        $landscapeJson = $this->encodeLandscape($landscape);
        $language = $this->resolvePrimaryLanguage($site);

        try {
            $run = $this->runPrompt(
                siteId: $siteId,
                actorId: $actorId,
                landscapeJson: $landscapeJson,
                count: $count,
                primaryLanguage: $language,
                siteDomain: (string) ($site->domain ?? ''),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('Discover New Topics failed: '.$e->getMessage());
        }

        $parsed = $this->parser->parse($run['value']);
        $novelty = $this->duplicateFilter->filter($siteId, $parsed['accepted']);
        $rejectedCount = count($parsed['rejected']) + count($novelty['rejected']);

        return [
            'ok' => true,
            'message' => '',
            'topics' => $novelty['accepted'],
            'rejected_count' => $rejectedCount,
            'prompt_result_id' => $run['prompt_result_id'],
        ];
    }

    /**
     * @return array{value: mixed, prompt_result_id: int|null}
     */
    private function runPrompt(
        int $siteId,
        ?int $actorId,
        string $landscapeJson,
        int $count,
        string $primaryLanguage,
        string $siteDomain,
    ): array {
        $context = array_filter([
            'site_id' => $siteId,
            'actor_id' => $actorId !== null && $actorId > 0 ? $actorId : null,
            'locale' => $primaryLanguage !== '' ? $primaryLanguage : null,
            'site_locale' => $primaryLanguage !== '' ? $primaryLanguage : null,
        ], static fn (mixed $v): bool => $v !== null);

        $envelope = PromptHookExecutionInput::fromArray([
            'context' => $context,
            'input' => [
                'landscape_json' => $landscapeJson,
                'count' => $count,
                'primary_language' => $primaryLanguage,
                'site_domain' => $siteDomain,
            ],
            'previous_outputs' => [],
            'settings' => [],
        ]);

        $promptResultId = null;
        $legacyVariables = [
            'landscape_json' => $landscapeJson,
            'count' => $count,
            'primary_language' => $primaryLanguage,
            'site_domain' => $siteDomain,
        ];

        $value = $this->promptHookBridge->run(
            hookKey: self::HOOK_KEY,
            version: self::HOOK_VERSION,
            envelope: $envelope,
            legacyExecute: function () use ($legacyVariables, &$promptResultId): mixed {
                $promptId = $this->workflowSettings->getBoundPromptId(self::HOOK_KEY);
                if ($promptId === null) {
                    throw new InvalidArgumentException(
                        'Discover New Topics prompt is not bound. Run seo:prompt:install-default-discover-new-topics or bind it in Settings → Prompt Hooks.',
                    );
                }
                $prompt = SeoPrompt::query()->find($promptId);
                if (! $prompt instanceof SeoPrompt) {
                    throw new InvalidArgumentException('Discover New Topics prompt record is missing.');
                }

                $result = $this->promptRunner->run($prompt, $legacyVariables);
                $promptResultId = $result->id !== null ? (int) $result->id : null;

                return (string) ($result->output_text ?? '');
            },
            mapHookResult: function (PromptHookRuntimeResult $runtimeResult) use (&$promptResultId): mixed {
                $metaId = $runtimeResult->meta['prompt_result_id'] ?? null;
                if (is_numeric($metaId) && (int) $metaId > 0) {
                    $promptResultId = (int) $metaId;
                }

                return $runtimeResult->output['value'] ?? null;
            },
        );

        return [
            'value' => $value,
            'prompt_result_id' => ($promptResultId !== null && $promptResultId > 0) ? $promptResultId : null,
        ];
    }

    private function encodeLandscape(KeywordLandscape $landscape): string
    {
        $topics = [];
        foreach ($landscape->topics as $topic) {
            $dna = array_map(
                static fn (array $row): string => (string) ($row['phrase'] ?? ''),
                array_slice($topic->dna, 0, 12),
            );
            $dna = array_values(array_filter($dna, static fn (string $p): bool => $p !== ''));
            $topics[] = [
                'id' => $topic->id,
                'name' => $topic->name,
                'mcp' => $topic->mcp,
                'dna_count' => $topic->dnaCount,
                'article_count' => $topic->articleCount,
                'coverage' => $topic->coverage,
                'dna' => $dna,
            ];
        }

        return json_encode(
            [
                'site_id' => $landscape->siteId,
                'topic_count' => $landscape->topicCount(),
                'topics' => $topics,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function resolvePrimaryLanguage(Site $site): string
    {
        try {
            $resolved = $this->primaryLanguage->resolvePrimaryLanguage($site);

            return is_string($resolved) ? trim($resolved) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   topics: list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}>,
     *   rejected_count: int,
     *   prompt_result_id: int|null
     * }
     */
    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'topics' => [],
            'rejected_count' => 0,
            'prompt_result_id' => null,
        ];
    }
}
