<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: backfill migration must be offline (no WP/network) and chunked.
 */
final class ArticleContentTypeBackfillMigrationContractTest extends TestCase
{
    public function test_migration_file_has_no_wordpress_or_http_calls(): void
    {
        $path = dirname(__DIR__, 2)
            .'/database/migrations/2026_08_30_210000_backfill_article_content_type_classification.php';

        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString('Http::', $source);
        self::assertStringNotContainsString("file_get_contents('http", $source);
        self::assertStringNotContainsString('curl_', $source);
        self::assertStringNotContainsString('SyncDomainContent', $source);
        self::assertStringNotContainsString('Http::', $source);
        self::assertDoesNotMatchRegularExpression('/\bHttp::|\bGuzzle|curl_exec/', $source);
        self::assertStringContainsString('CHUNK', $source);
        self::assertStringContainsString('content_type', $source);
        self::assertStringContainsString('wp_is_term', $source);
        self::assertStringContainsString('parent_id', $source);
    }

    public function test_migration_preserves_wp_post_type_and_does_not_delete_article_rows(): void
    {
        $path = dirname(__DIR__, 2)
            .'/database/migrations/2026_08_30_210000_backfill_article_content_type_classification.php';
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString("->table('articles')->delete(", $source);
        self::assertStringContainsString("'wp_post_type'", $source);
        self::assertStringContainsString('Idempotent', $source);
    }
}
