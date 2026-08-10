<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Support\SeoLinkMapHttpAuditClassifier;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

final class SeoLinkMapHttpAuditClassifierTest extends TestCase
{
    public function test_classifies_404_as_broken(): void
    {
        $this->assertSame(
            SeoLinkMapStatus::Broken,
            SeoLinkMapHttpAuditClassifier::classifyResponse(new Response(new \GuzzleHttp\Psr7\Response(404))),
        );
    }

    public function test_classifies_2xx_as_active(): void
    {
        $this->assertSame(
            SeoLinkMapStatus::Active,
            SeoLinkMapHttpAuditClassifier::classifyResponse(new Response(new \GuzzleHttp\Psr7\Response(200))),
        );
    }

    public function test_classifies_redirect_as_active(): void
    {
        $this->assertSame(
            SeoLinkMapStatus::Active,
            SeoLinkMapHttpAuditClassifier::classifyResponse(new Response(new \GuzzleHttp\Psr7\Response(302))),
        );
    }

    public function test_classifies_server_errors_as_needs_audit(): void
    {
        $this->assertSame(
            SeoLinkMapStatus::NeedsAudit,
            SeoLinkMapHttpAuditClassifier::classifyResponse(new Response(new \GuzzleHttp\Psr7\Response(503))),
        );
    }

    public function test_classifies_client_blocks_as_needs_audit(): void
    {
        $this->assertSame(
            SeoLinkMapStatus::NeedsAudit,
            SeoLinkMapHttpAuditClassifier::classifyResponse(new Response(new \GuzzleHttp\Psr7\Response(403))),
        );
    }
}
