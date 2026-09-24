<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\TopicalMapAuditHistoryLinker;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditResultParser;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditStatusService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapLatestAuditReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Realistic PromptResult shape from PromptRunner (site_id=0 + output_text JSON).
 */
final class TopicalMapLatestAuditRuntimeContractTest extends TestCase
{
    public function test_status_service_falls_back_when_prompt_result_site_id_is_zero(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditStatusService::class))->getFileName()
        );
        self::assertStringContainsString('latestAuditPromptResultId', $src);
        self::assertStringContainsString('site_domain', $src);
        self::assertStringContainsString("where('site_id', 0)", $src);
        self::assertStringContainsString('bindPromptResultSite', (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService::class
            ))->getFileName()
        ));
    }

    public function test_history_linker_exposes_latest_audit_prompt_result_id_without_create(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditHistoryLinker::class))->getFileName()
        );
        $body = $this->methodBody($src, 'latestAuditPromptResultId');
        self::assertStringContainsString('SOURCE_TOPICAL_MAP_AUDIT', $body);
        self::assertStringContainsString("where('site_id', \$siteId)", $body);
        self::assertStringNotContainsString('ensureSharedDraft', $body);
    }

    public function test_realistic_prompt_runner_output_text_maps_topic_refs_to_cards(): void
    {
        // Shape mirrors PromptResult #3206 (PromptRunner legacy): plain output_text JSON contract.
        $raw = json_encode([
            'summary' => 'baloquatang.net có cấu trúc topical map',
            'findings' => [
                [
                    'type' => 'internal_link_gap',
                    'severity' => 'high',
                    'topic_ref' => 'topic:50',
                    'topic_name' => 'Balo quà tặng',
                    'title' => 'Internal links thin',
                    'observation' => 'MCP cho thấy chỉ 1 bài nhận được internal links và 1,131 bài không có internal links.',
                    'evidence' => ['MCP internal links'],
                ],
                [
                    'type' => 'data_quality',
                    'severity' => 'medium',
                    'topic_ref' => 'topic:50',
                    'topic_name' => 'Balo quà tặng',
                    'title' => 'Secondary note',
                    'observation' => 'Ghi chú phụ cho cùng Topic.',
                    'evidence' => [],
                ],
                [
                    'type' => 'data_quality',
                    'severity' => 'low',
                    'topic_ref' => null,
                    'title' => 'Site note',
                    'observation' => 'GSC data unavailable for this audit.',
                    'evidence' => [],
                ],
                [
                    'type' => 'coverage_gap',
                    'severity' => 'high',
                    'topic_ref' => 'topic:99999',
                    'title' => 'Deleted topic',
                    'observation' => 'Stale topic ref must be site-wide.',
                    'evidence' => [],
                ],
            ],
            'opportunities' => [
                ['topic_ref' => 'topic:50', 'title' => 'Opp', 'reason' => 'r', 'suggested_direction' => 'd'],
            ],
            'recommended_actions' => [
                ['priority' => 1, 'action_type' => 'review_internal_links', 'topic_ref' => 'topic:50', 'title' => 'Fix links', 'reason' => 'gap'],
            ],
            'tag_suggestions' => ['taxonomy' => [], 'assignments' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $parser = new TopicalMapAuditResultParser;
        $parsed = $parser->parse($raw, []);
        self::assertTrue($parsed['ok']);
        $findings = $parsed['payload']['findings'];
        self::assertCount(4, $findings);
        self::assertSame('topic:50', $findings[0]['topic_ref']);

        $model = (new ReflectionClass(TopicalMapLatestAuditReadModel::class))->newInstanceWithoutConstructor();
        $mapped = $model->mapFindings($findings, [50 => true, 2688 => true]);

        self::assertArrayHasKey(50, $mapped['by_topic']);
        self::assertCount(2, $mapped['by_topic'][50]);
        self::assertSame('high', $model->highestSeverity($mapped['by_topic'][50]));
        self::assertSame(
            'MCP cho thấy chỉ 1 bài nhận được internal links và 1,131 bài không có internal links.',
            $mapped['by_topic'][50][0]['observation'],
        );
        self::assertSame(1, max(0, count($mapped['by_topic'][50]) - 1));
        self::assertCount(2, $mapped['site_wide']); // null ref + stale 99999
        self::assertArrayNotHasKey(99999, $mapped['by_topic']);

        $observation = (string) $mapped['by_topic'][50][0]['observation'];
        $severity = (string) $model->highestSeverity($mapped['by_topic'][50]);
        $extra = max(0, count($mapped['by_topic'][50]) - 1);

        // Same presentation contract Topics card renders (no Livewire bootstrap required).
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $siteNotesHtml = '';
        foreach ($mapped['site_wide'] as $note) {
            $siteNotesHtml .= '<div class="topic-ai-site-notes__item">'
                .$esc((string) ($note['observation'] ?? ''))
                .'</div>';
        }
        $html = '<div class="cluster-index-row cluster-index-row--ai-'.$esc($severity).'"'
            .' title="AI severity: '.$esc(ucfirst($severity)).'">'
            .'<div class="cluster-index-row__meta">134 keywords · 124 focus articles</div>'
            .'<div class="cluster-index-row__ai-note">'
            .'<div class="cluster-index-row__ai-note-label">AI note</div>'
            .'<div class="cluster-index-row__ai-note-text">'.$esc($observation).'</div>'
            .($extra > 0
                ? '<button type="button" class="cluster-index-row__ai-note-more">+'.$extra.' nhận xét khác</button>'
                : '')
            .'</div></div>'
            .($siteNotesHtml !== '' ? '<div class="topic-ai-site-notes">'.$siteNotesHtml.'</div>' : '');

        self::assertStringContainsString('cluster-index-row--ai-high', $html);
        self::assertStringContainsString('AI severity: High', $html);
        self::assertStringContainsString($observation, $html);
        self::assertStringContainsString('+1 nhận xét khác', $html);
        self::assertStringContainsString('topic-ai-site-notes', $html);
        self::assertStringContainsString('GSC data unavailable for this audit.', $html);
        self::assertStringContainsString('Stale topic ref must be site-wide.', $html);
        self::assertStringNotContainsString('cluster-index-row--ai-medium', $html);
    }

    public function test_topics_blade_still_wires_observation_and_severity_classes(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('aiFindingsForTopic', $blade);
        self::assertStringContainsString('cluster-index-row--ai-', $blade);
        self::assertStringContainsString('cluster-index-row__ai-note-text', $blade);
        self::assertStringContainsString('topic_ai_note_more', $blade);
        self::assertStringContainsString('topic-ai-audit-summary', $blade);
        self::assertStringContainsString('topic-ai-site-notes', $blade);
        self::assertStringContainsString("wire:model.live=\"coverageFilter\"", $blade);
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
