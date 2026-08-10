<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\AgentContextBudgetManager;
use PHPUnit\Framework\TestCase;

final class AgentContextBudgetManagerTest extends TestCase
{
    public function test_prioritizes_current_message_and_does_not_truncate_json_midway(): void
    {
        $manager = new AgentContextBudgetManager(defaultLimitTokens: 80, charsPerToken: 4.0);

        $fitted = $manager->fit([
            'current_message' => 'KEEP_ME',
            'system_policy' => ['rules' => ['safe']],
            'working_context' => ['site_ref' => 's1'],
            'recent_messages' => array_fill(0, 20, ['role' => 'user', 'content' => str_repeat('x', 40)]),
            'skill_catalog' => array_fill(0, 20, ['key' => 'skill', 'description' => str_repeat('y', 40)]),
            'summary' => str_repeat('z', 200),
        ], modelContextLimit: 200, outputReserve: 20);

        self::assertSame('KEEP_ME', $fitted['sections']['current_message']);
        self::assertArrayHasKey('system_policy', $fitted['sections']);
        self::assertNotEmpty($fitted['dropped']);
        // Encode remaining sections — must still be valid JSON object.
        $json = json_encode($fitted['sections']);
        self::assertNotFalse($json);
        self::assertIsArray(json_decode($json, true));
    }

    public function test_estimate_tokens_positive(): void
    {
        $manager = new AgentContextBudgetManager;
        self::assertGreaterThan(0, $manager->estimateTokens(['a' => 'hello']));
    }
}
