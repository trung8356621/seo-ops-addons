<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Model\AnswerEvidenceProjector;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\StructuredRag\EvidenceContextBuilder;
use Omnichannel\Addons\AgentRuntime\StructuredRag\QueryUnderstandingService;
use Omnichannel\Addons\AgentRuntime\StructuredRag\StructuredRagPipeline;
use Omnichannel\Addons\AgentRuntime\StructuredRag\StructuredRetrievalPlanner;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use PHPUnit\Framework\TestCase;

final class StructuredRagPipelineTest extends TestCase
{
    public function test_understands_single_capability_factual_question(): void
    {
        $understanding = (new QueryUnderstandingService())->understand('Website có bao nhiêu bài viết?');
        $plan = (new StructuredRetrievalPlanner())->plan($understanding);

        self::assertFalse($understanding->requiresAnalysis);
        self::assertSame(['articles.inventory'], $plan->capabilities);
        self::assertSame(['articles'], $plan->modules);
    }

    public function test_plans_multiple_independent_capabilities_for_analysis(): void
    {
        $understanding = (new QueryUnderstandingService())->understand('Website nên tối ưu bài cũ hay phát triển nội dung mới theo topic và từ khóa?');
        $plan = (new StructuredRetrievalPlanner())->plan($understanding);

        self::assertTrue($understanding->requiresAnalysis);
        self::assertContains('articles.inventory', $plan->capabilities);
        self::assertContains('keywords.landscape', $plan->capabilities);
        self::assertContains('keywords.relationship', $plan->capabilities);
        self::assertGreaterThan(1, count($plan->capabilities));
        self::assertSame('low_score', $plan->parameters['task']);
        self::assertSame(1, count(array_filter($plan->modules, static fn (string $module): bool => $module === 'keywords')));
    }

    public function test_follow_up_uses_conversation_subject_without_treating_it_as_evidence(): void
    {
        $understanding = (new QueryUnderstandingService())->understand('Vậy nhóm nào nên ưu tiên trước?', [
            ['role' => 'user', 'content' => 'So sánh topic túi vải và topic balo theo coverage.'],
            ['role' => 'assistant', 'content' => 'Có hai nhóm cần xem thêm dữ liệu.'],
        ]);

        self::assertTrue($understanding->resolvedContext['history_subject_applied']);
        self::assertContains('topic_coverage', $understanding->dataRequirements);
        self::assertSame('analysis', $understanding->intent);
    }

    public function test_general_knowledge_question_does_not_retrieve(): void
    {
        $understanding = (new QueryUnderstandingService())->understand('Internal link là gì?');

        self::assertFalse($understanding->requiresRetrieval);
        self::assertSame('general_knowledge', $understanding->intent);
        self::assertFalse((new QueryUnderstandingService())->understand(
            'Canonical khác redirect 301 chỗ nào? Giải thích ngắn, chưa cần áp dụng vào website của tôi.',
        )->requiresRetrieval);
    }

    public function test_gsc_only_question_is_explicitly_excluded_without_unrelated_retrieval(): void
    {
        $understanding = (new QueryUnderstandingService())->understand('Cho tôi clicks và CTR Search Console của website này.');
        $plan = (new StructuredRetrievalPlanner())->plan($understanding);

        self::assertFalse($understanding->requiresRetrieval);
        self::assertContains('gsc_excluded', $understanding->limitations);
        self::assertSame([], $plan->capabilities);
    }

    public function test_factual_dataset_request_requires_retrieval_without_analysis(): void
    {
        $understanding = (new QueryUnderstandingService())->understand(
            'Xuất tối đa 250 bài đã xuất bản của website, mỗi bài chỉ cần tiêu đề và URL hoặc mã bài.',
        );
        $plan = (new StructuredRetrievalPlanner())->plan($understanding);

        self::assertTrue($understanding->requiresRetrieval);
        self::assertFalse($understanding->requiresAnalysis);
        self::assertSame('dataset', $understanding->intent);
        self::assertSame(250, $plan->parameters['limit_max']);
        self::assertSame('published', $plan->parameters['status']);
        self::assertSame('article_identity', $plan->parameters['projection']);
        self::assertSame(['articles.inventory'], $plan->capabilities);
    }

    public function test_prior_memory_failure_analysis_requires_site_evidence(): void
    {
        $understanding = (new QueryUnderstandingService())->understand(
            'Nếu phải bảo vệ ngân sách SEO, đâu là hai tài sản SEO mạnh nhất của website cần giữ nguyên trước khi tối ưu?',
        );

        self::assertTrue($understanding->requiresRetrieval);
        self::assertTrue($understanding->requiresAnalysis);
        self::assertContains('articles', $understanding->dataRequirements);
        self::assertContains('article_quality', $understanding->dataRequirements);
    }

    public function test_topic_and_keyword_entities_are_resolved_as_scoped_lookups(): void
    {
        $topic = (new QueryUnderstandingService())->understand(
            'Chủ đề Backpack Factory (topic:2382) có DNA/từ khóa và bài nào đã gắn?',
        );
        $keyword = (new QueryUnderstandingService())->understand(
            'Trong website này, keyword:6277 thuộc topic nào và liên kết với bài nào?',
        );

        self::assertSame('topic:2382', $topic->parameters['topic_ref']);
        self::assertTrue($topic->resolvedContext['relationship_required']);
        self::assertFalse($topic->resolvedContext['direct_response_eligible']);
        self::assertSame('keyword:6277', $keyword->parameters['keyword_ref']);
        self::assertTrue($keyword->resolvedContext['relationship_required']);
        self::assertSame(['topics'], (new StructuredRetrievalPlanner())->plan($topic)->modules);
        self::assertSame(['keyword_inventory'], (new StructuredRetrievalPlanner())->plan($keyword)->modules);
    }

    public function test_technical_audit_is_missing_instead_of_substituting_content_score(): void
    {
        $understanding = (new QueryUnderstandingService())->understand(
            'Từ dữ liệu audit/crawl nội bộ, chỉ ra canonical, redirect hoặc trang lỗi.',
        );
        $plan = (new StructuredRetrievalPlanner())->plan($understanding);

        self::assertSame(['technical_audit'], $understanding->dataRequirements);
        self::assertSame([], $plan->capabilities);
        self::assertSame(['technical_audit'], $plan->missingRequirements);
    }

    public function test_partial_retrieval_status_is_preserved_as_limitation(): void
    {
        $result = (new EvidenceContextBuilder())->build(new RetrievalBundle(
            AgentProjectScope::site(6),
            [new RetrievalSource('topics', 'unavailable', 'GET /keywords', [], 'fixture_unavailable')],
        ));

        self::assertContains('source_unavailable:topics:fixture_unavailable', $result->limitations);
        self::assertSame('unavailable', $result->bundle->sources[0]->status);
    }

    public function test_context_builder_deduplicates_and_bounds_large_lists(): void
    {
        $rows = array_map(static fn (int $id): array => ['id' => $id, 'title' => 'Article '.$id], range(1, 1000));
        $source = new RetrievalSource('articles', 'ok', 'GET /articles', ['total' => 1000, 'items' => $rows]);
        $result = (new EvidenceContextBuilder(maxSources: 3, maxRecordsPerList: 10, maxEvidenceBytes: 30_000))->build(
            new RetrievalBundle(AgentProjectScope::site(6), [$source, $source]),
        );

        self::assertCount(1, $result->bundle->sources);
        self::assertCount(10, $result->bundle->sources[0]->data['items']);
        self::assertSame(990, $result->bundle->sources[0]->data['_context_meta']['records_omitted']);
        self::assertSame('evidence_context', $result->bundle->sources[0]->data['_context_meta']['stage']);
        self::assertSame(1000, $result->bundle->sources[0]->data['_context_meta']['source_total']);
        self::assertContains('duplicate_source_removed:articles', $result->limitations);
        self::assertLessThanOrEqual(30_000, $result->evidenceBytes);
    }

    public function test_model_projection_does_not_apply_a_second_silent_topic_limit(): void
    {
        $topics = array_map(static fn (int $id): array => [
            'topic_ref' => 'topic:'.$id,
            'name' => 'Topic '.$id,
            'coverage' => 'weak',
            'article_count' => 0,
        ], range(1, 12));
        $bundle = new RetrievalBundle(
            AgentProjectScope::site(5),
            [new RetrievalSource('keywords', 'ok', 'GET /keywords', [
                'source_updated_at' => '2026-11-28T00:00:00+00:00',
                'pagination' => ['total' => 12],
                'topics' => $topics,
            ])],
        );

        $projected = (new AnswerEvidenceProjector())->project($bundle, 'Nên ưu tiên nội dung nào?', structured: true);

        self::assertCount(12, $projected['bundle']['sources'][0]['data']['topics']);
        self::assertSame('2026-11-28T00:00:00+00:00', $projected['bundle']['sources'][0]['data']['source_updated_at']);
        self::assertNotEmpty($projected['analysis_task']);
    }

    public function test_input_only_pipeline_builds_real_model_input_without_model_gateway(): void
    {
        $transport = new RecordingStructuredRagTransport();
        $pipeline = new StructuredRagPipeline(
            new QueryUnderstandingService(),
            new StructuredRetrievalPlanner(),
            new RetrievalExecutor(
                new RetrievalPlanner(),
                new SeoAccessExecutor(
                    $transport,
                    new class implements SeoAccessCredential {
                        public function bearer(): ?string { return 'server-test-token'; }
                    },
                    new SeoAccessUrlPolicy(),
                    'https://seo-ops.test',
                ),
            ),
            new EvidenceContextBuilder(maxSources: 5, maxRecordsPerList: 10, maxEvidenceBytes: 50_000),
            new AgentModelInputBuilder(promptBindings: new StructuredRagPromptResolver()),
        );

        $result = $pipeline->prepare(
            AgentProjectScope::site(6),
            'Website nên tối ưu bài cũ hay phát triển nội dung mới theo topic và từ khóa?',
        );

        self::assertSame('model_input_ready', $result->status);
        self::assertNotNull($result->modelInput);
        self::assertContains('/articles', $transport->paths);
        self::assertContains('/keywords', $transport->paths);
        self::assertStringContainsString('retrieval_bundle', $result->modelInput->exportText());
        self::assertFalse($result->toArray()['model_execution']['answer_model_called']);
        self::assertFalse($result->toArray()['model_execution']['decision_model_called']);
    }
}

final class RecordingStructuredRagTransport implements SeoAccessTransport
{
    /** @var list<string> */
    public array $paths = [];

    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
    {
        if ($method === 'POST') {
            return ['status' => 200, 'json' => ['data' => ['access_url' => 'https://seo-ops.test/api/v1/access/test', 'expires_at' => '2026-11-28T16:00:00+07:00']]];
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = str_replace('/api/v1/access/test', '', $path);
        $this->paths[] = $path;
        $data = match ($path) {
            '/articles' => ['total' => 2, 'items' => [
                ['article_ref' => 'article:1', 'title' => 'A', 'seo_score' => 35, 'reason_labels' => ['missing links']],
                ['article_ref' => 'article:2', 'title' => 'B', 'seo_score' => 70, 'reason_labels' => []],
            ]],
            '/keywords' => ['summary' => ['topic_count' => 2], 'topics' => [
                ['topic_ref' => 'topic:1', 'name' => 'Balo', 'coverage' => 'weak', 'article_count' => 1],
                ['topic_ref' => 'topic:2', 'name' => 'Túi', 'coverage' => 'strong', 'article_count' => 5],
            ]],
            default => ['available' => false, 'reason' => 'fixture_unavailable'],
        };

        return ['status' => 200, 'json' => ['data' => $data]];
    }
}

final class StructuredRagPromptResolver implements ResolvesSettingsPromptHook
{
    public function resolveSettingsHook(string $hookKey): SeoPrompt
    {
        $prompt = new SeoPrompt();
        $prompt->forceFill(['hook_key' => $hookKey, 'markdown_content' => 'Use only the structured retrieval evidence.']);

        return $prompt;
    }
}
