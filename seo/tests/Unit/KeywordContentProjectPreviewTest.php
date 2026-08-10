<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterContentActionResolver;
use PHPUnit\Framework\TestCase;

final class KeywordContentProjectPreviewTest extends TestCase
{
    public function test_write_new_when_no_mapping(): void
    {
        $resolver = new KeywordClusterContentActionResolver;
        $cluster = new SeoKeywordCluster([
            'status' => KeywordClusterStatus::Approved->value,
            'target_article_ref' => null,
            'suggested_content_type' => 'article',
            'primary_keyword_id' => 5,
            'name' => 'seo audit',
            'metadata' => [],
        ]);

        $result = $resolver->resolve($cluster);
        self::assertSame('write_new', $result['action']);
    }

    public function test_rewrite_only_with_evidence(): void
    {
        $resolver = new KeywordClusterContentActionResolver;
        $withEvidence = new SeoKeywordCluster([
            'status' => KeywordClusterStatus::Approved->value,
            'target_article_ref' => 'art_1',
            'suggested_content_type' => 'rewrite',
            'primary_keyword_id' => 5,
            'name' => 'seo',
            'metadata' => ['reviewed_action' => 'rewrite'],
        ]);
        $without = new SeoKeywordCluster([
            'status' => KeywordClusterStatus::Approved->value,
            'target_article_ref' => 'art_1',
            'suggested_content_type' => 'article',
            'primary_keyword_id' => 5,
            'name' => 'seo',
            'metadata' => ['mapping_confidence' => 0.9, 'mapping_status' => 'approved'],
        ]);

        self::assertSame('rewrite', $resolver->resolve($withEvidence)['action']);
        self::assertSame('covered', $resolver->resolve($without)['action']);
    }

    public function test_unapproved_cluster_blocked(): void
    {
        $resolver = new KeywordClusterContentActionResolver;
        $cluster = new SeoKeywordCluster([
            'status' => KeywordClusterStatus::Draft->value,
            'primary_keyword_id' => 1,
            'name' => 'x',
            'metadata' => [],
        ]);

        self::assertSame('blocked', $resolver->resolve($cluster)['action']);
    }
}
