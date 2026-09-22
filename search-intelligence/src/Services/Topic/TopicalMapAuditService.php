<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use App\Models\Site;
use InvalidArgumentException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeResult;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpAiContextBuilder;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;

/**
 * AI Topical Map Audit — Prompt registry + MCP bundle (site + keywords + gsc).
 * Does not create Topics or articles.
 */
final class TopicalMapAuditService
{
    public const HOOK_KEY = DefaultTopicalMapAuditPromptInstaller::HOOK_KEY;

    public const HOOK_VERSION = DefaultTopicalMapAuditPromptInstaller::HOOK_VERSION;

    public function __construct(
        private readonly TopicalMapReadModel $topicalMap,
        private readonly McpAiContextBuilder $mcpContext,
        private readonly McpPeriodService $periods,
        private readonly PromptHookCallerBridge $promptHookBridge,
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SitePrimaryLanguageService $primaryLanguage,
        private readonly TopicalMapAuditResultParser $parser,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   payload: array<string, mixed>|null,
     *   prompt_result_id: int|null
     * }
     */
    public function audit(int $siteId, ?int $actorId = null): array
    {
        if ($siteId <= 0) {
            return $this->fail('Site is required.');
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return $this->fail('Site not found.');
        }

        $period = $this->periods->currentOpenOrLatestFinalized() ?? $this->periods->ensureCurrentMonth();
        $periodKey = $period->periodKey();
        $mcpMarkdown = $this->mcpContext->build($siteId, $periodKey);
        $mapJson = json_encode(
            $this->topicalMap->overview($siteId)->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (! is_string($mapJson) || $mapJson === '') {
            return $this->fail('Failed to encode Topical Map overview.');
        }

        $language = '';
        try {
            $resolved = $this->primaryLanguage->resolvePrimaryLanguage($site);
            $language = is_string($resolved) ? trim($resolved) : '';
        } catch (\Throwable) {
            $language = '';
        }

        try {
            $run = $this->runPrompt(
                siteId: $siteId,
                actorId: $actorId,
                mcpMarkdown: $mcpMarkdown,
                topicalMapJson: $mapJson,
                primaryLanguage: $language,
                siteDomain: (string) ($site->domain ?? ''),
                periodKey: $periodKey,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('Topical Map audit failed: '.$e->getMessage());
        }

        $parsed = $this->parser->parse($run['value']);
        if (! $parsed['ok']) {
            return $this->fail($parsed['message'], $run['prompt_result_id']);
        }

        return [
            'ok' => true,
            'message' => '',
            'payload' => $parsed['payload'],
            'prompt_result_id' => $run['prompt_result_id'],
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
        string $primaryLanguage,
        string $siteDomain,
        string $periodKey,
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
                'mcp_markdown' => $mcpMarkdown,
                'topical_map_json' => $topicalMapJson,
                'primary_language' => $primaryLanguage,
                'site_domain' => $siteDomain,
                'period_key' => $periodKey,
            ],
            'previous_outputs' => [],
            'settings' => [],
        ]);

        $promptResultId = null;
        $legacyVariables = [
            'mcp_markdown' => $mcpMarkdown,
            'topical_map_json' => $topicalMapJson,
            'primary_language' => $primaryLanguage,
            'site_domain' => $siteDomain,
            'period_key' => $periodKey,
        ];

        $value = $this->promptHookBridge->run(
            hookKey: self::HOOK_KEY,
            version: self::HOOK_VERSION,
            envelope: $envelope,
            legacyExecute: function () use ($legacyVariables, &$promptResultId): mixed {
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

    /**
     * @return array{ok: bool, message: string, payload: null, prompt_result_id: int|null}
     */
    private function fail(string $message, ?int $promptResultId = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'payload' => null,
            'prompt_result_id' => $promptResultId,
        ];
    }
}
