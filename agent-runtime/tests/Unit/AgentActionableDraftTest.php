<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Response\FactualAgentResponseComposer;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeItemResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Omnichannel\Addons\Seo\Services\SeoAuditKeywordFlagService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class AgentActionableDraftTest extends TestCase
{
    #[Test]
    public function worst_articles_request_published_rows_sorted_by_score_before_the_limit(): void
    {
        $root = dirname(__DIR__, 3);
        $read = file_get_contents($root.'/seo/src/Services/SeoAudit/Agent/SeoAuditAgentReadService.php');
        $executor = file_get_contents($root.'/agent-runtime/src/Runtime/AgentConfirmedToolExecutor.php');
        $shared = file_get_contents($root.'/seo/src/Services/SeoAuditKeywordFlagService.php');
        self::assertIsString($read);
        self::assertIsString($executor);
        self::assertIsString($shared);
        self::assertStringContainsString("->where('status', 'published')", $read);
        self::assertStringContainsString("\$sortScoreAsc ? 'score' : null", $read);
        self::assertStringContainsString("'published_only' => true", $executor);
        self::assertStringContainsString("'sort_score_asc' => true", $executor);
        self::assertStringContainsString("'limit' => \$limit", $executor);
        self::assertStringNotContainsString("->where('status', 'published')", $shared);
    }

    #[Test]
    public function score_sort_orders_fifty_rows_ascending_before_callers_apply_a_limit(): void
    {
        $rows = [];
        for ($i = 50; $i >= 1; $i--) {
            $rows[] = ['id' => $i, 'score' => $i, 'updated_at' => '2026-01-01'];
        }
        $method = new ReflectionMethod(SeoAuditKeywordFlagService::class, 'compareResultRows');
        $service = (new ReflectionClass(SeoAuditKeywordFlagService::class))->newInstanceWithoutConstructor();
        usort($rows, static fn (array $left, array $right): int => $method->invoke($service, $left, $right, 'score', 'asc'));

        self::assertSame(1, $rows[0]['score']);
        self::assertSame(50, $rows[49]['score']);
        self::assertCount(50, $rows);
    }

    #[Test]
    public function factual_worst_articles_are_actionable_and_plain_tables_are_not(): void
    {
        $composer = new FactualAgentResponseComposer();
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'list', [
                'draft_action' => 'content_project.draft.intake',
                'draft_item_type' => 'improve',
                'draft_source' => 'seo_audit',
                'items' => [[
                    'article_ref' => 'article:7',
                    'title' => 'Balo',
                    'focus_keyword' => 'balo',
                    'seo_score' => 21,
                    'reason_labels' => ['Thin content'],
                ]],
            ]),
        ]);

        $response = $composer->compose($bundle, 'tháng 9 này cần sửa những bài nào', 'vi');
        $block = $response->blocks[0];
        self::assertSame('content_project.draft.intake', $block['actionable']['action']);
        self::assertSame('site:4', $block['actionable']['site_ref']);
        self::assertSame(1, $block['rows'][0]['n']);
        self::assertSame('improve', $block['rows'][0]['item']['type']);
        self::assertSame('article:7', $block['rows'][0]['item']['article_ref']);
        self::assertSame(['Thin content'], $block['rows'][0]['item']['reasons']);

        $plain = $composer->compose(new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('keywords', 'ok', 'keywords', [
                'items' => [['keyword' => 'balo', 'clicks' => 3]],
            ]),
        ]), 'keyword landscape', 'en');
        self::assertArrayNotHasKey('actionable', $plain->blocks[0]);
        self::assertArrayNotHasKey('item', $plain->blocks[0]['rows'][0]);
    }

    #[Test]
    public function parser_rejects_markdown_style_lists_and_accepts_validated_items(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), []);
        $parsed = $parser->parse(json_encode([
            'message' => 'Ideas',
            'blocks' => [[
                'type' => 'table',
                'columns' => [['key' => 'title', 'label' => 'Title']],
                'actionable' => ['action' => 'content_project.draft.intake', 'site_ref' => 'site:4'],
                'rows' => [[
                    'title' => 'Balo mới',
                    'item' => [
                        'id' => 'title:Balo mới',
                        'type' => 'new',
                        'title' => 'Balo mới',
                        'keyword' => 'balo',
                        'reasons' => [],
                        'source' => ['type' => 'agent', 'ref' => 'title:Balo mới'],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR), $bundle);
        self::assertSame('new', $parsed->blocks[0]['rows'][0]['item']['type']);

        $markdown = $parser->parse(json_encode([
            'message' => '1. Viết bài balo',
            'blocks' => [['type' => 'markdown', 'text' => '1. Viết bài balo']],
        ], JSON_THROW_ON_ERROR), $bundle);
        self::assertArrayNotHasKey('actionable', $markdown->blocks[0]);
        self::assertSame('markdown', $markdown->blocks[0]['type']);
    }

    #[Test]
    public function improve_maps_to_improve_and_requires_an_article(): void
    {
        $service = (new ReflectionClass(ServiceApiDraftIntakeService::class))->newInstanceWithoutConstructor();
        $normalize = new ReflectionMethod($service, 'normalizeItems');
        $rows = $normalize->invoke($service, [[
            'type' => 'improve',
            'article_ref' => 'article:9',
            'title' => 'Balo',
            'keyword' => 'balo',
            'source' => ['type' => 'seo_audit', 'ref' => 'article:9', 'reason' => 'low score'],
        ]], 4);
        self::assertSame(SeoProjectTask::TYPE_IMPROVE, $rows[0]['type']);
        self::assertNotSame(SeoProjectTask::TYPE_REWRITE, $rows[0]['type']);

        $this->expectException(InvalidArgumentException::class);
        $normalize->invoke($service, [[
            'type' => 'improve',
            'title' => 'Không có bài',
            'keyword' => 'balo',
        ]], 4);
    }

    #[Test]
    public function draft_intake_rejects_another_site_and_reports_partial_results_without_a_model(): void
    {
        $sites = new class implements SiteDirectory {
            public function listActiveSites(?int $userId = null): array
            {
                return [];
            }

            public function isSiteVisible(int $siteId, ?int $userId = null): bool
            {
                return $siteId === 4 && $userId === 1;
            }
        };
        $intake = $this->createMock(ServiceApiDraftIntakeService::class);
        $intake->expects($this->never())->method('intake');
        $controller = new AgentRuntimeController();
        $denied = $controller->draftIntake($this->request([
            'site_id' => 9,
            'items' => [[
                'type' => 'improve',
                'article_ref' => 'article:1',
                'title' => 'X',
                'keyword' => 'x',
                'source' => ['type' => 'seo_audit', 'ref' => 'article:1'],
            ]],
        ]), $sites, $intake);
        self::assertSame(403, $denied->getStatusCode());

        $partial = new ServiceApiDraftIntakeResult('project:3', 'site:4', 2, 1, 0, 1, [
            new ServiceApiDraftIntakeItemResult(0, 'added', 'item:1'),
            new ServiceApiDraftIntakeItemResult(1, 'failed', message: 'Unknown article_id.'),
        ]);
        $calledKey = null;
        $intake = $this->createMock(ServiceApiDraftIntakeService::class);
        $intake->expects($this->once())->method('intake')->willReturnCallback(
            function (array $payload, ?string $key) use ($partial, &$calledKey): ServiceApiDraftIntakeResult {
                $calledKey = $key;
                self::assertSame(4, $payload['site_id']);

                return $partial;
            }
        );
        $body = [
            'site_id' => 4,
            'items' => [
                ['type' => 'improve', 'article_ref' => 'article:1', 'title' => 'A', 'keyword' => 'a', 'source' => ['type' => 'seo_audit', 'ref' => 'article:1']],
                ['type' => 'new', 'title' => 'Bài mới', 'keyword' => 'moi', 'source' => ['type' => 'agent', 'ref' => 'title:Bài mới']],
            ],
        ];
        $ok = $controller->draftIntake($this->request($body), $sites, $intake);
        $data = $ok->getData(true)['data'];
        self::assertFalse($data['complete']);
        self::assertSame(1, $data['failed']);
        self::assertSame('failed', $data['items'][1]['status']);
        self::assertSame('/seo/content-projects/3', $data['draft_url']);

        $intake = $this->createMock(ServiceApiDraftIntakeService::class);
        $intake->expects($this->once())->method('intake')->willReturnCallback(
            function (array $payload, ?string $key) use ($calledKey, $partial): ServiceApiDraftIntakeResult {
                self::assertSame($calledKey, $key);

                return $partial;
            }
        );
        $controller->draftIntake($this->request($body), $sites, $intake);
    }

    /** @param array<string, mixed> $payload */
    private function request(array $payload): Request
    {
        $request = Request::create('/agent-runtime/draft-intake', 'POST', $payload);
        $request->setUserResolver(static fn () => new class {
            public int $id = 1;
        });

        return $request;
    }
}
