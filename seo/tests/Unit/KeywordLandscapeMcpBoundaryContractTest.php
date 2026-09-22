<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Services\DomainSeoMcpService;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\KeywordMonthlyMcpSource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class KeywordLandscapeMcpBoundaryContractTest extends TestCase
{
    public function test_snapshot_source_is_keywords_and_schema_version_is_v2(): void
    {
        self::assertSame('keywords', McpSourceKey::Keywords->value);
        self::assertNotSame('keywords.mcp.v2', McpSourceKey::Keywords->value);
        self::assertSame('keywords.mcp.v2', McpSourceKey::Keywords->schema());

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordMonthlyMcpSource::class))->getFileName(),
        );
        self::assertStringContainsString("return 'v2'", $src);
        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $src);
        self::assertStringNotContainsString('cluster context builder retired', $src);
        self::assertStringNotContainsString('emits empty payload', $src);
    }

    public function test_domain_keyword_landscape_uses_gateway_not_read_model(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DomainSeoMcpService::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringContainsString('landscapeGateway', $src);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $src);

        $body = $this->methodBody($src, 'keywordLandscape');
        self::assertStringContainsString('landscapeGateway->forSite', $body);
        self::assertStringNotContainsString('EMPTY_LANDSCAPE', $body);
        self::assertStringContainsString("'topics'", $body);
        self::assertTrue((new ReflectionMethod(DomainSeoMcpService::class, 'keywordLandscape'))->isPrivate());
        self::assertTrue(class_exists(KeywordLandscapeGateway::class));
        self::assertTrue(class_exists(KeywordLandscapeReadModel::class));
    }

    public function test_gateway_documents_three_approved_consumers_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLandscapeGateway::class))->getFileName(),
        );
        self::assertStringContainsString('SEO Audit', $src);
        self::assertStringContainsString('Prompt Generator', $src);
        self::assertStringContainsString('Topical Map', $src);
        self::assertStringContainsString('domain.keyword_landscape', $src);
        self::assertStringContainsString('Not a general-purpose', $src);
        self::assertStringContainsString('KeywordLandscapeReadModel', $src);
        self::assertSame(KeywordLandscapeReadModel::DNA_LIMIT, KeywordLandscapeGateway::DNA_LIMIT);
    }

    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^{]+)?\{/';
        if (! preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            self::fail('Method '.$method.' not found');
        }
        $start = (int) $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($src);
        for ($i = $start; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        return '';
    }
}
