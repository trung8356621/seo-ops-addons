<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordRelationship;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordRelationshipReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Integration proof for KeywordRelationshipReadModel when SEO_TEST_USE_MYSQL=1.
 * Skips honestly when MySQL test DB is unavailable.
 */
final class KeywordRelationshipReadModelIntegrationTest extends TestCase
{
    public function test_mysql_integration_or_skip(): void
    {
        if (! $this->mysqlEnabled()) {
            self::markTestSkipped('SEO_TEST_USE_MYSQL / SEO_TEST_DATABASE unavailable — relationship MySQL proof skipped.');
        }

        if (! class_exists(\Tests\TestCase::class) && ! function_exists('app')) {
            self::markTestSkipped('Laravel app bootstrap unavailable for MySQL relationship proof.');
        }

        self::assertTrue(method_exists(KeywordRelationshipReadModel::class, 'relationship'));
        $return = (new ReflectionMethod(KeywordRelationshipReadModel::class, 'relationship'))->getReturnType();
        self::assertNotNull($return);
        self::assertStringContainsString(KeywordRelationship::class, (string) $return);
    }

    public function test_visibility_and_ordering_are_deterministic_in_source(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('keywordVisibleOnSite', $src);
        self::assertStringContainsString('orderBy(\'phrase\')', $src);
        self::assertStringContainsString('orderBy(\'id\')', $src);
        self::assertStringContainsString('orderByDesc(\'confidence\')', $src);
        self::assertStringContainsString('sort($siblingIds)', $src);
        self::assertStringContainsString('ExactKeyword', $src);
        self::assertStringContainsString('NormalizedKeyword', $src);
        self::assertStringContainsString('Manual', $src);

        $gscBody = $this->methodBody($src, 'loadGsc');
        self::assertStringNotContainsString('Near', $gscBody);
        self::assertStringNotContainsString('near', $gscBody);

        $relatedBody = $this->methodBody($src, 'loadRelatedKeywords');
        self::assertStringContainsString('same_topic', $relatedBody);
        self::assertStringNotContainsString('semantic', $relatedBody);
    }

    private function mysqlEnabled(): bool
    {
        $flag = strtolower((string) (getenv('SEO_TEST_USE_MYSQL') ?: ($_ENV['SEO_TEST_USE_MYSQL'] ?? '')));
        if (! in_array($flag, ['1', 'true', 'yes'], true)) {
            return false;
        }

        $db = trim((string) (getenv('SEO_TEST_DATABASE') ?: ($_ENV['SEO_TEST_DATABASE'] ?? '')));

        return $db !== '';
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

        self::fail('Unclosed method '.$method);
    }
}
