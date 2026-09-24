<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ExcludesTopicsFromMcp;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordRelationshipReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMcpExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\KeywordMonthlyMcpSource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Topic-level MCP quarantine contracts (seo_topics.mcp_excluded).
 */
final class TopicMcpExclusionContractTest extends TestCase
{
    public function test_storage_default_and_service_api(): void
    {
        $model = (string) file_get_contents((string) (new ReflectionClass(SeoTopic::class))->getFileName());
        self::assertStringContainsString("'mcp_excluded'", $model);
        self::assertStringContainsString("'mcp_excluded' => 'boolean'", $model);

        $migration = dirname(__DIR__, 3).'/database/migrations/2026_09_24_120000_add_mcp_excluded_to_seo_topics.php';
        self::assertFileExists($migration);
        $migSrc = (string) file_get_contents($migration);
        self::assertStringContainsString("boolean('mcp_excluded')->default(false)", $migSrc);

        $ref = new ReflectionClass(TopicMcpExclusionService::class);
        self::assertTrue($ref->hasMethod('exclude'));
        self::assertTrue($ref->hasMethod('restore'));
        self::assertTrue($ref->hasMethod('isExcluded'));
        self::assertTrue($ref->hasMethod('excludedTopicIdMap'));

        $src = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString("where('site_id', \$siteId)", $src);
        self::assertStringContainsString('topic_not_found', $src);
        self::assertStringNotContainsString('PromptRunner', $src);
        self::assertStringNotContainsString('TopicDissolveService', $src);
        self::assertStringNotContainsString('KeywordMetaKey::McpExcluded', $src);
    }

    public function test_keyword_level_flag_remains_independent(): void
    {
        self::assertSame('mcp_keyword_excluded', KeywordMetaKey::McpExcluded->value);
        self::assertTrue(class_exists(SkipKeywordFromMcpService::class));
        self::assertTrue(class_exists(TopicMcpExclusionService::class));
        self::assertNotSame(SkipKeywordFromMcpService::class, TopicMcpExclusionService::class);
    }

    public function test_landscape_omits_entire_excluded_topic(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLandscapeReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('TopicMcpExclusionService', $src);
        self::assertStringContainsString("where('mcp_excluded', false)", $src);
        self::assertStringContainsString('SkipKeywordFromMcpService', $src);
        self::assertStringContainsString('Eligibility = Topic NOT mcp_excluded', $src);
    }

    public function test_gateway_monthly_audit_discover_inherit_landscape(): void
    {
        $gateway = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLandscapeGateway::class))->getFileName(),
        );
        $monthly = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordMonthlyMcpSource::class))->getFileName(),
        );
        $audit = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNoteClusterSuggestionQuery::class))->getFileName(),
        );
        $discover = (string) file_get_contents(
            (string) (new ReflectionClass(DiscoverNewTopicsService::class))->getFileName(),
        );

        self::assertStringContainsString('readModel->forSite', $gateway);
        self::assertStringContainsString('landscape->forSite', $monthly);
        self::assertStringContainsString('landscape->forSite', $audit);
        self::assertStringContainsString('landscape->forSite', $discover);
    }

    public function test_topical_map_and_audit_use_filtered_overview(): void
    {
        $map = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapReadModel::class))->getFileName(),
        );
        $audit = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditService::class))->getFileName(),
        );

        self::assertStringContainsString('landscape->forSite', $map);
        self::assertStringContainsString('buildMcpEligibleTagFacets', $map);
        self::assertStringContainsString('topicalMap->overview', $audit);
    }

    public function test_topic_list_keeps_excluded_topics_for_raw_management(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicListQuery::class))->getFileName(),
        );

        self::assertStringContainsString('Lists ALL Topics including mcp_excluded', $src);
        self::assertStringContainsString("'mcp_excluded'", $src);
        self::assertStringContainsString('excludedTopicIdMap', $src);
        self::assertStringContainsString('mcpEligibleTopicIds', $src);
    }

    public function test_relationship_exposes_parent_topic_mcp_excluded(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'mcp_excluded' => \$topicMcpExcluded", $src);
        self::assertStringContainsString('TopicMcpExclusionService', $src);
    }

    public function test_recluster_preserves_mcp_excluded_topic_ids(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicReclusterService::class))->getFileName(),
        );

        self::assertStringContainsString('TopicMcpExclusionService::columnReady', $src);
        self::assertStringContainsString("where('mcp_excluded', true)", $src);
        self::assertStringContainsString('preservedTopicIds', $src);
    }

    public function test_topics_menu_wires_exclude_restore_without_badge(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordTopicClusters::class))->getFileName(),
        );
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(ExcludesTopicsFromMcp::class))->getFileName(),
        );
        $menu = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/topic-row-actions-menu.blade.php',
        );
        $index = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        );

        self::assertStringContainsString('ExcludesTopicsFromMcp', $page);
        self::assertStringContainsString('excludeTopicFromMcp', $trait);
        self::assertStringContainsString('restoreTopicMcp', $trait);
        self::assertStringContainsString('TopicMcpExclusionService', $trait);
        self::assertStringNotContainsString('PromptRunner', $trait);

        self::assertStringContainsString('topic_mcp_exclude_action', $menu);
        self::assertStringContainsString('topic_mcp_restore_action', $menu);
        self::assertStringContainsString('wire:confirm', $menu);
        self::assertStringContainsString('excludeTopicFromMcp', $menu);
        self::assertStringContainsString('restoreTopicMcp', $menu);
        self::assertStringNotContainsString('MCP Excluded', $menu);
        self::assertStringNotContainsString('badge', strtolower($menu));

        self::assertStringContainsString('mcpExcluded', $index);
        self::assertStringContainsString('canMutateMcp', $index);
    }

    public function test_topics_index_shows_mcp_excluded_badge_only_when_excluded(): void
    {
        $index = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        );

        self::assertStringContainsString('$isMcpExcluded = (bool) ($row[\'mcp_excluded\'] ?? false)', $index);
        self::assertStringContainsString('@if ($isMcpExcluded)', $index);
        self::assertStringContainsString('keyword_item_tag_mcp_skipped', $index);
        self::assertStringContainsString('cluster-tag cluster-tag--planned', $index);
        // Presentation only — mutate still via menu actions, not a direct badge button.
        self::assertStringNotContainsString('wire:click="excludeTopicFromMcp', $index);
        self::assertStringNotContainsString('wire:click="restoreTopicMcp', $index);
        // Badge is gated; included Topics render no MCP badge markup without the flag.
        self::assertSame(2, substr_count($index, '@if ($isMcpExcluded)'));
    }

    public function test_exclude_action_does_not_call_ai_or_recluster(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(ExcludesTopicsFromMcp::class))->getFileName(),
        );
        $method = $this->methodBody($trait, 'mutateTopicMcpExclusion');

        self::assertStringContainsString('TopicMcpExclusionService', $method);
        self::assertStringContainsString('TopicReclusterUiState::isMutationLocked', $method);
        self::assertStringNotContainsString('PromptRunner', $method);
        self::assertStringNotContainsString('TopicDissolveService', $method);
        self::assertStringNotContainsString('TopicReclusterService', $method);
        self::assertTrue((new ReflectionMethod(ExcludesTopicsFromMcp::class, 'excludeTopicFromMcp'))->isPublic());
    }

    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^{]+)?\{/';
        if (! preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            self::fail('Method '.$method.' not found');
        }
        $start = (int) $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($src);
        for ($i = $start; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        return '';
    }
}
