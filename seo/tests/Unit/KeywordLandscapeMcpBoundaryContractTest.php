<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Services\DomainSeoMcpService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\KeywordMonthlyMcpSource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class KeywordLandscapeMcpBoundaryContractTest extends TestCase
{
    public function test_keyword_monthly_source_uses_read_model_and_v2_schema(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordMonthlyMcpSource::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordLandscapeReadModel', $src);
        self::assertStringContainsString("return 'v2'", $src);
        self::assertStringContainsString('toMcpPayloadParts', $src);
        self::assertStringNotContainsString('cluster context builder retired', $src);
        self::assertStringNotContainsString('emits empty payload', $src);
        self::assertSame('keywords.mcp.v2', McpSourceKey::Keywords->schema());
    }

    public function test_domain_keyword_landscape_uses_read_model_not_empty_landscape(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DomainSeoMcpService::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordLandscapeReadModel', $src);
        self::assertStringContainsString('landscapeReadModel', $src);

        $method = new ReflectionMethod(DomainSeoMcpService::class, 'keywordLandscape');
        $body = $this->methodBody($src, 'keywordLandscape');
        self::assertStringContainsString('landscapeReadModel->forSite', $body);
        self::assertStringNotContainsString('EMPTY_LANDSCAPE', $body);
        self::assertStringContainsString("'topics'", $body);
        self::assertTrue($method->isPrivate());
        self::assertTrue(class_exists(KeywordLandscapeReadModel::class));
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
