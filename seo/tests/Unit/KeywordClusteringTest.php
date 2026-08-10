<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterMutationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterValidator;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterPrimaryKeywordSelector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeywordClusteringTest extends TestCase
{
    public function test_cluster_service_accepts_options_argument(): void
    {
        $ref = new ReflectionClass(KeywordClusterService::class);
        $method = $ref->getMethod('clusterWorkspace');
        self::assertGreaterThanOrEqual(2, $method->getNumberOfParameters());
    }

    public function test_mutation_service_has_merge_split_move(): void
    {
        $ref = new ReflectionClass(KeywordClusterMutationService::class);
        self::assertTrue($ref->hasMethod('previewMerge'));
        self::assertTrue($ref->hasMethod('merge'));
        self::assertTrue($ref->hasMethod('split'));
        self::assertTrue($ref->hasMethod('moveKeywords'));
    }

    public function test_validator_and_primary_selector_exist(): void
    {
        self::assertTrue(class_exists(KeywordClusterValidator::class));
        self::assertTrue(class_exists(ClusterPrimaryKeywordSelector::class));
    }

    public function test_suggested_page_type_helper_not_write_new(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(KeywordClusterService::class))->getFileName(),
        );
        self::assertStringContainsString('local_landing', $source);
        self::assertStringContainsString('landing_page', $source);
        self::assertStringNotContainsString("'suggested_content_type' => 'write_new'", $source);
    }
}
