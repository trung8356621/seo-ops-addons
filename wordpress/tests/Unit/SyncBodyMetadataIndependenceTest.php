<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

final class SyncBodyMetadataIndependenceTest extends TestCase
{
    public function test_import_path_separates_body_and_metadata_phases(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/SyncDomainContentService.php',
        );

        self::assertStringContainsString('syncBodyChanged', $source);
        self::assertStringContainsString('Phase A', $source);
        self::assertStringContainsString('Phase B', $source);
        self::assertStringContainsString('// Metadata path: WP slug is authoritative on pull', $source);
        self::assertDoesNotMatchRegularExpression(
            '/if \(trim\(\(string\) \(\$article->slug \?\? \'\'\)\) === \'\' \|\| \$forceOverwrite\)/',
            $source,
        );
    }

    public function test_manifest_comparator_fetches_on_wp_post_type_drift(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-foundation/src/Support/DomainSyncManifestComparator.php',
        );

        self::assertStringContainsString('wpPostTypeDrifted', $source);
        self::assertStringContainsString('localByWpId', $source);
    }

    public function test_body_unchanged_detection(): void
    {
        $article = new SeoArticle;
        $article->body = '<p>Same body</p>';

        $service = (new \ReflectionClass(SyncDomainContentService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SyncDomainContentService::class, 'syncBodyChanged');
        $method->setAccessible(true);

        self::assertFalse($method->invoke(
            $service,
            $article,
            ['content_hash' => '', 'post_content' => '<p>Same body</p>'],
            '<p>Same body</p>',
        ));

        self::assertTrue($method->invoke(
            $service,
            $article,
            [],
            '<p>New body</p>',
        ));
    }
}
