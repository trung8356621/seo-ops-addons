<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\Content\Services\CtaAutomation\SemanticCtaPlanClient;
use RuntimeException;
use Tests\TestCase;

final class SemanticCtaPlanClientTest extends TestCase
{
    public function test_missing_route_reports_status_without_response_body(): void
    {
        config([
            'semantic.enabled' => true,
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
        ]);
        Http::fake([
            'semantic.test/v1/cta/plan' => Http::response(
                ['detail' => 'Not Found', 'article' => 'secret body must not leak'],
                404,
                ['X-Request-Id' => 'cta-req-1'],
            ),
        ]);

        try {
            (new SemanticCtaPlanClient())->plan([
                'language' => 'vi',
                'sections' => [],
                'legacy_candidates' => [],
            ]);
            self::fail('Expected semantic plan failure');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            self::assertStringStartsWith('semantic_plan_failed', $message);
            self::assertStringContainsString('HTTP 404', $message);
            self::assertStringContainsString('/v1/cta/plan', $message);
            self::assertStringContainsString('not_found', $message);
            self::assertStringContainsString('id=cta-req-1', $message);
            self::assertStringNotContainsString('secret body', $message);
        }
    }
}
