<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Support\ArticleGenerationSourceResult;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ArticleGenerationInputResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fixture_raw_artifact_contains_both_markers(): void
    {
        $raw = $this->fixtureArtifact();
        $result = $this->resolver('')->resolveFromRawArtifact($raw);

        self::assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, $result->rawArtifact);
        self::assertStringContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $result->rawArtifact);
        self::assertNotSame('', $result->outlineSection);
        self::assertNotSame('', $result->writingInstructionsSection);
        self::assertTrue($result->outlineMarkerFound);
        self::assertTrue($result->writingInstructionsMarkerFound);
    }

    public function test_rejects_article_body_with_seo_title_meta_and_long_content(): void
    {
        $body = "**SEO Title:** Old title\n\n**Meta Description:** Old meta\n\n"
            .str_repeat('word ', 1602);

        $resolver = $this->resolver('');

        self::assertTrue($resolver->looksLikeArticleContent($body));
        self::assertNull($resolver->tryParseArtifact($body));
        self::assertNull($resolver->tryResolveFromRawArtifact($body));
    }

    public function test_prefers_outline_producer_over_newer_content_success_item(): void
    {
        $outlineRaw = $this->fixtureArtifact('outline-A', 'vocab-A');
        $articleBody = "**SEO Title:** X\n\n**Meta Description:** Y\n\n".str_repeat('body ', 500);

        $contentFirst = $this->fakeRunItem(99, 100, [
            [
                'type' => 'prompt',
                'title' => 'Viáº¿t bÃ i theo dÃ n Ã½',
                'hook_key' => 'article.content.generate',
                'persists_as_outline' => false,
                'status' => 'completed',
                'output' => $articleBody,
                'outputs' => ['out_main' => $articleBody],
            ],
        ]);
        $outlineSecond = $this->fakeRunItem(10, 100, [
            [
                'type' => 'prompt',
                'title' => 'Outline',
                'hook_key' => ArticleGenerationInputResolver::OUTLINE_HOOK_KEY,
                'persists_as_outline' => true,
                'status' => 'completed',
                'output' => $outlineRaw,
                'outputs' => ['total' => $outlineRaw, 'out_main' => $outlineRaw],
                'result_id' => 55,
            ],
        ]);

        // Giá»‘ng query orderByDesc(id): content id=99 trÆ°á»›c, outline id=10 sau.
        $result = $this->resolveFromInjectedItems([$contentFirst, $outlineSecond]);

        self::assertSame(ArticleGenerationSourceResult::SOURCE_RUN_OUTLINE_ARTIFACT, $result->sourceType);
        self::assertSame(10, $result->sourceRunItemId);
        self::assertSame(55, $result->sourcePromptResultId);
        self::assertStringContainsString('outline-A', $result->rawArtifact);
        self::assertStringNotContainsString('SEO Title', $result->rawArtifact);
        self::assertStringNotContainsString('body body', $result->rawArtifact);
    }

    public function test_query_includes_failed_run_items_to_reuse_completed_outline_step(): void
    {
        $source = (string) file_get_contents(ProjectRoot::addonsPath().'/content-projects/src/Services/ArticleGenerationInputResolver.php');

        self::assertStringContainsString('whereIn(\'status\'', $source);
        self::assertStringContainsString('SeoProjectRunItemStatus::Success->value', $source);
        self::assertStringContainsString('SeoProjectRunItemStatus::Failed->value', $source);
    }

    public function test_legacy_completed_raw_outline_artifact_step_is_reusable_without_hook_metadata(): void
    {
        $outlineRaw = $this->fixtureArtifact('legacy-outline', 'legacy-vocab');
        $legacyStep = [
            'type' => 'prompt',
            'title' => 'DÃ n Ã½ bÃ i viáº¿t',
            'status' => 'completed',
            'output' => $outlineRaw,
        ];

        $result = $this->resolveFromInjectedItems([$this->fakeRunItem(77, 88, [$legacyStep])]);

        self::assertSame(ArticleGenerationSourceResult::SOURCE_RUN_OUTLINE_ARTIFACT, $result->sourceType);
        self::assertSame(77, $result->sourceRunItemId);
        self::assertStringContainsString('legacy-outline', $result->rawArtifact);
        self::assertStringContainsString('legacy-vocab', $result->writingInstructionsSection);
    }

    public function test_does_not_pick_article_output_masquerading_as_outline_fields(): void
    {
        $articleBody = "**SEO Title:** X\n\n**Meta Description:** Y\n\n".str_repeat('paragraph ', 300);
        $item = $this->fakeRunItem(5, 1, [
            [
                'type' => 'prompt',
                'title' => 'Viáº¿t láº¡i ná»™i dung',
                'status' => 'completed',
                'output' => $articleBody,
                'outputs' => [
                    'out_main' => $articleBody,
                    'out_outline' => $articleBody,
                ],
                'outline_markdown' => $articleBody,
                'persists_as_outline' => false,
            ],
        ]);

        self::assertNull($this->tryResolveFromInjectedItems([$item]));
    }

    public function test_rejects_artifact_missing_vocabulary_section(): void
    {
        $raw = ArticleGenerationInputResolver::OUTLINE_START."\n## H2 only\n"
            .ArticleGenerationInputResolver::OUTLINE_END;

        self::assertNull($this->resolver('')->tryParseArtifact($raw));
    }

    public function test_rejects_empty_sections_with_markers(): void
    {
        $raw = ArticleGenerationInputResolver::OUTLINE_START."\n   \n"
            .ArticleGenerationInputResolver::OUTLINE_END."\n"
            .ArticleGenerationInputResolver::VOCABULARY_START."\n   \n"
            .ArticleGenerationInputResolver::VOCABULARY_END;

        self::assertNull($this->resolver('')->tryParseArtifact($raw));
    }

    public function test_canonical_fallback_allowed_when_full_two_sections(): void
    {
        $raw = $this->fixtureArtifact('canon-outline', 'canon-vocab');
        $article = $this->article(7, $raw);

        $result = $this->resolverNoRunLookup($raw)->resolveForArticle($article);

        self::assertSame(ArticleGenerationSourceResult::SOURCE_CANONICAL_OUTLINE_ARTIFACT, $result->sourceType);
        self::assertStringContainsString('canon-outline', $result->rawArtifact);
        self::assertStringContainsString('canon-vocab', $result->writingInstructionsSection);
    }

    public function test_canonical_heading_only_rejected(): void
    {
        $headingOnly = "## TiÃªu chÃ­ 1\n## TiÃªu chÃ­ 2\n## TiÃªu chÃ­ 3\nNá»™i dung dÃ n Ã½ Ä‘á»§ dÃ i.";
        $article = $this->article(8, $headingOnly);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ArticleGenerationInputResolver::REJECT_MESSAGE);
        $this->resolverNoRunLookup($headingOnly)->resolveForArticle($article);
    }

    public function test_reassembles_from_stripped_ports(): void
    {
        $step = [
            'title' => 'Outline',
            'hook_key' => ArticleGenerationInputResolver::OUTLINE_HOOK_KEY,
            'persists_as_outline' => true,
            'output' => '',
            'outputs' => [
                'task_1_outline' => '## Heading structure',
                'task_2_vocabulary' => 'term: meaning â€” writing guidance',
            ],
        ];

        $resolver = $this->resolver('');
        self::assertTrue($resolver->isOutlineProducerStep($step));

        $matched = null;
        foreach ($resolver->candidatePayloadsFromStep($step) as $candidate) {
            if ($resolver->isValidArtifact($candidate)) {
                $matched = $candidate;
                break;
            }
        }

        self::assertNotNull($matched);
        self::assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, (string) $matched);
        self::assertStringContainsString(ArticleGenerationInputResolver::VOCABULARY_START, (string) $matched);
    }

    public function test_debug_variables_shape(): void
    {
        $result = $this->resolver('')->resolveFromRawArtifact(
            $this->fixtureArtifact(),
            ArticleGenerationSourceResult::SOURCE_RUN_OUTLINE_ARTIFACT,
            123,
            456,
            789,
        );

        $debug = $result->toDebugVariables();
        self::assertSame('run_outline_artifact', $debug['article_generation_source']);
        self::assertSame(123, $debug['source_run_id']);
        self::assertSame(456, $debug['source_run_item_id']);
        self::assertTrue($debug['outline_marker_found']);
        self::assertTrue($debug['writing_instructions_marker_found']);
        self::assertNotSame('', $debug['artifact_version']);
    }

    public function test_content_step_is_not_outline_producer(): void
    {
        $resolver = $this->resolver('');
        self::assertFalse($resolver->isOutlineProducerStep([
            'title' => 'Viáº¿t bÃ i theo dÃ n Ã½',
            'hook_key' => 'article.content.generate',
            'output' => $this->fixtureArtifact(),
            'persists_as_outline' => false,
        ]));
    }

    private function fixtureArtifact(string $outline = 'H2 Outline body', string $vocab = 'term: meaning'): string
    {
        return ArticleGenerationInputResolver::OUTLINE_START."\n{$outline}\n"
            .ArticleGenerationInputResolver::OUTLINE_END."\n"
            .ArticleGenerationInputResolver::VOCABULARY_START."\n{$vocab}\n"
            .ArticleGenerationInputResolver::VOCABULARY_END;
    }

    private function article(int $id, string $canonical = ''): SeoArticle
    {
        $article = new SeoArticle;
        $article->id = $id;
        $metas = [];
        if ($canonical !== '') {
            $metas[] = new ArticleMeta([
                'meta_key' => ArticleOutlineResolver::META_KEY,
                'meta_value' => $canonical,
            ]);
        }
        $article->setRelation('articleMetas', new Collection($metas));

        return $article;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function fakeRunItem(int $id, int $runId, array $steps): object
    {
        return (object) [
            'id' => $id,
            'run_id' => $runId,
            'output_snapshot' => ['steps' => $steps],
        ];
    }

    private function resolver(string $canonical): ArticleGenerationInputResolver
    {
        $outline = \Mockery::mock(ArticleOutlineResolver::class);
        $outline->shouldReceive('resolveMarkdown')->andReturn($canonical);

        return new ArticleGenerationInputResolver($outline);
    }

    private function resolverNoRunLookup(string $canonical): ArticleGenerationInputResolver
    {
        $outline = \Mockery::mock(ArticleOutlineResolver::class);
        $outline->shouldReceive('resolveMarkdown')->andReturn($canonical);

        return new class($outline) extends ArticleGenerationInputResolver
        {
            protected function fetchSuccessfulRunItems(int $articleId, ?int $preferRunId): \Illuminate\Support\Collection
            {
                return collect();
            }
        };
    }

    /**
     * @param  list<object>  $items  newest-first
     */
    private function resolveFromInjectedItems(array $items): ArticleGenerationSourceResult
    {
        $result = $this->tryResolveFromInjectedItems($items);
        if (! $result instanceof ArticleGenerationSourceResult) {
            throw new \InvalidArgumentException(ArticleGenerationInputResolver::REJECT_MESSAGE);
        }

        return $result;
    }

    /**
     * @param  list<object>  $items  newest-first
     */
    private function tryResolveFromInjectedItems(array $items): ?ArticleGenerationSourceResult
    {
        $outline = \Mockery::mock(ArticleOutlineResolver::class);
        $outline->shouldReceive('resolveMarkdown')->andReturn('');

        $resolver = new class($outline, $items) extends ArticleGenerationInputResolver
        {
            /**
             * @param  list<object>  $items
             */
            public function __construct(ArticleOutlineResolver $outline, private readonly array $items)
            {
                parent::__construct($outline);
            }

            protected function fetchSuccessfulRunItems(int $articleId, ?int $preferRunId): \Illuminate\Support\Collection
            {
                unset($articleId, $preferRunId);

                return collect($this->items);
            }
        };

        try {
            return $resolver->resolveForArticle($this->article(1));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
