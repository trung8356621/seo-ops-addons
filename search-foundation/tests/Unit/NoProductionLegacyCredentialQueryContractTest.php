<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards: no production runtime may query retired credential tables.
 */
final class NoProductionLegacyCredentialQueryContractTest extends TestCase
{
    public function test_production_php_has_no_legacy_credential_table_queries(): void
    {
        $roots = [
            dirname(__DIR__, 3).'/search-foundation/src',
            dirname(__DIR__, 3).'/seo/src',
            dirname(__DIR__, 3).'/publishing/src',
            dirname(__DIR__, 3).'/content-projects/src',
            dirname(__DIR__, 3).'/agent/src',
            dirname(__DIR__, 3).'/ai-prompt/src',
            dirname(__DIR__, 3).'/seeding/src',
            dirname(__DIR__, 3).'/seo-content-ai-compat',
        ];

        $forbidden = [
            'SeoDatabaseConnection::query(',
            'SeedingDatabaseConnection::query(',
            "DB::table('seo_database_connections'",
            'DB::table("seo_database_connections"',
            "DB::table('seeding_database_connections'",
            'DB::table("seeding_database_connections"',
            "hasTable('seo_database_connections')",
            'hasTable("seo_database_connections")',
            "hasTable('seeding_database_connections')",
            'hasTable("seeding_database_connections")',
        ];

        $hits = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if (str_contains($path, DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $contents = (string) file_get_contents($path);
                foreach ($forbidden as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = $path.' :: '.$needle;
                    }
                }
            }
        }

        self::assertSame([], $hits);
    }

    public function test_content_project_article_job_uses_canonical_bootstrap_only(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/content-projects/src/Jobs/RunContentProjectArticleJob.php',
        );
        self::assertStringContainsString('bootstrapCanonicalSharedConnection', $src);
        self::assertStringNotContainsString('SeoDatabaseConnection::query(', $src);
    }

    public function test_seo_login_resolver_has_no_legacy_query(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo/src/Services/SeoLoginServiceResolver.php',
        );
        self::assertStringContainsString('bootstrapCanonicalSharedConnection', $src);
        self::assertStringNotContainsString('SeoDatabaseConnection::query(', $src);
        self::assertStringContainsString('use_short_url', $src);
    }
}
