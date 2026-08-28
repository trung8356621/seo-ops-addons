<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\Seo\Services\WorkflowKeywordResearchService;
use ReflectionClass;
use Tests\TestCase;

final class KeywordFlatArchitectureTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_RUNTIME_MARKERS = [
        'parent_id',
        '->parent(',
        '->children(',
        "withCount('children'",
        "with('children'",
        'bulk_quick_parent',
        'move_parent',
        'assignParent',
        'attachChild',
        'saveClusterRelationships',
        'TopicClusterBuilderService',
        'TopicClusterMapService',
        'FlattenLegacyKeywordParentHierarchy',
    ];

    /** @var list<string> */
    private const PRODUCTION_PATHS = [
        'search-foundation/src/Models/Keyword.php',
        'search-foundation/src/Services/KeywordPersistenceService.php',
        'search-intelligence/src/Filament/Resources/KeywordResource.php',
        'search-intelligence/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php',
        'seo/src/Services/WorkflowKeywordResearchService.php',
        'agent/src/Automation/Actions/Keyword/SaveKeywordVocabularyAction.php',
        'search-intelligence/src/Support/KeywordIntelligence/KeywordTagResolver.php',
        'search-intelligence/src/Services/KeywordDomainResyncService.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->ensureTables();
    }

    public function test_schema_keywords_table_has_no_parent_id_column(): void
    {
        self::assertFalse(
            Schema::connection('omi_seo_ai')->hasColumn('keywords', 'parent_id'),
            'keywords.parent_id must be dropped by migration 2026_08_27_110000_drop_legacy_keyword_parent_hierarchy',
        );
    }

    public function test_keyword_model_has_no_parent_or_children_relations(): void
    {
        $reflection = new ReflectionClass(Keyword::class);

        self::assertFalse($reflection->hasMethod('parent'));
        self::assertFalse($reflection->hasMethod('children'));
    }

    public function test_keyword_persistence_upsert_has_no_parent_argument(): void
    {
        $reflection = new ReflectionClass(KeywordPersistenceService::class);
        $parameters = $reflection->getMethod('upsert')->getParameters();

        foreach ($parameters as $parameter) {
            self::assertNotSame('parentId', $parameter->getName());
        }
    }

    public function test_workflow_vocabulary_service_exposes_flat_sync_api(): void
    {
        $reflection = new ReflectionClass(WorkflowKeywordResearchService::class);

        self::assertTrue($reflection->hasMethod('syncVocabularyKeywords'));
        self::assertFalse($reflection->hasMethod('syncTopicCluster'));

        $source = (string) file_get_contents(dirname(__DIR__, 3).'/seo/src/Services/WorkflowKeywordResearchService.php');
        self::assertStringContainsString('vocabulary_count', $source);
        self::assertStringNotContainsString('parent_id', $source);
        self::assertStringNotContainsString('children_count', $source);
    }

    public function test_ui_phrase_column_is_flat(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/tables/columns/keyword-item.blade.php');

        self::assertStringContainsString('partials.keyword-item', $blade);
        self::assertStringNotContainsString('toggleParentExpand', $blade);
    }

    public function test_list_keywords_query_is_flat(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');

        self::assertStringContainsString('Flat dictionary', $src);
        self::assertStringNotContainsString('expandedParentIds', $src);
        self::assertStringNotContainsString('move_parent', $src);
        self::assertStringNotContainsString('moveSelectedKeyword', $src);
    }

    public function test_recluster_has_no_parent_hierarchy_dependency(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/ReclusterTopicClustersService.php';
        if (! is_file($path)) {
            self::markTestSkipped('ReclusterTopicClustersService missing');
        }

        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('parent_id', $src);
        self::assertStringNotContainsString('->children()', $src);
    }

    public function test_production_paths_do_not_reintroduce_keyword_hierarchy(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::PRODUCTION_PATHS as $relativePath) {
            $absolutePath = $root.'/'.$relativePath;
            self::assertFileExists($absolutePath, "Missing production path: {$relativePath}");

            $source = (string) file_get_contents($absolutePath);

            foreach (self::FORBIDDEN_RUNTIME_MARKERS as $marker) {
                self::assertStringNotContainsString(
                    $marker,
                    $source,
                    "Forbidden hierarchy marker [{$marker}] found in {$relativePath}",
                );
            }
        }
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
    }
}
