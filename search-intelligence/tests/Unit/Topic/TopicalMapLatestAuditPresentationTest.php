<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\RunsTopicalMapAuditAndTags;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\ReclustersSiteTopics;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditContracts;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditResultParser;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditStatusService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapLatestAuditReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TopicalMapLatestAuditPresentationTest extends TestCase
{
    public function test_read_model_exists_and_is_read_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapLatestAuditReadModel::class))->getFileName()
        );
        self::assertStringContainsString('latestSuccessfulAudit', $src);
        self::assertStringContainsString('output_text', $src);
        self::assertStringContainsString('loadPayloadSoft', $src);
        self::assertStringContainsString('mapFindings', $src);
        self::assertStringContainsString('resolveAiHistoryUrl', $src);
        self::assertStringNotContainsString('PromptHookCallerBridge', $src);
        self::assertStringNotContainsString('Schema::create', $src);
    }

    public function test_map_findings_by_exact_topic_ref_and_site_wide(): void
    {
        $model = (new ReflectionClass(TopicalMapLatestAuditReadModel::class))->newInstanceWithoutConstructor();
        $findings = [
            [
                'type' => 'weak_coverage',
                'severity' => 'medium',
                'topic_ref' => 'topic:12',
                'title' => 'A',
                'observation' => 'Topic 12 medium',
            ],
            [
                'type' => 'internal_link_gap',
                'severity' => 'high',
                'topic_ref' => 'topic:12',
                'title' => 'B',
                'observation' => 'Topic 12 high',
            ],
            [
                'type' => 'data_quality',
                'severity' => 'low',
                'topic_ref' => null,
                'title' => 'GSC',
                'observation' => 'GSC unavailable',
            ],
            [
                'type' => 'coverage_gap',
                'severity' => 'high',
                'topic_ref' => 'topic:999',
                'title' => 'Stale',
                'observation' => 'Deleted topic',
            ],
            [
                'type' => 'potential_overlap',
                'severity' => 'low',
                'topic_ref' => 'topic:5',
                'title' => 'Other',
                'observation' => 'Topic 5 only',
            ],
        ];

        $mapped = $model->mapFindings($findings, [12 => true, 5 => true]);

        self::assertArrayHasKey(12, $mapped['by_topic']);
        self::assertArrayHasKey(5, $mapped['by_topic']);
        self::assertArrayNotHasKey(999, $mapped['by_topic']);
        self::assertCount(2, $mapped['by_topic'][12]);
        self::assertCount(1, $mapped['by_topic'][5]);
        self::assertCount(2, $mapped['site_wide']);

        // high before medium for topic 12; original order within severity preserved.
        self::assertSame('high', $mapped['by_topic'][12][0]['severity']);
        self::assertSame('Topic 12 high', $mapped['by_topic'][12][0]['observation']);
        self::assertSame('medium', $mapped['by_topic'][12][1]['severity']);
    }

    public function test_severity_priority_high_over_medium_over_low(): void
    {
        $model = (new ReflectionClass(TopicalMapLatestAuditReadModel::class))->newInstanceWithoutConstructor();

        self::assertSame('high', $model->highestSeverity([
            ['severity' => 'low'],
            ['severity' => 'medium'],
            ['severity' => 'high'],
        ]));
        self::assertSame('medium', $model->highestSeverity([
            ['severity' => 'low'],
            ['severity' => 'medium'],
        ]));
        self::assertSame('low', $model->highestSeverity([
            ['severity' => 'low'],
        ]));
        self::assertNull($model->highestSeverity([]));
    }

    public function test_sort_keeps_stable_order_within_same_severity(): void
    {
        $model = (new ReflectionClass(TopicalMapLatestAuditReadModel::class))->newInstanceWithoutConstructor();
        $sorted = $model->sortFindingsBySeverity([
            ['severity' => 'medium', 'title' => 'first', '_order' => 0],
            ['severity' => 'high', 'title' => 'urgent', '_order' => 1],
            ['severity' => 'medium', 'title' => 'second', '_order' => 2],
            ['severity' => 'high', 'title' => 'also-urgent', '_order' => 3],
        ]);

        self::assertSame(['urgent', 'also-urgent', 'first', 'second'], array_column($sorted, 'title'));
    }

    public function test_parser_soft_path_keeps_valid_refs_when_allowed_empty(): void
    {
        $parser = new TopicalMapAuditResultParser;
        $result = $parser->parse([
            'summary' => 'ok',
            'findings' => [[
                'type' => 'weak_coverage',
                'severity' => 'high',
                'topic_ref' => 'topic:42',
                'title' => 'T',
                'observation' => 'Obs',
            ]],
            'opportunities' => [],
            'recommended_actions' => [],
        ], []);

        self::assertTrue($result['ok']);
        self::assertSame('topic:42', $result['payload']['findings'][0]['topic_ref']);
        self::assertSame(42, TopicalMapAuditContracts::topicIdFromRef('topic:42'));
    }

    public function test_topics_page_wires_latest_audit_helpers_and_history_link(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(RunsTopicalMapAuditAndTags::class))->getFileName()
        );
        self::assertStringContainsString('TopicalMapLatestAuditReadModel', $trait);
        self::assertStringContainsString('latestAiAudit', $trait);
        self::assertStringContainsString('aiFindingsForTopic', $trait);
        self::assertStringContainsString('aiSeverityForTopic', $trait);
        self::assertStringContainsString('aiSiteWideFindings', $trait);
        self::assertStringContainsString('aiHistoryUrl', $trait);
        self::assertStringContainsString('latestAiAuditPresentation = null', $trait);

        $page = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordTopicClusters::class))->getFileName()
        );
        self::assertStringContainsString('refreshAiAuditSnapshot', $page);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('cluster-index-row--ai-', $blade);
        self::assertStringContainsString('cluster-index-row__ai-note', $blade);
        self::assertStringContainsString('topic_ai_note_more', $blade);
        self::assertStringContainsString('topic-ai-audit-summary', $blade);
        self::assertStringContainsString('topic-ai-site-notes', $blade);
        self::assertStringContainsString('topic_ai_history_link', $blade);
        self::assertStringContainsString('aiHistoryUrl', $blade);
        self::assertStringContainsString('target="_blank"', $blade);
        self::assertStringContainsString('rel="noopener noreferrer"', $blade);
        self::assertStringContainsString('aiFindingsForTopic', $blade);
        self::assertStringContainsString('aiSeverityForTopic', $blade);

        // Coverage filter remains structural only.
        self::assertStringContainsString("wire:model.live=\"coverageFilter\"", $blade);
        self::assertStringContainsString('topic_filter_coverage_all', $blade);
        self::assertStringNotContainsString('wire:model.live="aiSeverityFilter"', $blade);
        self::assertStringNotContainsString('coverageFilter === \'high\'', $blade);
    }

    public function test_coverage_filter_normalization_unchanged_and_separate_from_ai_severity(): void
    {
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordTopicClusters::class))->getFileName()
        );
        self::assertStringContainsString("['strong', 'medium', 'weak']", $pageSrc);
        self::assertStringNotContainsString('ai_severity', $pageSrc);
        self::assertStringNotContainsString('findings_by_topic', $pageSrc);

        $css = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo/resources/css/keyword-workspace.css'
        );
        self::assertStringContainsString('.cluster-index-row--ai-high', $css);
        self::assertStringContainsString('.cluster-index-row--ai-medium', $css);
        self::assertStringContainsString('.cluster-index-row--ai-low', $css);
        self::assertStringContainsString('.cluster-index-row__ai-note', $css);
    }

    public function test_recluster_does_not_trigger_ai_audit(): void
    {
        $recluster = (string) file_get_contents(
            (string) (new ReflectionClass(ReclustersSiteTopics::class))->getFileName()
        );
        self::assertStringNotContainsString('TopicalMapAuditService', $recluster);
        self::assertStringNotContainsString('confirmRunAiAuditAndTags', $recluster);
        self::assertStringNotContainsString('TopicalMapLatestAuditReadModel', $recluster);

        $status = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditStatusService::class))->getFileName()
        );
        self::assertStringNotContainsString('TopicalMapAuditService::class)->audit', $status);
        self::assertStringNotContainsString('PromptHookCallerBridge', $status);
    }

    public function test_ai_history_link_uses_no_create_resolver(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(RunsTopicalMapAuditAndTags::class))->getFileName()
        );
        $method = $this->methodBody($trait, 'aiHistoryUrl');
        self::assertStringNotContainsString('ensureSharedDraft', $method);
        self::assertStringNotContainsString('linkFromAuditResult', $method);

        $status = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditStatusService::class))->getFileName()
        );
        self::assertStringContainsString('resolveAiHistoryUrl', $status);
        self::assertStringNotContainsString('ensureSharedDraft', $status);

        $readModel = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapLatestAuditReadModel::class))->getFileName()
        );
        self::assertStringContainsString('resolveAiHistoryUrl', $readModel);
        self::assertStringNotContainsString('ensureSharedDraft', $readModel);
    }

    public function test_existing_tag_apply_contracts_unchanged(): void
    {
        $service = (string) file_get_contents(
            (string) (new ReflectionClass(TopicUserTagService::class))->getFileName()
        );
        self::assertStringContainsString('syncAiSuggestions', $service);
        self::assertStringContainsString('source !== TopicTagAssignmentSource::AI', $service);
        self::assertStringContainsString('TopicTagAssignmentSource::MANUAL', $service);
        self::assertStringContainsString('listForSite', $service);
        self::assertStringContainsString('topic_count', $service);
        self::assertStringNotContainsString('semanticMerge', $service);
        self::assertStringNotContainsString('mergeSimilarTags', $service);

        $audit = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService::class
            ))->getFileName()
        );
        self::assertStringContainsString('existing_topic_tags_json', $audit);
        self::assertStringContainsString('syncAiSuggestions', $audit);
        self::assertStringContainsString('listForSite', $audit);
    }

    public function test_refresh_after_audit_clears_presentation_cache(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(RunsTopicalMapAuditAndTags::class))->getFileName()
        );
        $refresh = $this->methodBody($trait, 'refreshAiAuditSnapshot');
        self::assertStringContainsString('latestAiAuditPresentation = null', $refresh);
        self::assertStringContainsString('latestAiAudit()', $refresh);

        $confirm = $this->methodBody($trait, 'confirmRunAiAuditAndTags');
        self::assertStringContainsString('refreshAiAuditSnapshot', $confirm);
        self::assertStringContainsString('refreshClusterSummaryCounters', $confirm);
    }

    public function test_extra_count_formula_for_multi_findings(): void
    {
        $model = (new ReflectionClass(TopicalMapLatestAuditReadModel::class))->newInstanceWithoutConstructor();
        $mapped = $model->mapFindings([
            ['severity' => 'low', 'topic_ref' => 'topic:1', 'observation' => 'a'],
            ['severity' => 'medium', 'topic_ref' => 'topic:1', 'observation' => 'b'],
            ['severity' => 'high', 'topic_ref' => 'topic:1', 'observation' => 'c'],
        ], [1 => true]);

        $rows = $mapped['by_topic'][1];
        self::assertSame('high', $rows[0]['severity']);
        self::assertSame(2, max(0, count($rows) - 1));
    }

    public function test_malformed_topic_ref_helpers_do_not_throw(): void
    {
        self::assertNull(TopicalMapAuditContracts::topicIdFromRef(null));
        self::assertNull(TopicalMapAuditContracts::topicIdFromRef(''));
        self::assertNull(TopicalMapAuditContracts::topicIdFromRef('topic:abc'));
        self::assertNull(TopicalMapAuditContracts::topicIdFromRef('12'));
        self::assertSame(7, TopicalMapAuditContracts::topicIdFromRef('topic:7'));

        $model = (new ReflectionClass(TopicalMapLatestAuditReadModel::class))->newInstanceWithoutConstructor();
        $mapped = $model->mapFindings([
            ['severity' => 'high', 'topic_ref' => 'not-a-ref', 'observation' => 'x'],
            ['severity' => 'low', 'topic_ref' => 'topic:0', 'observation' => 'y'],
        ], [1 => true]);
        self::assertSame([], $mapped['by_topic']);
        self::assertCount(2, $mapped['site_wide']);
    }

    private function methodBody(string $src, string $method): string
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

        self::fail("Unclosed method body for {$method}");
    }
}
