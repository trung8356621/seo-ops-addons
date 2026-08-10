<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands\ApplyArticleContentArtifactCommand;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands\ApplyArticleOutlineArtifactCommand;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands\BulkDeleteArticleAiArtifactsCommand;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands\DeleteArticleAiArtifactCommand;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands\ListArticleAiHistoryCommand;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands\PreviewArticleAiArtifactCommand;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Contracts\ArticleAiHistoryCommand;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplicationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticleAiHistoryActionsContractTest extends TestCase
{
    public function test_all_commands_implement_contract_and_expose_stable_names(): void
    {
        $cases = [
            [new ListArticleAiHistoryCommand(1, [1]), 'article_ai_history.list'],
            [new PreviewArticleAiArtifactCommand(1, 'pr:1', [1]), 'article_ai_history.preview'],
            [new ApplyArticleOutlineArtifactCommand(1, 'pr:1', [1], 9), 'article_ai_history.apply_outline'],
            [new ApplyArticleContentArtifactCommand(1, 'pr:1', [1], 9), 'article_ai_history.apply_content'],
            [new DeleteArticleAiArtifactCommand(1, 'pr:1', [1], 9), 'article_ai_history.delete'],
            [new BulkDeleteArticleAiArtifactsCommand(1, ['pr:1', 'pr:2'], [1], 9), 'article_ai_history.bulk_delete'],
        ];

        foreach ($cases as [$command, $expectedName]) {
            self::assertInstanceOf(ArticleAiHistoryCommand::class, $command);
            self::assertSame($expectedName, $command->name());
        }
    }

    public function test_apply_commands_default_confirm_dirty_to_false(): void
    {
        $outline = new ApplyArticleOutlineArtifactCommand(1, 'pr:1', [1], 9);
        $content = new ApplyArticleContentArtifactCommand(1, 'pr:1', [1], 9);

        self::assertFalse($outline->confirmDirty);
        self::assertFalse($content->confirmDirty);
    }

    public function test_delete_commands_default_confirm_previously_applied_to_false(): void
    {
        $delete = new DeleteArticleAiArtifactCommand(1, 'pr:1', [1], 9);
        $bulk = new BulkDeleteArticleAiArtifactsCommand(1, ['pr:1'], [1], 9);

        self::assertFalse($delete->confirmPreviouslyApplied);
        self::assertFalse($bulk->confirmPreviouslyApplied);
        self::assertNull($delete->reason);
        self::assertNull($bulk->reason);
    }

    public function test_application_service_exposes_all_facade_methods(): void
    {
        $ref = new ReflectionClass(ArticleAiHistoryApplicationService::class);

        foreach ([
            'list',
            'preview',
            'applyOutline',
            'applyContent',
            'delete',
            'bulkDelete',
            'undoPending',
            'commitPendingOnSave',
        ] as $method) {
            self::assertTrue($ref->hasMethod($method), "Missing method: {$method}");
            self::assertTrue($ref->getMethod($method)->isPublic(), "Method not public: {$method}");
        }
    }

    public function test_commands_are_not_registered_on_content_project_capability_registry(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src'
            .DIRECTORY_SEPARATOR.'Services'
            .DIRECTORY_SEPARATOR.'ContentProject'
            .DIRECTORY_SEPARATOR.'Application'
            .DIRECTORY_SEPARATOR.'Capabilities'
            .DIRECTORY_SEPARATOR.'ContentProjectCapabilityRegistry.php';

        $src = (string) file_get_contents($path);

        self::assertStringNotContainsString('ArticleAiHistory', $src);
        self::assertStringNotContainsString('article_ai_history.', $src);
    }
}
