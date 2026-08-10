<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSyncController;
use Omnichannel\Addons\Content\Http\Requests\ArticleEditorActionRequest;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class ArticleEditorSyncConflictInputTest extends TestCase
{
    public function test_build_content_update_input_includes_expected_conflict_fields(): void
    {
        // Avoid container: BusinessActionDispatcher / ManualSync not bound in unit suite.
        $controller = (new ReflectionClass(ArticleEditorSyncController::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod($controller, 'buildContentUpdateInput');
        $method->setAccessible(true);

        $article = new SeoArticle(['id' => 42]);
        $bundle = [
            'html' => '<p>Body</p>',
            'expected_updated_at' => '2026-07-01T10:00:00+00:00',
            'expected_content_hash' => 'abc123deadbeef',
            'article_meta' => ['title' => 'Title'],
        ];

        $input = $method->invoke($controller, $article, $bundle, '<p>Body</p>');

        self::assertSame(42, $input['article_id'] ?? null);
        self::assertSame('<p>Body</p>', $input['content'] ?? null);
        self::assertSame('2026-07-01T10:00:00+00:00', $input['expected_updated_at'] ?? null);
        self::assertSame('abc123deadbeef', $input['expected_content_hash'] ?? null);
        self::assertSame('Title', $input['title'] ?? null);
    }

    public function test_action_request_allows_expected_conflict_fields(): void
    {
        $request = new ArticleEditorActionRequest;
        $validator = Validator::make([
            'html' => '<p>x</p>',
            'expected_updated_at' => '2026-07-01T10:00:00+00:00',
            'expected_content_hash' => hash('sha256', 'x'),
        ], $request->rules());

        self::assertFalse($validator->fails());
    }

    public function test_update_action_skips_conflict_when_force_overwrite_allowed(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/Actions/Article/UpdateArticleContentAction.php',
        );
        $access = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Support/SeoAccessControl.php',
        );
        $controller = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Http/Controllers/ArticleEditorSyncController.php',
        );

        self::assertStringContainsString('canForceArticleContentOverwrite', $source);
        self::assertStringContainsString('force_overwrite', $source);
        self::assertStringContainsString('function canForceArticleContentOverwrite', $access);
        self::assertStringContainsString(
            'self::rank(self::actualRole()) > self::rank(self::ROLE_CONTENT_MANAGER)',
            $access,
        );
        self::assertStringContainsString('User::ROLE_OWNER', $access);
        // Content write before bundle side-effects.
        $dispatchPos = strpos($controller, "dispatch(\n            'article.content.update'");
        $bundlePos = strpos($controller, '$this->bundleApply->apply(');
        self::assertNotFalse($dispatchPos);
        self::assertNotFalse($bundlePos);
        self::assertLessThan($bundlePos, $dispatchPos);
    }
}
