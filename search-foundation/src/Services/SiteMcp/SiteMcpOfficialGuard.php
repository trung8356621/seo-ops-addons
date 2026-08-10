<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;
use RuntimeException;

/**
 * Service-level guard: Site MCP generator may only write site_mcp_draft.
 *
 * Never updates official Knowledge Profile fields.
 */
final class SiteMcpOfficialGuard
{
    /** @var list<string> */
    public const FORBIDDEN_META_KEYS = [
        SiteDomainPromptContextService::META_KEY,
        'seo_domain_type',
        'seo_site_title',
        'seo_brand_name',
    ];

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    /**
     * Snapshot official payload + forbidden metas, run writer, assert unchanged.
     *
     * @param  callable(): void  $writer
     */
    public function runDraftWrite(Site $site, callable $writer): void
    {
        $beforeOfficial = $this->promptContext->getRawPayloadForSite($site);
        $beforeMetas = $this->snapshotForbiddenMetas($site);

        $writer();

        $afterOfficial = $this->promptContext->getRawPayloadForSite($site);
        $afterMetas = $this->snapshotForbiddenMetas($site);

        if ($this->normalize($beforeOfficial) !== $this->normalize($afterOfficial)) {
            throw new RuntimeException(
                'Site MCP guard: official seo_domain_prompt_context was modified during draft write.'
            );
        }

        foreach (self::FORBIDDEN_META_KEYS as $key) {
            if (($beforeMetas[$key] ?? null) !== ($afterMetas[$key] ?? null)) {
                throw new RuntimeException(
                    'Site MCP guard: forbidden meta "'.$key.'" was modified during draft write.'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function assertDraftPayloadSafe(array $draft): void
    {
        if (($draft['generation']['official_fields_modified'] ?? null) !== false) {
            throw new RuntimeException(
                'Site MCP guard: generation.official_fields_modified must be false.'
            );
        }
    }

    public function assertMetaKeyAllowed(string $metaKey): void
    {
        if ($metaKey !== SiteMcpDraft::META_KEY) {
            throw new RuntimeException(
                'Site MCP guard: only meta key "'.SiteMcpDraft::META_KEY.'" may be written.'
            );
        }

        if (in_array($metaKey, self::FORBIDDEN_META_KEYS, true)) {
            throw new RuntimeException('Site MCP guard: forbidden meta key.');
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshotForbiddenMetas(Site $site): array
    {
        $out = [];
        foreach (self::FORBIDDEN_META_KEYS as $key) {
            $out[$key] = $site->getMeta($key);
        }

        // Also snapshot draft key presence separately — allowed to change.
        unset($out[SiteMcpDraft::META_KEY]);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalize(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Helper used by tests: confirm draft put path uses only site_mcp_draft.
     */
    public function writeDraftOnly(Site $site, array $draft): void
    {
        $this->assertDraftPayloadSafe($draft);
        $this->assertMetaKeyAllowed(SiteMcpDraft::META_KEY);
        $this->runDraftWrite($site, static function () use ($site, $draft): void {
            SiteSyncSiteMeta::putJson($site, SiteMcpDraft::META_KEY, $draft);
        });
    }
}
