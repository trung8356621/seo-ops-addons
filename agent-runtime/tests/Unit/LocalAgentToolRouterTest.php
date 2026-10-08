<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Routing\CatalogToolRouteAuthority;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;
use Omnichannel\Addons\AgentRuntime\Routing\ToolIntentMatcher;
use Omnichannel\Addons\AgentRuntime\Routing\ToolIntentMatchResult;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LocalAgentToolRouterTest extends TestCase
{
    #[Test]
    public function obvious_phrase_selects_the_capability_without_semantic_score(): void
    {
        $router = $this->router(new FrozenToolIntentMatcher(new ToolIntentMatchResult('none', [])));
        $route = $router->route('tìm bài SEO kém');

        self::assertSame('confident', $route->outcome);
        self::assertSame('seo_audit.worst_articles', $route->capability);
        self::assertTrue($route->catalogAuthorized);
        self::assertSame('explicit', $route->evidenceKind);
        self::assertFalse($route->executesTool());
    }

    #[Test]
    public function two_explicit_capabilities_stay_ambiguous(): void
    {
        $router = $this->router(new FrozenToolIntentMatcher(new ToolIntentMatchResult('confident', [
            ['ref' => 'gsc.performance', 'score' => 0.99, 'lexical' => false, 'semantic_score' => 0.99],
        ])));
        $route = $router->route('traffic tháng này và draft hiện tại');

        self::assertSame('ambiguous', $route->outcome);
        self::assertNull($route->capability);
        self::assertFalse($route->catalogAuthorized);
    }

    #[Test]
    public function unmatched_message_stays_unresolved(): void
    {
        $router = $this->router(new FrozenToolIntentMatcher(new ToolIntentMatchResult('none', [])));
        $route = $router->route('thời tiết hôm nay thế nào');

        self::assertSame('none', $route->outcome);
        self::assertNull($route->capability);
    }

    #[Test]
    public function unavailable_capability_is_rejected_even_with_a_high_score(): void
    {
        $authority = new CatalogToolRouteAuthority();
        self::assertFalse($authority->accepts('seo_audit.publish'));

        $router = $this->router(new FrozenToolIntentMatcher(new ToolIntentMatchResult('confident', [
            ['ref' => 'seo_audit.publish', 'score' => 0.99, 'lexical' => false, 'semantic_score' => 0.99],
        ])));
        $route = $router->route('hãy làm điều không có ví dụ tường minh');

        self::assertSame('rejected', $route->outcome);
        self::assertSame('seo_audit.publish', $route->capability);
        self::assertFalse($route->catalogAuthorized);
        self::assertFalse($route->executesTool());
    }

    #[Test]
    public function enabled_local_router_does_not_call_the_decision_model(): void
    {
        config(['agent-runtime.local_tool_router.enabled' => true]);
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->never())->method('complete');

        $coordinator = new AgentTurnCoordinator(
            new AgentModelInputBuilder(promptBindings: new class implements ResolvesSettingsPromptHook {
                public function resolveSettingsHook(string $hookKey): SeoPrompt
                {
                    $prompt = new SeoPrompt();
                    $prompt->markdown_content = 'local router test';

                    return $prompt;
                }
            }),
            $decisions,
            new RetrievalDecisionParser(),
            new RetrievalExecutor(
                new RetrievalPlanner(),
                new SeoAccessExecutor(
                    new class implements SeoAccessTransport {
                        public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
                        {
                            throw new \LogicException('SEO Access must not run for an unconfirmed tool route.');
                        }
                    },
                    new class implements SeoAccessCredential {
                        public function bearer(): ?string
                        {
                            return null;
                        }
                    },
                    new SeoAccessUrlPolicy(),
                    'https://app.example.test',
                ),
            ),
            $answers,
            new AgentResponseParser(),
            localToolRouter: $this->router(new FrozenToolIntentMatcher(new ToolIntentMatchResult('none', []))),
        );

        $result = $coordinator->send(1, AgentProjectScope::site(4), 'tìm bài SEO kém', []);

        self::assertNotNull($result->confirmationProposal);
        self::assertSame('seo_audit.worst_articles', $result->confirmationProposal->primaryCapability);
        self::assertFalse($result->answerModelCalled);
    }

    private function router(ToolIntentMatcher $matcher): LocalAgentToolRouter
    {
        return new LocalAgentToolRouter($matcher);
    }
}

final class FrozenToolIntentMatcher implements ToolIntentMatcher
{
    public function __construct(private readonly ToolIntentMatchResult $result) {}

    public function match(string $query, array $intents): ToolIntentMatchResult
    {
        return $this->result;
    }
}
