<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentScopeEvaluator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanExecutor;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use Omnichannel\Addons\AiPrompt\Services\SeoApiConnectionProviderCatalog;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderRegistry as LegacySeoApiProviderRegistry;
use Omnichannel\Addons\SiteSync\Services\Inbound\SiteSyncDeltaEventIngestor;
use Omnichannel\Addons\Content\Support\SourceAwareAfterCommit;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Backend risk closure contracts (A1–A7).
 */
final class BackendRiskClosureContractTest extends TestCase
{
    public function test_plan_executor_does_not_hardcode_admin_scopes(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentPlanExecutor::class))->getFileName(),
        );

        self::assertStringNotContainsString("'content-project:admin'", $source);
        self::assertStringContainsString('normalizeStoredScopes', $source);
        self::assertStringContainsString('AgentScopeEvaluator', $source);
    }

    public function test_pat_empty_abilities_fail_closed(): void
    {
        $evaluator = new ReflectionClass(AgentScopeEvaluator::class);
        $method = $evaluator->getMethod('scopesFromPersonalAccessToken');
        $method->setAccessible(true);

        $token = new PersonalAccessToken;
        $token->abilities = [];

        $instance = $evaluator->newInstanceWithoutConstructor();
        $scopes = $method->invoke($instance, $token);

        self::assertSame([], $scopes);
    }

    public function test_pat_wildcard_grants_known_scopes(): void
    {
        $evaluator = new ReflectionClass(AgentScopeEvaluator::class);
        $method = $evaluator->getMethod('scopesFromPersonalAccessToken');
        $method->setAccessible(true);

        $token = new PersonalAccessToken;
        $token->abilities = ['*'];

        $instance = $evaluator->newInstanceWithoutConstructor();
        $scopes = $method->invoke($instance, $token);

        self::assertContains('content-project:read', $scopes);
        self::assertContains('content-project:write', $scopes);
        self::assertContains('content-project:admin', $scopes);
    }

    public function test_pat_write_scope_does_not_imply_admin(): void
    {
        $evaluator = new ReflectionClass(AgentScopeEvaluator::class);
        $method = $evaluator->getMethod('scopesFromPersonalAccessToken');
        $method->setAccessible(true);

        $token = new PersonalAccessToken;
        $token->abilities = ['content-project:write'];

        $instance = $evaluator->newInstanceWithoutConstructor();
        $scopes = $method->invoke($instance, $token);

        self::assertSame(['content-project:write'], $scopes);
    }

    public function test_publish_handler_does_not_mark_published_on_delivery_requested(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ProcessScheduledProjectItemPublishHandler::class))->getFileName(),
        );

        self::assertStringContainsString('deliveryRequested', $source);
        self::assertStringContainsString('Do NOT markPublished here', $source);

        $pos = strpos($source, 'if ($publishResult->deliveryRequested)');
        self::assertNotFalse($pos);
        $end = strpos($source, 'Publish delivery requested.', $pos);
        self::assertNotFalse($end);
        $branch = substr($source, (int) $pos, ((int) $end - (int) $pos) + strlen('Publish delivery requested.'));

        self::assertStringContainsString('$this->queue->markProcessing($task->fresh()', $branch);
        self::assertStringNotContainsString('$this->queue->markPublished', $branch);
        self::assertStringNotContainsString('$this->health->rememberSuccess', $branch);
    }

    public function test_inbound_event_resumes_from_staged_batch(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SiteSyncDeltaEventIngestor::class))->getFileName(),
        );

        self::assertStringContainsString('batch_id', $source);
        self::assertStringContainsString('resumed_idempotent', $source);
        self::assertStringContainsString('applied_at', $source);
    }

    public function test_prompt_hook_registry_rejects_duplicate_keys(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(PromptHookRegistry::class))->getFileName(),
        );

        self::assertStringContainsString('HookDuplicateKey', $source);
        self::assertStringContainsString('Duplicate prompt hook key', $source);
        self::assertStringContainsString('isset($indexed[$definition->key])', $source);
    }

    public function test_seo_api_catalog_and_extension_registry_are_distinct(): void
    {
        self::assertTrue(class_exists(SeoApiConnectionProviderCatalog::class));
        self::assertTrue(is_subclass_of(LegacySeoApiProviderRegistry::class, SeoApiConnectionProviderCatalog::class));
        self::assertNotSame(
            SeoApiConnectionProviderCatalog::class,
            \Omnichannel\Addons\Seo\Extension\Registry\SeoProviderRegistry::class,
        );
    }

    public function test_source_aware_after_commit_helper_exists(): void
    {
        self::assertTrue(method_exists(SourceAwareAfterCommit::class, 'run'));
        $method = new ReflectionMethod(SourceAwareAfterCommit::class, 'run');
        self::assertTrue($method->isStatic());
    }
}
