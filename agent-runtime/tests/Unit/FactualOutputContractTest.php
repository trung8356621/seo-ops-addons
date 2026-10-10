<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelEvidenceSanitizer;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Model\AnswerEvidenceProjector;
use Omnichannel\Addons\AgentRuntime\Model\SecretRedactor;
use Omnichannel\Addons\AgentRuntime\Navigation\AgentInternalLinkResolver;
use Omnichannel\Addons\AgentRuntime\Response\FactualAgentResponseComposer;
use Omnichannel\Addons\AgentRuntime\Retrieval\AgentEvidenceLinkEnricher;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FactualOutputContractTest extends TestCase
{
    #[Test]
    public function topic_statistics_use_authoritative_counts_and_skip_the_model(): void
    {
        $response = (new FactualAgentResponseComposer())->compose(
            $this->topics(true),
            'Thống kê số chủ đề Strong, Medium và Weak của website.',
            'vi',
        );

        self::assertNotNull($response);
        self::assertStringContainsString('Strong: 4', $response->blocks[0]['text']);
        self::assertStringContainsString('Medium: 6', $response->blocks[0]['text']);
        self::assertStringContainsString('Weak', $response->blocks[0]['text']);
        self::assertStringContainsString(': 20', $response->blocks[0]['text']);
        self::assertStringContainsString('https://seo-ops.test/seo/keywords?coverage=weak&site_id=4', $response->blocks[0]['text']);
        self::assertStringContainsString('Tổng số chủ đề đã đếm: 30', $response->blocks[0]['text']);
        self::assertStringContainsString('toàn bộ topic của website', $response->blocks[0]['text']);
        self::assertStringNotContainsString('Có 1 bản ghi', $response->message);
        self::assertNotSame('table', $response->blocks[0]['type']);
    }

    #[Test]
    public function partial_topic_pages_are_not_site_totals(): void
    {
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('topics', 'ok', 'GET /keywords', [
                'topics' => [[
                    'name' => 'Balo',
                    'coverage' => 'weak',
                    'detail_href' => '/api/v1/access/access_tmp_secret/keywords/topics/topic:1',
                ]],
                'pagination' => ['page' => 1, 'per_page' => 30, 'total' => 120, 'total_pages' => 4],
            ]),
        ]);

        $response = (new FactualAgentResponseComposer())->compose(
            $bundle,
            'Thống kê số chủ đề Strong, Medium và Weak của website.',
            'vi',
        );

        self::assertNotNull($response);
        self::assertStringContainsString('120', $response->message);
        self::assertStringContainsString('Không dùng số dòng', $response->message);
        self::assertStringNotContainsString('Strong:', $response->message);
        self::assertStringNotContainsString('access_tmp', $response->message);
    }

    #[Test]
    public function topic_listing_keeps_records_without_access_urls(): void
    {
        $response = (new FactualAgentResponseComposer())->compose(
            $this->topics(true),
            'Cho tôi xem các chủ đề.',
            'vi',
        );

        self::assertNotNull($response);
        self::assertSame('table', $response->blocks[0]['type']);
        self::assertSame('Balo học sinh', $response->blocks[0]['rows'][0]['name']['label']);
        self::assertSame('https://seo-ops.test/seo/keywords/clusters/9?site_id=4', $response->blocks[0]['rows'][0]['name']['href']);
        self::assertSame('weak', $response->blocks[0]['rows'][0]['coverage']);
        self::assertArrayNotHasKey('detail_href', $response->blocks[0]['rows'][0]);
        self::assertStringNotContainsString('access_tmp', json_encode($response->blocks, JSON_UNESCAPED_UNICODE) ?: '');
    }

    #[Test]
    public function keyword_inventory_columns_stay_available(): void
    {
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('keywords', 'ok', 'GET /keywords/inventory', [
                'items' => [[
                    'keyword_ref' => 'keyword:3',
                    'id' => 3,
                    'phrase' => 'balo học sinh',
                    'type' => 'primary',
                    'source' => 'manual',
                    'review_status' => 'approved',
                ]],
            ]),
        ]);

        $response = (new FactualAgentResponseComposer())->compose($bundle, 'Website này đang theo dõi những từ khóa SEO nào?', 'vi');

        self::assertNotNull($response);
        self::assertSame('balo học sinh', $response->blocks[0]['rows'][0]['phrase']);
        self::assertSame('primary', $response->blocks[0]['rows'][0]['type']);
        self::assertSame('manual', $response->blocks[0]['rows'][0]['source']);
        self::assertSame('approved', $response->blocks[0]['rows'][0]['review_status']);
        self::assertSame('Có 1 bản ghi phù hợp.', $response->message);
    }

    #[Test]
    public function credential_urls_leave_the_answer_input_and_public_urls_stay(): void
    {
        $public = 'https://shop.example/balo-hoc-sinh';
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('topics', 'ok', 'GET /keywords', [
                'topics' => [[
                    'name' => 'Balo',
                    'public_url' => $public,
                    'detail_href' => '/api/v1/access/access_tmp_secret/keywords/topics/topic:9',
                    'ui_href' => 'https://seo-ops.test/seo/keywords/clusters/9?site_id=4',
                ]],
            ]),
        ]);

        $sanitized = (new AgentModelEvidenceSanitizer())->sanitize($bundle);
        $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        self::assertStringNotContainsString('access_tmp', $encoded);
        self::assertStringNotContainsString('ui_href', $encoded);
        self::assertStringContainsString($public, $encoded);
        self::assertSame($public, $sanitized['sources'][0]['data']['topics'][0]['public_url']);
    }

    #[Test]
    public function improve_evidence_separates_total_from_sample_and_sets_the_task(): void
    {
        $items = [];
        for ($id = 1; $id <= 2; $id++) {
            $items[] = [
                'article_ref' => 'article:'.$id,
                'title' => 'Bài '.$id,
                'seo_score' => 20,
                'focus_keyword' => 'balo',
                'reason_labels' => ['Thiếu meta'],
                'detail_href' => '/api/v1/access/access_tmp_secret/articles/'.$id,
            ];
        }
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'GET /audit', [
                'items' => $items,
                'total' => 50,
                'post_type' => null,
            ]),
        ]);

        $projected = (new AnswerEvidenceProjector())->project(
            $bundle,
            'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.',
        );
        $data = $projected['bundle']['sources'][0]['data'];

        self::assertSame(50, $data['total']);
        self::assertSame(2, $data['sample_size']);
        self::assertFalse($data['sample_covers_total']);
        self::assertCount(2, $data['examples']);
        self::assertArrayNotHasKey('article_ref', $data['examples'][0]);
        self::assertStringNotContainsString('access_tmp', json_encode($projected) ?: '');
        self::assertCount(5, $projected['analysis_task']);

        $input = (new AgentModelInputBuilder(new SecretRedactor(), new class implements ResolvesSettingsPromptHook {
            public function resolveSettingsHook(string $hookKey): SeoPrompt
            {
                $prompt = new SeoPrompt();
                $prompt->markdown_content = 'answer';

                return $prompt;
            }
        }))->buildAnswerInput(
            AgentProjectScope::site(4),
            'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.',
            [],
            $bundle,
            'text',
            'vi',
        )->exportText();

        self::assertStringContainsString('sample_size', $input);
        self::assertStringContainsString('Prioritize 3 to 5 actionable improvements.', $input);
        self::assertStringNotContainsString('access_tmp', $input);
    }

    private function topics(bool $complete): RetrievalBundle
    {
        $source = new RetrievalSource('topics', 'ok', 'GET /keywords', [
            'summary' => [
                'topic_count' => 30,
                'coverage_counts' => [
                    'strong' => 4,
                    'medium' => 6,
                    'weak' => 20,
                    'other' => 0,
                    'counted' => 30,
                    'complete' => $complete,
                ],
                'coverage_links' => [
                    'weak' => 'https://seo-ops.test/seo/keywords?coverage=weak&site_id=4',
                ],
            ],
            'topics' => [[
                'topic_ref' => 'topic:9',
                'id' => 9,
                'name' => 'Balo học sinh',
                'coverage' => 'weak',
                'mcp_percent' => 12,
                'article_count' => 1,
                'detail_href' => '/api/v1/access/access_tmp_secret/keywords/topics/topic:9',
                'ui_href' => 'https://seo-ops.test/seo/keywords/clusters/9?site_id=4',
            ]],
            'pagination' => ['page' => 1, 'per_page' => 30, 'total' => 30, 'total_pages' => 1],
        ]);
        $enriched = (new AgentEvidenceLinkEnricher(new class implements AgentInternalLinkResolver {
            public function resolve(string $entityRef, AgentProjectScope $scope): ?string
            {
                return $entityRef === 'topic:9'
                    ? 'https://seo-ops.test/seo/keywords/clusters/9?site_id=4'
                    : null;
            }
        }))->enrich([$source], AgentProjectScope::site(4));

        return new RetrievalBundle(AgentProjectScope::site(4), $enriched);
    }
}
