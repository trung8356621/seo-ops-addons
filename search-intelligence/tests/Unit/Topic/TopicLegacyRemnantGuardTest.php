<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use PHPUnit\Framework\TestCase;

/**
 * Grep-style guard: runtime Topic Core must not resurrect cluster_key architecture.
 */
final class TopicLegacyRemnantGuardTest extends TestCase
{
    public function test_topic_runtime_services_have_no_cluster_key(): void
    {
        $root = dirname(__DIR__, 3).'/src/Services/Topic';
        $files = $this->phpFiles($root);
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('cluster_key', $src, basename($file));
            self::assertStringNotContainsString('SeoKeywordClassification', $src, basename($file));
            self::assertStringNotContainsString('seo_keyword_classifications', $src, basename($file));
            self::assertStringNotContainsString('SeoKeywordDna', $src, basename($file));
            self::assertStringNotContainsString('seo_keyword_dna', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_meta', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_aliases', $src, basename($file));
            self::assertStringNotContainsString('legacyClusterKeyToTopicId', $src, basename($file));
            self::assertStringNotContainsString('TopicCompatibilityResolver', $src, basename($file));
            self::assertStringNotContainsString('KeywordClusterService', $src, basename($file));
        }
    }

    public function test_restored_topic_ui_pages_have_no_cluster_key(): void
    {
        $files = [
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php',
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file);
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('cluster_key', $src, basename($file));
            self::assertStringNotContainsString('KeywordClusterService', $src, basename($file));
        }
    }

    public function test_topic_models_have_no_cluster_key(): void
    {
        foreach (['SeoSiteKeyword', 'SeoTopic', 'SeoTopicKeyword', 'SeoTopicKeywordDna'] as $class) {
            $path = dirname(__DIR__, 3).'/src/Models/'.$class.'.php';
            self::assertFileExists($path);
            $src = (string) file_get_contents($path);
            self::assertStringNotContainsString('cluster_key', $src, $class);
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
