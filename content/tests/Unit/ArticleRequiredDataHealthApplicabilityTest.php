<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\Health\ArticleRequiredDataHealthAuditor;
use Omnichannel\Addons\Content\Support\ArticleSeoInventoryPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ArticleRequiredDataHealthApplicabilityTest extends TestCase
{
    private ArticleRequiredDataHealthAuditor $auditor;

    private ReflectionMethod $classify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new ArticleRequiredDataHealthAuditor();
        $this->classify = new ReflectionMethod(ArticleRequiredDataHealthAuditor::class, 'classifyField');
        $this->classify->setAccessible(true);
    }

    public function test_wp_backed_valid_article_all_present(): void
    {
        $row = $this->row([
            'wp_post_id' => 100,
            'title' => 'Hello',
            'slug' => 'hello',
            'status' => 'publish',
            'content_type' => 'post',
            'wp_post_type' => 'post',
            'wp_permalink' => 'https://example.test/hello/',
        ]);

        foreach (['source_id', 'title', 'slug', 'permalink', 'content_type', 'wp_post_type', 'status'] as $key) {
            self::assertSame(
                ArticleRequiredDataHealthAuditor::OUTCOME_PRESENT,
                $this->classify->invoke($this->auditor, $key, $row),
                $key,
            );
        }
    }

    public function test_local_only_article_source_and_permalink_not_applicable(): void
    {
        $row = $this->row([
            'wp_post_id' => null,
            'title' => 'Local draft',
            'slug' => 'local-draft',
            'status' => 'draft',
            'content_type' => 'post',
            'wp_post_type' => null,
            'wp_permalink' => null,
        ]);

        self::assertSame(
            ArticleRequiredDataHealthAuditor::OUTCOME_NOT_APPLICABLE,
            $this->classify->invoke($this->auditor, 'source_id', $row),
        );
        self::assertSame(
            ArticleRequiredDataHealthAuditor::OUTCOME_NOT_APPLICABLE,
            $this->classify->invoke($this->auditor, 'permalink', $row),
        );
        self::assertSame(
            ArticleRequiredDataHealthAuditor::OUTCOME_NOT_APPLICABLE,
            $this->classify->invoke($this->auditor, 'wp_post_type', $row),
        );
    }

    public function test_wp_draft_empty_slug_is_source_absent_expected(): void
    {
        $row = $this->row([
            'wp_post_id' => 55,
            'title' => 'Draft',
            'slug' => null,
            'status' => 'draft',
            'content_type' => 'post',
            'wp_post_type' => 'post',
            'wp_permalink' => null,
        ]);

        self::assertSame(
            ArticleRequiredDataHealthAuditor::OUTCOME_SOURCE_ABSENT,
            $this->classify->invoke($this->auditor, 'slug', $row),
        );
        self::assertSame(
            ArticleRequiredDataHealthAuditor::OUTCOME_SOURCE_ABSENT,
            $this->classify->invoke($this->auditor, 'permalink', $row),
        );
    }

    public function test_wp_publish_missing_permalink_is_true_missing(): void
    {
        $row = $this->row([
            'wp_post_id' => 77,
            'title' => 'Published',
            'slug' => 'published',
            'status' => 'publish',
            'content_type' => 'post',
            'wp_post_type' => 'post',
            'wp_permalink' => null,
        ]);

        self::assertSame(
            ArticleRequiredDataHealthAuditor::OUTCOME_MISSING,
            $this->classify->invoke($this->auditor, 'permalink', $row),
        );
    }

    public function test_blocks_and_terms_excluded_from_seo_inventory_policy(): void
    {
        self::assertFalse(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('blocks', null));
        self::assertFalse(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('wp_template', null));
        self::assertFalse(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('post', '1'));
        self::assertTrue(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('post', null));
        self::assertTrue(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('page', null));
        self::assertTrue(ArticleSeoInventoryPolicy::isSeoInventoryCandidate('product', null));
    }

    public function test_acceptance_script_tracks_and_cleans_fixtures(): void
    {
        $path = dirname(__DIR__, 3).'/../omnichannel-client/_v3_acceptance_site2.php';
        if (! is_file($path)) {
            $path = 'D:/work/omnichannel-client/_v3_acceptance_site2.php';
        }
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString("created_post_ids", $src);
        self::assertStringContainsString('temporary_mutations', $src);
        self::assertStringContainsString('register_shutdown_function($cleanupFixtures)', $src);
        self::assertStringContainsString('$cleanupFixtures()', $src);
        self::assertStringContainsString("\$fixtures['created_post_ids'][] = \$createId", $src);
        self::assertStringContainsString("\$fixtures['created_post_ids'][] = \$traceDelete", $src);
        self::assertStringContainsString("status' => 'trash'", $src);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'article_id' => 1,
            'title' => null,
            'slug' => null,
            'status' => null,
            'wp_post_id' => null,
            'content_type' => null,
            'wp_post_type' => null,
            'wp_is_term' => null,
            'wp_permalink' => null,
        ], $overrides);
    }
}
