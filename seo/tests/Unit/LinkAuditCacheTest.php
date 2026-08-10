<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkAudit;
use Omnichannel\Addons\Seo\Services\LinkAuditCacheService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class LinkAuditCacheTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_find_fresh_returns_recent_audit(): void
    {
        $this->requireSeoDatabaseConnection();

        $service = app(LinkAuditCacheService::class);
        $url = '/fresh-audit-'.uniqid('', true);

        SeoLinkAudit::query()->create([
            'site_id' => 2,
            'target_url_hash' => $service->hashUrl($url),
            'target_url' => $url,
            'status' => SeoLinkMapStatus::Active,
            'last_http_status' => 200,
            'last_audited_at' => now(),
        ]);

        $audit = $service->findFresh(2, $url);

        $this->assertInstanceOf(SeoLinkAudit::class, $audit);
        $this->assertSame(200, $audit->last_http_status);
    }

    public function test_find_fresh_returns_null_for_stale_audit(): void
    {
        $this->requireSeoDatabaseConnection();

        $service = app(LinkAuditCacheService::class);
        $url = '/stale-audit-'.uniqid('', true);

        SeoLinkAudit::query()->create([
            'site_id' => 2,
            'target_url_hash' => $service->hashUrl($url),
            'target_url' => $url,
            'status' => SeoLinkMapStatus::Active,
            'last_http_status' => 404,
            'last_audited_at' => now()->subDays(3),
        ]);

        $this->assertNull($service->findFresh(2, $url));
    }
}
