<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher;
use Omnichannel\Addons\Agent\Automation\Registry\ActionHandlerRegistrar;
use Omnichannel\Addons\Agent\Automation\Runtime\CatalogBusinessActionDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Architecture guards for Action Runtime cutover (local mutation callers).
 */
final class AutomationActionCutoverArchitectureTest extends TestCase
{
    public function test_business_action_dispatcher_exists(): void
    {
        self::assertTrue(interface_exists(BusinessActionDispatcher::class));
        self::assertTrue(class_exists(CatalogBusinessActionDispatcher::class));
    }

    public function test_approve_and_domain_link_list_actions_registered(): void
    {
        $handlers = ActionHandlerRegistrar::handlers();
        $keys = [];
        foreach ($handlers as $class) {
            $keys[] = $class::definition()->key;
        }

        self::assertContains('article.approve', $keys);
        self::assertContains('keyword.domain_link_list.sync', $keys);
        self::assertContains('article.content.update', $keys);
        self::assertContains('article.seo_meta.update', $keys);
    }

    public function test_editor_controller_does_not_inject_persist_service(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src'.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Controllers'
            .DIRECTORY_SEPARATOR.'ArticleEditorSyncController.php';
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString('ArticleEditorPersistService', $source);
        self::assertStringContainsString('BusinessActionDispatcher', $source);
        self::assertStringContainsString('article.content.update', $source);
        self::assertStringContainsString('article.seo_meta.update', $source);
    }

    public function test_persist_local_does_not_emit_business_hook(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src'.DIRECTORY_SEPARATOR.'Services'
            .DIRECTORY_SEPARATOR.'ArticleEditorPersistService.php';
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString('BusinessHookEmitter', $source);
        self::assertStringNotContainsString('articleContentUpdated', $source);
    }

    public function test_seo_meta_save_does_not_emit_business_hook(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src'.DIRECTORY_SEPARATOR.'Services'
            .DIRECTORY_SEPARATOR.'ArticleEditorSeoMetaService.php';
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString('BusinessHookEmitter', $source);
        self::assertStringNotContainsString('articleContentUpdated', $source);
    }

    public function test_keyword_observer_does_not_call_domain_link_list_service(): void
    {
        $path = ProjectRoot::addonsPath().'/search-foundation/src'.DIRECTORY_SEPARATOR.'Observers'
            .DIRECTORY_SEPARATOR.'KeywordLinkListSyncObserver.php';
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString('DomainLinkListKeywordSyncService', $source);
        self::assertStringContainsString('keywordSaved', $source);
    }

    public function test_task_workflow_keyword_sync_goes_through_action(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/src'.DIRECTORY_SEPARATOR.'Services'
            .DIRECTORY_SEPARATOR.'TaskWorkflowTestRunner.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('keyword.vocabulary.save', $source);
        self::assertStringNotContainsString(
            'return $this->keywordResearch->syncVocabularyKeywords',
            $source,
        );
    }

    public function test_approval_caller_uses_action_dispatcher(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src'.DIRECTORY_SEPARATOR.'Filament'.DIRECTORY_SEPARATOR.'Resources'
            .DIRECTORY_SEPARATOR.'ArticleResource.php';
        $source = (string) file_get_contents($path);
        $submitPos = strpos($source, 'function submitStaffEditingComplete');
        self::assertNotFalse($submitPos);
        $chunk = substr($source, (int) $submitPos, 1800);

        self::assertStringContainsString('article.approve', $chunk);
        self::assertStringContainsString('BusinessActionDispatcher', $chunk);
        self::assertStringNotContainsString('approveLinkedProject($article, $user)', $chunk);
    }
}
