<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;

/**
 * Site MCP draft storage — Knowledge Profile draft only.
 *
 * Never writes official Site MCP fields.
 */
final class SiteMcpDraft
{
    public const META_KEY = 'site_mcp_draft';

    public const VERSION = 3;

    public const SOURCE = 'site_mcp_generator.v3';

    public const COMPANY_SHORT_IDENTITY_MAX = 80;

    public function __construct(
        private readonly SiteMcpOfficialGuard $guard,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function get(Site $site): ?array
    {
        $decoded = SiteSyncSiteMeta::getJson($site, self::META_KEY);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function put(Site $site, array $draft): void
    {
        $draft['generation']['official_fields_modified'] = false;
        $this->guard->assertDraftPayloadSafe($draft);
        $this->guard->assertMetaKeyAllowed(self::META_KEY);
        $this->guard->runDraftWrite($site, static function () use ($site, $draft): void {
            SiteSyncSiteMeta::putJson($site, self::META_KEY, $draft);
        });
    }

    public function exists(Site $site): bool
    {
        return $this->get($site) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return [
            'site' => [
                'domain' => '',
                'website_type' => 'news',
                'discovery_strategy' => 'news_manual',
                'site_title' => '',
                'brand' => '',
                'company_short_identity' => '',
                'short_description' => '',
            ],
            'content_context' => [
                'tone' => '',
                'business_summary' => '',
                'cta_instructions' => '',
            ],
            'contact' => [
                'phones' => [],
                'emails' => [],
                'socials' => [],
            ],
            'important_pages' => [],
            'discovery_candidates' => [],
            'keyword_context' => [
                'main_topics' => [],
                'main_topic_records' => [],
                'warnings' => [],
            ],
            'counts' => [
                'post' => 0,
                'page' => 0,
                'product' => 0,
                'product_cat' => 0,
                'root_product_cat' => 0,
                'attachment' => 0,
                'product_categories' => 0,
                'products' => 0,
                'service_categories' => 0,
                'excluded' => [
                    'product' => 0,
                ],
            ],
            'generation' => [
                'generated_at' => null,
                'source' => self::SOURCE,
                'sync_run' => null,
                'version' => self::VERSION,
                'official_site_mcp_exists' => false,
                'official_fields_modified' => false,
            ],
        ];
    }
}
