<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersSiteTopics;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\RunsTopicalMapAuditAndTags;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicalMap;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterSiteTopicsJob;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditStatusService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicTagAssignmentSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Manual AI guarantee + Tag provenance + Topical Map v1 closure contracts.
 */
final class TopicalMapV1ClosureContractTest extends TestCase
{
    public function test_recluster_never_calls_audit_or_prompt(): void
    {
        $reclusterTrait = (string) file_get_contents(
            (string) (new ReflectionClass(ReclustersSiteTopics::class))->getFileName()
        );
        $job = (string) file_get_contents(
            (string) (new ReflectionClass(ReclusterSiteTopicsJob::class))->getFileName()
        );
        $service = (string) file_get_contents(
            (string) (new ReflectionClass(TopicReclusterService::class))->getFileName()
        );

        foreach ([$reclusterTrait, $job, $service] as $src) {
            self::assertStringNotContainsString('TopicalMapAuditService', $src);
            self::assertStringNotContainsString('PromptHookCallerBridge', $src);
            self::assertStringNotContainsString('PromptRunnerService', $src);
            self::assertStringNotContainsString('syncAiSuggestions', $src);
            self::assertStringNotContainsString('seo_keywords.topical_map_audit', $src);
        }
    }

    public function test_ai_action_requires_confirmation_before_execution(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(RunsTopicalMapAuditAndTags::class))->getFileName()
        );
        self::assertStringContainsString('confirmAiAudit', $trait);
        self::assertStringContainsString('beginConfirmAiAudit', $trait);
        self::assertStringContainsString('cancelConfirmAiAudit', $trait);
        self::assertStringContainsString('confirmRunAiAuditAndTags', $trait);
        self::assertStringContainsString('TopicalMapAuditService', $trait);

        // beginConfirm must not call audit()
        $begin = $this->extractMethodBody($trait, 'beginConfirmAiAudit');
        self::assertStringNotContainsString('->audit(', $begin);
        self::assertStringContainsString('confirmAiAudit = true', $begin);

        $cancel = $this->extractMethodBody($trait, 'cancelConfirmAiAudit');
        self::assertStringNotContainsString('->audit(', $cancel);

        $confirm = $this->extractMethodBody($trait, 'confirmRunAiAuditAndTags');
        self::assertStringContainsString('->audit(', $confirm);
        self::assertSame(1, substr_count($confirm, '->audit('));
    }

    public function test_topics_page_wires_ai_button_beside_recluster(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordTopicClusters::class))->getFileName()
        );
        self::assertStringContainsString('RunsTopicalMapAuditAndTags', $page);

        $blade = dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php';
        $bladeSrc = (string) file_get_contents($blade);
        self::assertStringContainsString('beginConfirmAiAudit', $bladeSrc);
        self::assertStringContainsString('confirmRunAiAuditAndTags', $bladeSrc);
        self::assertStringContainsString('aiAuditButtonLabel', $bladeSrc);
        self::assertStringContainsString('topic-ai-audit-modal', $bladeSrc);
        self::assertStringContainsString('beginConfirmRecluster', $bladeSrc);
    }

    public function test_zero_topics_and_recluster_running_disable_ai(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(RunsTopicalMapAuditAndTags::class))->getFileName()
        );
        self::assertStringContainsString("snapshot['can_run']", $trait);
        self::assertStringContainsString('isTopicMutationLocked', $trait);

        $status = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditStatusService::class))->getFileName()
        );
        self::assertStringContainsString("'can_run' => \$topicCount > 0", $status);
        self::assertStringContainsString('STATUS_NEVER_RUN', $status);
        self::assertStringContainsString('STATUS_CURRENT', $status);
        self::assertStringContainsString('STATUS_STALE', $status);
        self::assertStringContainsString("'ai_history_url'", $status);
        self::assertStringContainsString('resolveAiHistoryUrl', $status);
        self::assertStringContainsString('TopicalMapAuditHistoryLinker', $status);
        self::assertStringNotContainsString('ensureSharedDraft', $status);
    }

    public function test_tag_provenance_migration_and_constants(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_09_24_100000_add_source_to_seo_topic_tag_assignments.php'
        );
        self::assertStringContainsString("'source'", $migration);
        self::assertStringContainsString("'manual'", $migration);
        self::assertStringContainsString('seo_topic_tag_assignments', $migration);

        self::assertSame('manual', TopicTagAssignmentSource::MANUAL);
        self::assertSame('ai', TopicTagAssignmentSource::AI);
        self::assertSame('manual', TopicTagAssignmentSource::normalize(null));
        self::assertSame('ai', TopicTagAssignmentSource::normalize('AI'));
    }

    public function test_manual_attach_promotes_ai_and_sync_preserves_manual(): void
    {
        $service = (string) file_get_contents(
            (string) (new ReflectionClass(TopicUserTagService::class))->getFileName()
        );
        self::assertStringContainsString('attachManualPair', $service);
        self::assertStringContainsString('TopicTagAssignmentSource::MANUAL', $service);
        self::assertStringContainsString('TopicTagAssignmentSource::AI', $service);
        self::assertStringContainsString('syncAiSuggestions', $service);
        self::assertStringContainsString('isAi', $service);
        self::assertMatchesRegularExpression('/promote|MANUAL/', $service);

        // Sync removes obsolete AI only.
        self::assertStringContainsString('source !== TopicTagAssignmentSource::AI', $service);
        self::assertStringContainsString('DB::connection', $service);
        self::assertStringContainsString('transaction', $service);
    }

    public function test_full_viewport_removes_sidebar_and_fixed_height(): void
    {
        $reactCss = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/topical-map/styles/topical-map-app.css');
        self::assertStringNotContainsString('height: 560px', $reactCss);
        self::assertStringNotContainsString('min-height: 560px', $reactCss);
        self::assertStringContainsString('100dvh', $reactCss);
        self::assertStringNotContainsString('tm-sidebar', $reactCss);

        $canvas = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/topical-map/components/ChartCanvas.jsx');
        self::assertStringContainsString('ResizeObserver', $canvas);
        self::assertStringContainsString('passive: false', $canvas);
        self::assertStringContainsString("window.open(url, '_blank', 'noopener,noreferrer')", $canvas);

        $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/topical-map-chart.js');
        self::assertStringContainsString('retired', strtolower($js));
    }

    public function test_tag_filter_or_semantics_in_read_model(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicalMapReadModel.php'
        );
        self::assertStringContainsString('filterTopicsByTags', $src);
        self::assertStringContainsString('tagFacets', $src);
        self::assertStringContainsString('untaggedCount', $src);
        self::assertStringContainsString("'tags' => \$topicTags", $src);

        $filters = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/topical-map/state/filters.js');
        self::assertStringContainsString('showUntagged', $filters);
        self::assertStringContainsString('selectedTagIds', $filters);
        self::assertStringContainsString('selected.includes', $filters);

        $page = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordTopicalMap::class))->getFileName()
        );
        self::assertStringContainsString('TopicalMapAppPage::appUrl', $page);
    }

    public function test_audit_service_applies_tags_after_parse_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditService::class))->getFileName()
        );
        self::assertStringContainsString('existing_topic_tags_json', $src);
        self::assertStringContainsString('syncAiSuggestions', $src);
        self::assertStringContainsString('tag_apply', $src);
        self::assertStringContainsString('0.3.0', TopicalMapAuditService::HOOK_VERSION);
        self::assertStringNotContainsString('ReclusterSiteTopicsJob', $src);
        self::assertStringNotContainsString('TopicReclusterService', $src);
    }

    private function extractMethodBody(string $src, string $method): string
    {
        if (! preg_match('/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            self::fail("Method {$method} not found");
        }
        $start = (int) $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $len = strlen($src);
        for ($i = $start; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }
}
