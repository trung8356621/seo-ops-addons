<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Jobs\RunAgentAutomationJob;
use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncInboundEventJob;
use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncStepJob;
use Omnichannel\Addons\SearchFoundation\Observers\KeywordLinkListSyncObserver;
use Omnichannel\Addons\Content\Observers\SeoArticleObserver;
use App\Addons\SeoContentAi\Providers\SeoPanelProvider;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentGateway;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\Publishing\Application\Publishing\ContentPublisher;
use Omnichannel\Addons\Publishing\Application\Publishing\ContentPublisherRegistry;
use Omnichannel\Addons\Publishing\Application\Publishing\PublisherResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Architecture Lock hardening contracts â€” fail closed, no silent overwrite / duplicate queue.
 */
final class ArchitectureHardeningLockContractTest extends TestCase
{
    public function test_content_publisher_registry_rejects_duplicate_key(): void
    {
        $registry = new ContentPublisherRegistry;
        $publisher = $this->createStub(ContentPublisher::class);

        $registry->register('wordpress', $publisher);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');
        $registry->register('wordpress', $publisher);
    }

    public function test_site_sync_and_agent_jobs_are_unique(): void
    {
        self::assertFalse(
            is_a(ProcessSiteSyncStepJob::class, ShouldBeUnique::class, true),
            'Step continuation jobs must not share a run-level unique lock; a running job dispatches the next step before its lock would be released.'
        );
        self::assertTrue(is_a(ProcessSiteSyncInboundEventJob::class, ShouldBeUnique::class, true));
        self::assertTrue(is_a(RunAgentAutomationJob::class, ShouldBeUnique::class, true));

        self::assertSame('site-sync-inbound-event:7', (new ProcessSiteSyncInboundEventJob(7))->uniqueId());
        self::assertSame('agent-automation-run:9', (new RunAgentAutomationJob(9))->uniqueId());
    }

    public function test_observers_defer_side_effects_safely(): void
    {
        self::assertTrue((new ReflectionClass(SeoArticleObserver::class))->getProperty('afterCommit')->getDefaultValue());

        $keywordSource = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLinkListSyncObserver::class))->getFileName(),
        );
        self::assertStringContainsString('afterKeywordCommit', $keywordSource);
        self::assertStringContainsString('afterCommit', $keywordSource);
        self::assertStringContainsString('function updating', $keywordSource);
        // Must NOT defer updating via class-level afterCommit (breaks previousPhrase capture).
        self::assertStringNotContainsString('public bool $afterCommit = true', $keywordSource);
    }

    public function test_agent_cp_write_path_stays_gateway_to_command_bus(): void
    {
        $appService = (string) file_get_contents(
            (string) (new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName(),
        );
        $agentGateway = (string) file_get_contents(
            (string) (new ReflectionClass(AgentGateway::class))->getFileName(),
        );
        $cpGateway = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName(),
        );

        self::assertStringContainsString('AgentGateway', $appService);
        // Docblock may mention CommandBus as forbidden; assert no import/injection.
        self::assertStringNotContainsString(
            'use App\\Addons\\SeoContentAi\\Services\\ContentProject\\Application\\ContentProjectCommandBus',
            $appService,
        );
        self::assertStringNotContainsString('ContentProjectCommandBus $', $appService);
        self::assertStringNotContainsString('commandBus->dispatch', $appService);

        self::assertStringContainsString(ContentProjectAgentGateway::class, $agentGateway);

        self::assertStringContainsString('CanonicalCapabilityRegistry', $cpGateway);
        self::assertStringContainsString('commandBus->dispatch', $cpGateway);
        self::assertStringContainsString(ContentProjectCommandBus::class, $cpGateway);
    }

    public function test_publish_handler_resolves_via_publisher_resolver_not_concrete(): void
    {
        $handlerPath = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/ProcessScheduledProjectItemPublishHandler.php';
        self::assertFileExists($handlerPath);
        $source = (string) file_get_contents($handlerPath);

        self::assertStringContainsString(PublisherResolver::class, $source);
        self::assertStringNotContainsString('Extension\\Builtin\\Wordpress\\WordPressPublisher', $source);
    }

    public function test_global_ai_chat_routes_remain_retired(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoPanelProvider::class))->getFileName(),
        );

        self::assertStringNotContainsString('GlobalAiChatController', $source);
        self::assertStringNotContainsString('seo.global-ai-chat', $source);
    }

    public function test_command_bus_dispatch_requires_actor_context(): void
    {
        $method = new ReflectionMethod(ContentProjectCommandBus::class, 'dispatch');
        $params = $method->getParameters();

        self::assertGreaterThanOrEqual(2, count($params));
        self::assertSame('command', $params[0]->getName());
        self::assertSame('actor', $params[1]->getName());
        self::assertNotNull($params[1]->getType());
        self::assertStringContainsString('ActorContext', (string) $params[1]->getType());
    }
}
