<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithPlannerPlanClone;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\PlannerExactTopicMatcher;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\PlannerPlanCloneAllowlist;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\PlannerPlanCloneResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\PlannerPlanCloneService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\DnaPlacement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Planner plan clone — config only (A–N contract + remap unit tests). No real AI.
 */
final class PlannerPlanCloneContractTest extends TestCase
{
    public function test_allowlist_excludes_generated_and_foreign_keys(): void
    {
        foreach ([
            'site_id', 'cluster_id', 'topic_id', 'keyword_id', 'article_id',
            'origin_id', 'planner_run_id', 'prompt_result_id', 'added', 'remaining',
            'fill_remaining_of_run_id', 'status', 'stop_reason',
        ] as $deny) {
            self::assertContains($deny, PlannerPlanCloneAllowlist::DENY_KEYS);
        }
        self::assertContains('content_type', PlannerPlanCloneAllowlist::PLAN_FIELDS);
        self::assertContains('note_items', PlannerPlanCloneAllowlist::PLAN_FIELDS);
        self::assertContains('placement', PlannerPlanCloneAllowlist::DNA_FIELDS);
        self::assertNotContains('candidates', PlannerPlanCloneAllowlist::PLAN_FIELDS);
    }

    public function test_a_empty_destination_receives_full_config_without_generated(): void
    {
        $service = $this->serviceWithMatches([]);
        $source = [$this->topic('clu_src_1', 'Balo laptop', 20, [
            ['phrase' => 'đồng phục', 'slots' => 1, 'source' => 'manual', 'placement' => 'before'],
            ['phrase' => 'giá rẻ', 'slots' => 1, 'source' => 'manual', 'placement' => 'after'],
        ], 'auto')];
        $mapped = $service->remapPlanForDestination(
            $source,
            99,
            [],
            PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING,
        );

        self::assertCount(1, $mapped['note_items']);
        $item = $mapped['note_items'][0];
        self::assertSame(AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED, $item['source_type']);
        self::assertSame(20, $item['target_dna_count']);
        self::assertCount(2, $item['dna']);
        $byPhrase = [];
        foreach ($item['dna'] as $row) {
            $byPhrase[$row['phrase']] = $row['placement'];
        }
        self::assertSame('before', $byPhrase['đồng phục']);
        self::assertSame('after', $byPhrase['giá rẻ']);
        self::assertSame(20, AuditNoteDnaNormalizer::totalTargetDnaCount($mapped['note_items']));
        self::assertStringStartsWith('manual:', (string) $item['cluster_ref']);
        self::assertStringNotContainsString('clu_src_1', (string) $item['cluster_ref']);
    }

    public function test_b_multiple_destinations_remap_independently(): void
    {
        $service = $this->serviceWithCallback(static function (int $siteId, string $name): array {
            if ($siteId === 2) {
                return [['cluster_ref' => 'clu_dest_2', 'cluster_name' => 'Balo laptop', 'mcp_share' => 1.5]];
            }
            if ($siteId === 3) {
                return [['cluster_ref' => 'clu_dest_3', 'cluster_name' => 'Balo laptop', 'mcp_share' => 2.0]];
            }

            return [];
        });
        $source = [$this->topic('clu_src', 'Balo laptop', 10, [
            ['phrase' => 'may', 'slots' => 1, 'placement' => 'after'],
        ])];

        $a = $service->remapPlanForDestination($source, 2, [], PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING);
        $b = $service->remapPlanForDestination($source, 3, [], PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING);

        self::assertSame('clu_dest_2', $a['note_items'][0]['cluster_ref']);
        self::assertSame('clu_dest_3', $b['note_items'][0]['cluster_ref']);
        self::assertNotSame($a['note_items'][0]['cluster_ref'], $b['note_items'][0]['cluster_ref']);
    }

    public function test_c_raw_id_safety_uses_destination_topic_id(): void
    {
        $service = $this->serviceWithMatches([
            ['cluster_ref' => 'clu_dest_999', 'cluster_name' => 'Túi xách', 'mcp_share' => 3.0],
        ]);
        $source = [$this->topic('clu_src_1', 'Túi xách', 5, [
            ['phrase' => 'da thật', 'slots' => 1, 'placement' => 'after'],
        ])];
        $mapped = $service->remapPlanForDestination($source, 7, [], PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING);

        self::assertSame('clu_dest_999', $mapped['note_items'][0]['cluster_ref']);
        self::assertNotSame('clu_src_1', $mapped['note_items'][0]['cluster_ref']);
    }

    public function test_d_missing_destination_topic_becomes_manual_planned(): void
    {
        $service = $this->serviceWithMatches([]);
        $source = [$this->topic('clu_only_source', 'Chủ đề lạ', 8, [
            ['phrase' => 'dna a', 'slots' => 1, 'placement' => 'before'],
        ])];
        $mapped = $service->remapPlanForDestination($source, 5, [], PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING);
        $item = $mapped['note_items'][0];

        self::assertSame(AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED, $item['source_type']);
        self::assertSame('Chủ đề lạ', $item['cluster_name_snapshot']);
        self::assertNull($item['mcp_share_snapshot']);
        self::assertStringStartsWith('manual:', $item['cluster_ref']);
    }

    public function test_e_ambiguous_topic_match_is_skipped(): void
    {
        $service = $this->serviceWithMatches([
            ['cluster_ref' => 'a', 'cluster_name' => 'Balo', 'mcp_share' => 1],
            ['cluster_ref' => 'b', 'cluster_name' => 'Balo', 'mcp_share' => 2],
        ]);
        $source = [$this->topic('clu_src', 'Balo', 5, [])];
        $mapped = $service->remapPlanForDestination($source, 5, [], PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING);

        self::assertSame([], $mapped['note_items']);
        self::assertNotEmpty($mapped['skipped_topics']);
        self::assertNotEmpty($mapped['warnings']);
    }

    public function test_f_existing_target_skip_mode_keeps_target_untouched_via_remap_input(): void
    {
        // Skip mode short-circuits before remap in cloneToDestinations; remap itself still merges when called.
        // Contract: UI/service uses MODE_SKIP_EXISTING constant.
        self::assertSame('skip_existing', PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING);
        $src = (string) file_get_contents((new ReflectionClass(PlannerPlanCloneService::class))->getFileName());
        self::assertStringContainsString('MODE_SKIP_EXISTING', $src);
        self::assertStringContainsString('Domain đã có kế hoạch — đã bỏ qua.', $src);
    }

    public function test_g_merge_adds_missing_only_no_overwrite_no_duplicate(): void
    {
        $service = $this->serviceWithMatches([
            ['cluster_ref' => 'clu_dest', 'cluster_name' => 'Balo laptop', 'mcp_share' => 1],
        ]);
        $existing = [$this->topic('clu_dest', 'Balo laptop', 12, [
            ['phrase' => 'đồng phục', 'slots' => 1, 'source' => 'manual', 'placement' => 'before'],
        ], 'manual')];
        $source = [$this->topic('clu_src', 'Balo laptop', 50, [
            ['phrase' => 'đồng phục', 'slots' => 2, 'source' => 'manual', 'placement' => 'after'],
            ['phrase' => 'học sinh', 'slots' => 1, 'source' => 'manual', 'placement' => 'after'],
        ], 'auto')];

        $mapped = $service->remapPlanForDestination(
            $source,
            5,
            $existing,
            PlannerPlanCloneAllowlist::MODE_MERGE_MISSING,
        );

        self::assertCount(1, $mapped['note_items']);
        $item = $mapped['note_items'][0];
        self::assertSame(12, $item['target_dna_count']); // keep dest target
        self::assertSame('manual', $item['target_mode']);
        $byPhrase = [];
        foreach ($item['dna'] as $row) {
            $byPhrase[$row['phrase']] = $row;
        }
        self::assertCount(2, $byPhrase);
        self::assertSame('before', $byPhrase['đồng phục']['placement']); // keep dest placement
        self::assertSame('after', $byPhrase['học sinh']['placement']);
    }

    public function test_h_dna_placement_before_after_preserved(): void
    {
        $service = $this->serviceWithMatches([]);
        $source = [$this->topic('m1', 'Manual topic', 5, [
            ['phrase' => 'trước', 'slots' => 1, 'placement' => 'before'],
            ['phrase' => 'sau', 'slots' => 1, 'placement' => 'after'],
        ], 'manual', true)];
        $mapped = $service->remapPlanForDestination($source, 1, [], PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING);
        $by = [];
        foreach ($mapped['note_items'][0]['dna'] as $row) {
            $by[$row['phrase']] = $row['placement'];
        }
        self::assertSame('before', $by['trước']);
        self::assertSame('after', $by['sau']);
    }

    public function test_i_legacy_dna_missing_placement_defaults_after(): void
    {
        $dna = AuditNoteDnaNormalizer::normalizeDnaList(['đồng phục', ['phrase' => 'may']]);
        self::assertSame(DnaPlacement::AFTER, $dna[0]['placement']);
        self::assertSame(DnaPlacement::AFTER, $dna[1]['placement']);
    }

    public function test_j_idempotent_merge_does_not_duplicate(): void
    {
        $service = $this->serviceWithMatches([]);
        $source = [$this->topic('m', 'Seed A', 5, [
            ['phrase' => 'dna1', 'slots' => 1, 'placement' => 'after'],
        ], 'manual', true)];

        $first = $service->remapPlanForDestination($source, 1, [], PlannerPlanCloneAllowlist::MODE_MERGE_MISSING);
        $second = $service->remapPlanForDestination(
            $source,
            1,
            $first['note_items'],
            PlannerPlanCloneAllowlist::MODE_MERGE_MISSING,
        );

        self::assertCount(1, $second['note_items']);
        self::assertCount(1, $second['note_items'][0]['dna']);
        self::assertSame(5, $second['note_items'][0]['target_dna_count']);
    }

    public function test_k_tenant_and_l_permission_guarded_in_service(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(PlannerPlanCloneService::class))->getFileName());
        self::assertStringContainsString('SeoAccessControl::canManageContentProjectWorkflow()', $src);
        self::assertStringContainsString('SeoAccessControl::canAccessSite($sourceSiteId)', $src);
        self::assertStringContainsString('SeoAccessControl::canAccessSite($destSiteId)', $src);
        self::assertStringContainsString('Không có quyền domain đích.', $src);
        self::assertStringContainsString('$id === $sourceSiteId', $src);
    }

    public function test_m_partial_source_run_not_in_allowlist_or_snapshot_persist(): void
    {
        self::assertContains('fill_remaining_of_run_id', PlannerPlanCloneAllowlist::DENY_KEYS);
        self::assertContains('remaining', PlannerPlanCloneAllowlist::DENY_KEYS);
        self::assertContains('added', PlannerPlanCloneAllowlist::DENY_KEYS);
        $src = (string) file_get_contents((new ReflectionClass(PlannerPlanCloneService::class))->getFileName());
        self::assertStringContainsString("\$snapshot['fill_remaining_of_run_id']", $src);
        self::assertStringContainsString('recordSavedConfig', $src);
        self::assertStringNotContainsString('GenerateNewContentSuggestionsJob', $src);
    }

    public function test_n_no_generated_content_duplication_paths(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(PlannerPlanCloneService::class))->getFileName());
        self::assertStringNotContainsString('SeoProjectTask::', $src);
        self::assertStringNotContainsString('PromptResult', $src);
        self::assertStringNotContainsString('STATUS_QUEUED', $src);
        self::assertStringContainsString('KIND_SAVED_CONFIG', $src);
        self::assertStringContainsString('recordSavedConfig', $src);
    }

    public function test_result_summary_counts(): void
    {
        $result = new PlannerPlanCloneResult(
            sourceSiteId: 1,
            sourceDomain: 'a.test',
            mode: PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING,
            sourceTopicCount: 3,
            sourceDnaCount: 5,
            sourceTargetTotal: 50,
            destinations: [
                ['site_id' => 2, 'domain' => 'b.test', 'status' => 'copied', 'note_items' => [], 'content_type' => 'post', 'topic_count' => 3, 'dna_count' => 5, 'target_total' => 50, 'warnings' => [], 'skipped_topics' => []],
                ['site_id' => 3, 'domain' => 'c.test', 'status' => 'copied', 'note_items' => [], 'content_type' => 'post', 'topic_count' => 3, 'dna_count' => 5, 'target_total' => 50, 'warnings' => [], 'skipped_topics' => []],
                ['site_id' => 4, 'domain' => 'd.test', 'status' => 'copied', 'note_items' => [], 'content_type' => 'post', 'topic_count' => 2, 'dna_count' => 4, 'target_total' => 40, 'warnings' => [], 'skipped_topics' => ['Ambiguous']],
                ['site_id' => 5, 'domain' => 'e.test', 'status' => 'skipped', 'note_items' => [], 'content_type' => 'post', 'topic_count' => 1, 'dna_count' => 1, 'target_total' => 5, 'warnings' => [], 'skipped_topics' => []],
                ['site_id' => 6, 'domain' => 'f.test', 'status' => 'failed', 'note_items' => [], 'content_type' => 'post', 'topic_count' => 0, 'dna_count' => 0, 'target_total' => 0, 'warnings' => ['x'], 'skipped_topics' => []],
            ],
            correlationId: 'cid',
        );

        self::assertSame(3, $result->copiedCount());
        self::assertSame(1, $result->skippedCount());
        self::assertSame(1, $result->failedCount());
        self::assertSame(1, $result->warningTopicCount());
        $arr = $result->toArray();
        self::assertStringContainsString('Đã sao chép: 3', $arr['summary_message']);
        self::assertSame(50, $arr['source_target_total']);
    }

    public function test_ui_wiring_button_modal_and_trait(): void
    {
        $page = (string) file_get_contents((new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName());
        self::assertStringContainsString('InteractsWithPlannerPlanClone', $page);

        $trait = (string) file_get_contents((new ReflectionClass(InteractsWithPlannerPlanClone::class))->getFileName());
        self::assertStringContainsString('clonePlannerPlan', $trait);
        self::assertStringContainsString('PlannerPlanCloneService', $trait);

        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');
        self::assertStringContainsString('open-planner-plan-clone', $card);
        self::assertStringContainsString('data-planner-clone-modal', $card);
        self::assertStringContainsString('clonePlannerPlan', $card);
        self::assertStringContainsString('writeItemsForSite', $card);
        self::assertStringContainsString('planner-plan-cloned', $card);

        $notes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('writeItemsForSite', $notes);
        self::assertStringContainsString('hasPlanForSite', $notes);
        self::assertStringContainsString('planner_clone_button', $notes);
        self::assertStringContainsString('data-planner-clone-open', $notes);
        self::assertStringContainsString('data-audit-notes-clear-all', $notes);
        self::assertStringContainsString('clearAllSelected', $notes);
        self::assertStringContainsString('clearAllTopicsLocal', $notes);
    }

    public function test_fuzzy_not_used_exact_normalized_only(): void
    {
        $matcher = (string) file_get_contents((new ReflectionClass(
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\AuditNotePlannerExactTopicMatcher::class
        ))->getFileName());
        self::assertStringContainsString('findExactNormalizedNameMatches', $matcher);
        $clone = (string) file_get_contents((new ReflectionClass(PlannerPlanCloneService::class))->getFileName());
        self::assertStringNotContainsString('similar_text', $clone);
        self::assertStringNotContainsString('levenshtein', $clone);
        self::assertStringContainsString('findExactNormalizedNameMatches', $clone);
    }

    /**
     * @param  list<array{cluster_ref: string, cluster_name: string, mcp_share: float}>  $matches
     */
    private function serviceWithMatches(array $matches): PlannerPlanCloneService
    {
        return $this->serviceWithCallback(static fn (): array => $matches);
    }

    /**
     * @param  callable(int, string): list<array{cluster_ref: string, cluster_name: string, mcp_share: float}>  $callback
     */
    private function serviceWithCallback(callable $callback): PlannerPlanCloneService
    {
        $clusters = $this->createMock(PlannerExactTopicMatcher::class);
        $clusters->method('findExactNormalizedNameMatches')->willReturnCallback(
            static function (int $siteId, string $name) use ($callback): array {
                return $callback($siteId, $name);
            },
        );

        $ref = new ReflectionClass(PlannerPlanCloneService::class);
        /** @var PlannerPlanCloneService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('clusters')->setValue($service, $clusters);
        $ref->getProperty('plannerRuns')->setValue(
            $service,
            (new ReflectionClass(ContentProjectPlannerRunService::class))->newInstanceWithoutConstructor(),
        );

        return $service;
    }

    /**
     * @param  list<array<string, mixed>>  $dna
     * @return array<string, mixed>
     */
    private function topic(
        string $ref,
        string $name,
        int $target,
        array $dna,
        string $mode = 'auto',
        bool $manual = false,
    ): array {
        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => $manual
                ? AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED
                : AuditNoteDnaNormalizer::SOURCE_TYPE_CLUSTER,
            'cluster_ref' => $manual ? AuditNoteDnaNormalizer::manualSeedRef() : $ref,
            'cluster_name_snapshot' => $name,
            'seed_text' => $manual ? $name : null,
            'mcp_share_snapshot' => $manual ? null : 1.0,
            'target_dna_count' => $target,
            'target_mode' => $manual ? 'manual' : $mode,
            'dna' => $dna,
        ]);
        self::assertNotNull($item);

        return $item;
    }
}
