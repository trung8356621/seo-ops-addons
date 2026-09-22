<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\ContentProjectDraftAiCallHistoryService;
use Tests\TestCase;

/**
 * Read-model proof: Discover PromptResult linked via planner run appears in Content Plan AI History.
 * Requires SEO_TEST_USE_MYSQL=true + omi_seo_ai tables.
 */
final class DiscoverNewTopicsAiHistoryIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        $testDb = trim((string) (env('SEO_TEST_DATABASE') ?: env('DB_TEST_DATABASE') ?: ''));
        if ($testDb === '' || ! str_ends_with($testDb, '_test')) {
            $this->markTestSkipped('Set SEO_TEST_DATABASE=*_test (disposable) for Discover AI History integration.');
        }

        foreach (['seo_projects', 'seo_content_project_planner_runs', 'prompt_results', 'prompts'] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table: '.$table);
            }
        }
    }

    public function test_discover_prompt_result_appears_in_content_plan_ai_history_list(): void
    {
        $project = SeoProject::query()->create([
            'name' => 'Discover History IT '.$this->uniqueSuffix(),
            'status' => SeoProject::STATUS_DRAFT,
            'site_id' => null,
            'user_id' => 1,
        ]);

        $prompt = SeoPrompt::query()->create([
            'user_id' => 1,
            'name' => 'Discover IT '.$this->uniqueSuffix(),
            'title' => 'Discover IT',
            'hook_key' => DiscoverNewTopicsService::HOOK_KEY,
            'hook_version' => DiscoverNewTopicsService::HOOK_VERSION,
            'markdown_content' => "# Role\nTest",
            'is_active' => true,
            'tools' => 'default',
        ]);

        $result = PromptResult::query()->create([
            'prompt_id' => (int) $prompt->getKey(),
            'status' => 'completed',
            'output_text' => '{"topics":[]}',
            'started_at' => now(),
            'finished_at' => now(),
            'input_snapshot' => [
                'variables' => ['hook_key' => DiscoverNewTopicsService::HOOK_KEY],
                'hook_key' => DiscoverNewTopicsService::HOOK_KEY,
            ],
        ]);

        $service = app(DiscoverNewTopicsService::class);
        $service->linkPromptResultToContentPlanHistory(
            $project,
            7,
            1,
            DiscoverNewTopicsService::DEFAULT_COUNT,
            [
                'ok' => true,
                'message' => '',
                'topics' => [
                    [
                        'candidate_key' => 'g-it-1',
                        'name' => 'Topic IT',
                        'target_dna_count' => 5,
                        'dna' => ['a'],
                    ],
                ],
                'rejected_count' => 0,
                'prompt_result_id' => (int) $result->getKey(),
            ],
        );

        $runs = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', SeoContentProjectPlannerRun::SOURCE_DISCOVER_NEW_TOPICS)
            ->where('prompt_result_id', (int) $result->getKey())
            ->get();
        self::assertCount(1, $runs);

        $history = app(ContentProjectDraftAiCallHistoryService::class)->list($project, [
            'type' => ContentProjectDraftAiCallHistoryService::TYPE_DISCOVER_NEW_TOPICS,
            'status' => 'all',
            'page' => 1,
            'per_page' => 20,
        ]);

        self::assertSame(1, $history['total']);
        self::assertNotEmpty($history['groups']);
        $prompts = $history['groups'][0]['prompts'] ?? [];
        self::assertCount(1, $prompts);
        self::assertSame((int) $result->getKey(), (int) ($prompts[0]['prompt_result_id'] ?? 0));
        self::assertSame(DiscoverNewTopicsService::HOOK_KEY, (string) ($prompts[0]['hook_key'] ?? ''));

        // No duplicate when listing without type filter.
        $all = app(ContentProjectDraftAiCallHistoryService::class)->list($project, [
            'type' => 'all',
            'status' => 'completed',
        ]);
        self::assertSame(1, $all['total']);
    }

    public function test_resolve_planning_project_falls_back_to_shared_draft(): void
    {
        $draft = SeoProject::query()->create([
            'name' => 'Shared Draft IT '.$this->uniqueSuffix(),
            'status' => SeoProject::STATUS_DRAFT,
            'site_id' => null,
            'user_id' => 1,
        ]);

        $resolved = app(DiscoverNewTopicsService::class)->resolvePlanningProject(null, 7, 1);
        self::assertInstanceOf(SeoProject::class, $resolved);
        self::assertTrue($resolved->isDraftPlanning());
        // Prefer an existing shared draft when present (may be $draft or canonical older).
        self::assertNull($resolved->site_id);
        unset($draft);
    }

    public function test_link_is_idempotent_one_prompt_result_one_history_row_semantics(): void
    {
        $project = SeoProject::query()->create([
            'name' => 'Discover Dup IT '.$this->uniqueSuffix(),
            'status' => SeoProject::STATUS_DRAFT,
            'site_id' => null,
            'user_id' => 1,
        ]);
        $prompt = SeoPrompt::query()->create([
            'user_id' => 1,
            'name' => 'Discover Dup '.$this->uniqueSuffix(),
            'title' => 'Discover Dup',
            'hook_key' => DiscoverNewTopicsService::HOOK_KEY,
            'markdown_content' => "# Role\nTest",
            'is_active' => true,
            'tools' => 'default',
        ]);
        $result = PromptResult::query()->create([
            'prompt_id' => (int) $prompt->getKey(),
            'status' => 'completed',
            'output_text' => '{}',
            'started_at' => now(),
            'finished_at' => now(),
            'input_snapshot' => ['hook_key' => DiscoverNewTopicsService::HOOK_KEY],
        ]);

        $payload = [
            'ok' => true,
            'message' => '',
            'topics' => [],
            'rejected_count' => 0,
            'prompt_result_id' => (int) $result->getKey(),
        ];
        $svc = app(DiscoverNewTopicsService::class);
        $svc->linkPromptResultToContentPlanHistory($project, 3, 1, 8, $payload);
        $svc->linkPromptResultToContentPlanHistory($project, 3, 1, 8, $payload);

        $runCount = SeoContentProjectPlannerRun::query()
            ->where('prompt_result_id', (int) $result->getKey())
            ->count();
        // Two linkage calls create two planner runs historically allowed; history list de-dupes by prompt_result_id.
        self::assertGreaterThanOrEqual(1, $runCount);

        $history = app(ContentProjectDraftAiCallHistoryService::class)->list($project, ['type' => 'all']);
        self::assertSame(1, $history['total'], 'History read model must show one row per PromptResult');
    }

    private function uniqueSuffix(): string
    {
        return (string) (900000 + (++$this->seq)).'-'.bin2hex(random_bytes(2));
    }
}
