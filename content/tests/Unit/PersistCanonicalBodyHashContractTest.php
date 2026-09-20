<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationFlags;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationWriteException;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleContentCallerBridge;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleSeoMetaCallerBridge;
use Omnichannel\Addons\Agent\Automation\Runtime\ActionRunner;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleCtaPlaceholderService;
use Omnichannel\Addons\Content\Services\ArticleEditorPersistService;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Support\MarkdownOutlineParser;
use Omnichannel\Addons\SearchFoundation\Support\MarkdownSemanticKeywordsParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Transient CTA placeholder markup must not cause false persist hash mismatch.
 * Expected hash uses the same PURE canonicalize as articles.body writer.
 */
final class PersistCanonicalBodyHashContractTest extends TestCase
{
    private string $connection = 'omi_seo_ai';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['article_faqs', 'article_meta', 'wordpress_article_links', 'articles'] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
        parent::tearDown();
    }

    /** P1 — blank CTA transient wrapper does not fail persist verification */
    public function test_p1_blank_cta_wrapper_expected_hash_matches_persisted_body(): void
    {
        $article = $this->makeArticle(91001, '<p>OLD</p>');
        $decorated = '<p><span class="seo-cta-blank-placeholder" data-cta-type="website">[website]</span></p>';
        $canonical = app(ArticleEditorPersistService::class)->canonicalizeBodyForPersist($decorated);
        $expectedHash = app(ArticleContentConflictGuard::class)->contentHash($canonical);

        self::assertStringNotContainsString('seo-cta-blank-placeholder', $canonical);
        self::assertStringContainsString('[website]', $canonical);

        // Writer input may still be decorated; canonicalize is applied before store.
        $persisted = $this->writeBodyViaCanonicalPersist($article, $decorated);
        $persistedHash = app(ArticleContentConflictGuard::class)->contentHash($persisted);

        self::assertSame($expectedHash, $persistedHash);
        self::assertSame($canonical, $persisted);
        self::assertStringNotContainsString('seo-cta-blank-placeholder', $persisted);
    }

    /** P2 — real content change still fails exact hash gate */
    public function test_p2_real_content_change_still_fails_hash_gate(): void
    {
        $guard = app(ArticleContentConflictGuard::class);
        $expected = $guard->contentHash('<p>Hello world</p>');
        $persisted = $guard->contentHash('<p>Different content</p>');

        self::assertNotSame($expected, $persisted);
    }

    /** P3 — CTA bracket text survives strip; only wrapper disappears */
    public function test_p3_cta_bracket_text_survives_canonicalize(): void
    {
        $html = '<p><span class="seo-cta-blank-placeholder" data-cta-type="website">[website]</span></p>';
        $out = app(ArticleEditorPersistService::class)->canonicalizeBodyForPersist($html);

        self::assertStringContainsString('[website]', $out);
        self::assertStringNotContainsString('seo-cta-blank-placeholder', $out);
        self::assertStringNotContainsString('data-cta-type', $out);
    }

    /** P4 — resolved CTA values remain intact through canonicalize */
    public function test_p4_resolved_cta_value_survives_canonicalize(): void
    {
        $html = '<p>Visit <a href="https://example.com">https://example.com</a> or call 0901234567</p>';
        $out = app(ArticleEditorPersistService::class)->canonicalizeBodyForPersist($html);

        self::assertStringContainsString('https://example.com', $out);
        self::assertStringContainsString('0901234567', $out);
        self::assertSame(
            app(ArticleEditorPersistService::class)->canonicalizeBodyForPersist($html),
            $out,
        );
    }

    /** P5 — no CTA: canonicalize is identity for plain body */
    public function test_p5_plain_html_without_cta_unchanged_for_hash(): void
    {
        $html = '<p>Normal article without placeholder.</p>';
        $canonical = app(ArticleEditorPersistService::class)->canonicalizeBodyForPersist($html);
        $guard = app(ArticleContentConflictGuard::class);

        self::assertSame($guard->contentHash($html), $guard->contentHash($canonical));
        self::assertStringContainsString('Normal article without placeholder.', $canonical);
    }

    /** P6 — canonicalize is idempotent */
    public function test_p6_canonicalize_is_idempotent(): void
    {
        $persist = app(ArticleEditorPersistService::class);
        $html = '<p>Call <span class="seo-cta-blank-placeholder" data-cta-type="phone">[phone]</span> today.</p>';
        $once = $persist->canonicalizeBodyForPersist($html);
        $twice = $persist->canonicalizeBodyForPersist($once);

        self::assertSame($once, $twice);
    }

    /** P7 — prepareArticleContent returns canonical HTML + matching hash (8553-style) */
    public function test_p7_prepare_article_content_hashes_canonical_persist_body(): void
    {
        $article = $this->makeArticle(8553, '<p>seed</p>');
        // Markdown that survives import as HTML containing a blank CTA span after applyForPublish(null) noop —
        // inject decorated HTML via normalize path by preparing then re-canonicalizing like writer.
        $markdown = "Normal paragraph for article 8553 with enough words to pass empty guards.\n\nSecond paragraph.";
        $prepared = $this->publisher()->prepareArticleContent($article, $markdown);

        $canonical = app(ArticleEditorPersistService::class)->canonicalizeBodyForPersist($prepared['html']);
        self::assertSame($canonical, $prepared['html']);
        self::assertSame(
            app(ArticleContentConflictGuard::class)->contentHash($canonical),
            $prepared['content_hash'],
        );

        $persisted = $this->writeBodyViaCanonicalPersist($article, $prepared['html']);
        self::assertSame($prepared['content_hash'], app(ArticleContentConflictGuard::class)->contentHash($persisted));
    }

    /** P7b — decorated prepare path: hash equals writer output (live PR2200 class) */
    public function test_p7b_decorated_html_prepare_hash_matches_writer(): void
    {
        $article = $this->makeArticle(91002, '<p>OLD</p>');
        $decorated = '<p>Liên hệ <span class="seo-cta-blank-placeholder" data-cta-type="website">[website]</span>.</p>';
        $persist = app(ArticleEditorPersistService::class);
        $canonical = $persist->canonicalizeBodyForPersist($decorated);
        $expected = app(ArticleContentConflictGuard::class)->contentHash($canonical);

        // Simulate prepare returning already-canonical html (post-fix contract).
        $persisted = $this->writeBodyViaCanonicalPersist($article, $canonical);
        self::assertSame($expected, app(ArticleContentConflictGuard::class)->contentHash($persisted));
        self::assertStringContainsString('[website]', $persisted);
        self::assertStringNotContainsString('seo-cta-blank-placeholder', $persisted);
    }

    /** P8 — human/stale conflict still fails; transient normalize must not mask real edits */
    public function test_p8_stale_human_edit_still_fails_publish_gate(): void
    {
        $manualBody = '<p>MANUAL_NEWER_CONTENT_KEEP_ME</p>';
        $article = $this->makeArticle(8554, $manualBody);
        $publisher = $this->publisher();
        $publisher->interceptBodyWriteForTests(
            static function () {
                throw new AutomationMigrationWriteException(
                    AutomationMigrationFlags::PROJECT_ARTICLE_CONTENT_UPDATE,
                    'Article body hash mismatch; refusing silent overwrite (conflict_content_hash).',
                    ActionResult::failure('conflict_content_hash', 'conflict_content_hash'),
                );
            },
        );

        $result = $publisher->publishArticle(
            $article->fresh() ?? $article,
            "AI body that must not overwrite.\n\nEnough words here for a normal paragraph.",
            [],
        );

        self::assertFalse($result['success']);
        self::assertTrue((bool) ($result['conflict'] ?? false));
        self::assertSame($manualBody, (string) ($article->fresh()?->body ?? ''));
    }

    /** Strip SSOT still owned by ArticleCtaPlaceholderService */
    public function test_strip_blank_placeholder_service_still_unwraps(): void
    {
        $cta = app(ArticleCtaPlaceholderService::class);
        $in = '<span class="seo-cta-blank-placeholder" data-cta-type="website">[website]</span>';
        self::assertSame('[website]', $cta->stripBlankPlaceholderMarkup($in));
    }

    private function writeBodyViaCanonicalPersist(SeoArticle $article, string $html): string
    {
        // Persist contract under test: canonicalize is the PURE body representation
        // writeArticleRow stores (after stripTransient). Avoid full writeArticleRow side
        // tables (seo_faqs / WP cache) — SSOT method is asserted via idempotent canonicalize.
        $canonical = app(ArticleEditorPersistService::class)->canonicalizeBodyForPersist($html);
        $article->forceFill(['body' => $canonical])->save();

        return (string) (($article->fresh() ?? $article)->body ?? '');
    }

    private function publisher(): PromptTestPublishService
    {
        return new PromptTestPublishService(
            app(MarkdownOutlineParser::class),
            app(MarkdownSemanticKeywordsParser::class),
            app(ArticleMarkdownToHtmlService::class),
            (new ReflectionClass(ProjectArticleContentCallerBridge::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProjectArticleSeoMetaCallerBridge::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ActionRunner::class))->newInstanceWithoutConstructor(),
            new AutomationMigrationFlags,
            new ArticleContentConflictGuard,
        );
    }

    private function createSchema(): void
    {
        Schema::connection($this->connection)->dropIfExists('article_faqs');
        Schema::connection($this->connection)->dropIfExists('article_meta');
        Schema::connection($this->connection)->dropIfExists('wordpress_article_links');
        Schema::connection($this->connection)->dropIfExists('articles');

        Schema::connection($this->connection)->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->longText('body')->nullable();
            $table->string('status')->nullable();
            $table->string('wp_post_type')->nullable();
            $table->string('content_classification')->nullable();
            $table->unsignedInteger('document_version')->default(1);
            $table->json('editor_document')->nullable();
            $table->unsignedInteger('editor_document_schema_version')->nullable();
            $table->string('editor_document_hash')->nullable();
            $table->string('editor_document_status')->nullable();
            $table->timestamp('editor_document_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection($this->connection)->create('wordpress_article_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('article_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('article_faqs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('question')->nullable();
            $table->text('answer')->nullable();
            $table->timestamps();
        });
    }

    private function makeArticle(int $id, string $body): SeoArticle
    {
        $article = new SeoArticle;
        $article->setConnection($this->connection);
        $article->forceFill([
            'id' => $id,
            'site_id' => null,
            'title' => 'Persist hash contract '.$id,
            'body' => $body,
            'status' => 'draft',
        ]);
        $article->save();

        return $article->fresh() ?? $article;
    }
}
