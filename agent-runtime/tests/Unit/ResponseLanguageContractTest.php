<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentToolConfirmationProposal;
use PHPUnit\Framework\TestCase;

final class ResponseLanguageContractTest extends TestCase
{
    public function test_new_decision_requires_supported_response_language(): void
    {
        $parser = new RetrievalDecisionParser();
        $vi = $parser->parse($this->decision('vi'));
        $en = $parser->parse($this->decision('en'));

        self::assertSame('vi', $vi->responseLanguage);
        self::assertSame('en', $en->responseLanguage);

        foreach ([null, 'fr'] as $language) {
            try {
                $parser->parse($this->decision($language));
                self::fail('Missing or unsupported response language must fail closed.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('response_language', $error->getMessage());
            }
        }
    }

    public function test_language_survives_frozen_confirmation_proposal(): void
    {
        $decision = (new RetrievalDecisionParser())->parse($this->decision('vi', 'gsc.performance'));
        $proposal = AgentToolConfirmationProposal::fromDecision($decision, AgentProjectScope::site(6));

        self::assertNotNull($proposal);
        self::assertSame('vi', $proposal->responseLanguage);
        self::assertSame('vi', AgentToolConfirmationProposal::fromArray($proposal->toArray())->responseLanguage);
    }

    private function decision(?string $language, string $capability = 'site.knowledge'): string
    {
        $decision = [
            'is_in_scope' => true,
            'intent' => 'analyze current SEO',
            'primary_capability' => $capability,
            'capabilities' => [$capability],
            'parameters' => [],
            'requires_parameter_extraction' => false,
            'requires_user_confirmation' => false,
            'response_template' => 'report',
        ];
        if ($language !== null) {
            $decision['response_language'] = $language;
        }

        return json_encode($decision, JSON_THROW_ON_ERROR);
    }
}
