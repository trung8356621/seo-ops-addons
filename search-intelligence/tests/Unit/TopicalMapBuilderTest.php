<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\TopicalMapBuildRequest;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterContentActionResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalMapBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TopicalMapBuilderTest extends TestCase
{
    public function test_build_request_modes_exist(): void
    {
        self::assertSame('conservative', TopicalMapBuildRequest::MODE_CONSERVATIVE);
        self::assertSame('balanced', TopicalMapBuildRequest::MODE_BALANCED);
        self::assertSame('expansive', TopicalMapBuildRequest::MODE_EXPANSIVE);
    }

    public function test_builder_does_not_reanalyze_keywords(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(TopicalMapBuilder::class))->getFileName()
        );
        self::assertStringContainsString('KHÔNG re-run keyword', $src);
        self::assertStringNotContainsString('KeywordWorkspaceAnalysisService', $src);
        self::assertStringNotContainsString('KeywordIntentClassifier', $src);
    }

    public function test_action_resolver_write_new_when_no_mapping_landing_page_not_rewrite(): void
    {
        $resolver = new KeywordClusterContentActionResolver;
        $cluster = new SeoKeywordCluster([
            'status' => KeywordClusterStatus::Approved->value,
            'target_article_ref' => null,
            'suggested_content_type' => 'landing_page',
            'primary_keyword_id' => 1,
            'name' => 'SEO dich vu',
            'metadata' => [],
        ]);

        $result = $resolver->resolve($cluster);
        self::assertSame('write_new', $result['action']);
    }

    public function test_improve_requires_description(): void
    {
        $resolver = new KeywordClusterContentActionResolver;
        $cluster = new SeoKeywordCluster([
            'status' => KeywordClusterStatus::Approved->value,
            'target_article_ref' => 'art_1',
            'suggested_content_type' => 'article',
            'primary_keyword_id' => 1,
            'name' => 'SEO',
            'suggested_description' => '',
            'metadata' => [
                'reviewed_action' => 'improve',
                'improve_description' => '',
            ],
        ]);

        $result = $resolver->resolve($cluster);
        self::assertSame('needs_review', $result['action']);
        self::assertContains('improve_description_required', $result['reason_codes']);
    }
}
