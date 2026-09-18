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
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Support\MarkdownOutlineParser;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskOriginVariables;
use Omnichannel\Addons\SearchFoundation\Support\MarkdownSemanticKeywordsParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Behavioral: ancillary AI mutations only after verified body commit.
 * Conflict/stale → ZERO FAQ / focus / meta / title-protection writes.
 */
final class PromptTestPublishAncillaryOrderIntegrationTest extends TestCase
{
    private string $connection = 'omi_seo_ai';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->createCoreOptionTables();
    }

    private function createCoreOptionTables(): void
    {
        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->nullable();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->nullable();
        });
    }

    protected function tearDown(): void
    {
        foreach (['seo_project_tasks', 'article_meta', 'wordpress_article_links', 'articles'] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_stale_body_write_leaves_ancillary_state_unchanged(): void
    {
        $manualBody = '<p>MANUAL_NEWER_CONTENT</p>';
        $article = $this->makeArticle(8553, $manualBody, 'Manual Title');
        $article->articleMetas()->create([
            'meta_key' => 'seo_focus_keyword',
            'meta_value' => 'MANUAL_KEYWORD',
        ]);
        $article->articleMetas()->create([
            'meta_key' => 'seo_meta_description',
            'meta_value' => 'MANUAL_META',
        ]);
        $article->articleMetas()->create([
            'meta_key' => 'wp_faqs',
            'meta_value' => json_encode([['question' => 'Manual Q', 'answer' => 'Manual A']], JSON_THROW_ON_ERROR),
        ]);

        $this->seedTask(8799, titleProtection: 'user');

        $markdown = <<<'MD'
# AI Generated Title

This is AI_GENERATED_CONTENT that must not overwrite manual body. Enough words for a normal paragraph without FAQ markers.
MD;

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

        $result = $publisher->publishArticle($article->fresh() ?? $article, $markdown, [
            'focus_keyword' => 'AI_KEYWORD_SHOULD_NOT_APPLY',
            ProjectTaskOriginVariables::KEY => '8799',
        ]);

        self::assertFalse($result['success']);
        self::assertTrue((bool) ($result['conflict'] ?? false));
        self::assertSame('skipped', $result['ancillary_status'] ?? null);

        $fresh = SeoArticle::query()->find(8553);
        self::assertNotNull($fresh);
        self::assertSame($manualBody, (string) $fresh->body);
        self::assertSame('Manual Title', (string) $fresh->title);

        $metas = $fresh->articleMetas()->pluck('meta_value', 'meta_key')->all();
        self::assertSame('MANUAL_KEYWORD', (string) ($metas['seo_focus_keyword'] ?? ''));
        self::assertSame('MANUAL_META', (string) ($metas['seo_meta_description'] ?? ''));
        self::assertStringContainsString('Manual Q', (string) ($metas['wp_faqs'] ?? ''));

        $task = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()->find(8799);
        self::assertSame('user', (string) ($task?->title_protection ?? ''));
    }

    public function test_prepare_article_content_is_pure_no_db_writes(): void
    {
        $article = $this->makeArticle(1, '<p>old</p>', 'T');
        $metaCountBefore = $article->articleMetas()->count();
        $bodyBefore = (string) $article->body;

        $publisher = $this->publisher();
        $prepared = $publisher->prepareArticleContent($article, "# H1\n\nBody paragraph for prepare purity.");

        self::assertArrayHasKey('html', $prepared);
        self::assertArrayHasKey('content_hash', $prepared);
        self::assertArrayHasKey('faqs', $prepared);
        self::assertSame($bodyBefore, (string) ($article->fresh()?->body ?? ''));
        self::assertSame($metaCountBefore, (int) ($article->fresh()?->articleMetas()->count() ?? 0));
    }

    public function test_successful_body_commit_then_applies_ancillary_focus_and_meta(): void
    {
        $article = $this->makeArticle(42, '<p>OLD</p>', 'Old');
        $markdown = "# New Title\n\nDeterministic generated body for ancillary apply without FAQ or meta blocks.";

        $publisher = $this->publisher();
        $publisher->interceptBodyWriteForTests(
            static function (
                array $input,
                array $state,
                callable $legacyWrite,
            ): array {
                unset($input, $state);

                return $legacyWrite();
            },
        );

        $result = $publisher->publishArticle($article->fresh() ?? $article, $markdown, [
            'focus_keyword' => 'AI_FOCUS',
        ]);

        self::assertTrue($result['success']);
        self::assertContains($result['ancillary_status'] ?? '', ['applied', 'partial']);

        $fresh = SeoArticle::query()->find(42);
        self::assertNotNull($fresh);
        self::assertNotSame('<p>OLD</p>', (string) $fresh->body);
        self::assertSame(
            (string) ($result['expected_content_hash'] ?? ''),
            (string) ($result['persisted_content_hash'] ?? ''),
        );

        // Focus is written directly via article_meta; meta/FAQ bridges may be partial in this fixture.
        $focus = (string) ($fresh->articleMetas()->where('meta_key', 'seo_focus_keyword')->value('meta_value') ?? '');
        self::assertSame('AI_FOCUS', $focus);
    }

    public function test_source_order_is_prepare_body_then_ancillary(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(PromptTestPublishService::class))->getFileName(),
        );
        $preparePos = strpos($source, 'prepareArticleContent(');
        $bodyPos = strpos($source, 'runBodyWrite(');
        $ancillaryPos = strpos($source, 'commitAncillaryAfterBodyVerified(');
        self::assertNotFalse($preparePos);
        self::assertNotFalse($bodyPos);
        self::assertNotFalse($ancillaryPos);
        self::assertTrue($preparePos < $bodyPos);
        self::assertTrue($bodyPos < $ancillaryPos);
        self::assertStringContainsString('resolvePublishTitle', $source);
        self::assertStringNotContainsString(
            'persistGeneratedTitleProtection($variables, $title);'."\n\n".'        return $title;',
            $source,
        );
    }

    private function publisher(): PromptTestPublishService
    {
        putenv('AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true');
        $_ENV['AUTOMATION_MIGRATION_EMERGENCY_LEGACY'] = 'true';

        return new PromptTestPublishService(
            new MarkdownOutlineParser,
            new MarkdownSemanticKeywordsParser,
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
        Schema::connection($this->connection)->dropIfExists('seo_project_tasks');
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

        Schema::connection($this->connection)->create('seo_project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title_protection')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function makeArticle(int $id, string $body, string $title): SeoArticle
    {
        $article = new SeoArticle;
        $article->setConnection($this->connection);
        $article->forceFill([
            'id' => $id,
            'site_id' => null,
            'title' => $title,
            'body' => $body,
            'status' => 'draft',
        ]);
        $article->save();

        return $article->fresh() ?? $article;
    }

    private function seedTask(int $id, string $titleProtection): void
    {
        $task = new \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
        $task->setConnection($this->connection);
        $task->forceFill([
            'id' => $id,
            'project_id' => 900,
            'title_protection' => $titleProtection,
        ]);
        $task->save();
    }
}
