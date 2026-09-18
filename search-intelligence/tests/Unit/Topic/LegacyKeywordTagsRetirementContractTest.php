<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards against reintroducing retired keyword_tags vocabulary.
 */
final class LegacyKeywordTagsRetirementContractTest extends TestCase
{
    public function test_topic_ssot_is_seo_topic_tags_model_and_service(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__, 3).'/src/Models/SeoTopicTag.php');
        $service = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicUserTagService.php');

        self::assertStringContainsString("protected \$table = 'seo_topic_tags'", $model);
        self::assertStringNotContainsString('keyword_tags', $model);
        self::assertStringNotContainsString('keyword_tags', $service);
        self::assertTrue(class_exists(SeoTopicTag::class));
        self::assertTrue(class_exists(TopicUserTagService::class));
    }

    public function test_ownership_map_drops_keyword_tags_and_keeps_seo_topic_tags(): void
    {
        $map = (string) file_get_contents(dirname(__DIR__, 4).'/DB_OWNERSHIP_MAP.json');
        $decoded = json_decode($map, true);
        self::assertIsArray($decoded);

        $foundation = $decoded['owners']['search-foundation'] ?? [];
        $intelligence = $decoded['owners']['search-intelligence'] ?? [];

        self::assertNotContains('keyword_tags', $foundation);
        self::assertContains('seo_topic_tags', $intelligence);
        self::assertContains('seo_topic_tag_assignments', $intelligence);
    }

    public function test_production_php_does_not_bind_keyword_tags_table(): void
    {
        $roots = [
            dirname(__DIR__, 4).'/search-foundation/src',
            dirname(__DIR__, 4).'/search-intelligence/src',
            dirname(__DIR__, 4).'/seo/src',
            dirname(__DIR__, 4).'/content/src',
            dirname(__DIR__, 4).'/seo-content-ai-compat',
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
                if (preg_match("/protected\\s+\\\$table\\s*=\\s*['\"]keyword_tags['\"]/", $contents) === 1
                    || preg_match("/->table\\(\\s*['\"]keyword_tags['\"]/", $contents) === 1
                    || preg_match("/hasTable\\(\\s*['\"]keyword_tags['\"]/", $contents) === 1
                ) {
                    // Allow only the forward drop migration (not under src/).
                    $hits[] = $path;
                }
            }
        }

        self::assertSame([], $hits, 'Production code must not query or bind keyword_tags');
    }

    public function test_drop_migration_removes_keyword_tags(): void
    {
        $path = dirname(__DIR__, 3).'/database/migrations/2026_09_18_170000_drop_legacy_keyword_tags_table.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("hasTable('keyword_tags')", $src);
        self::assertStringContainsString("drop('keyword_tags')", $src);
        self::assertStringContainsString("omi_seo_ai", $src);
    }
}
