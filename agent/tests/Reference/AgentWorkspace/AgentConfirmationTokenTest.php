<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentConfirmationTokenService;
use PHPUnit\Framework\TestCase;

final class AgentConfirmationTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AgentConfirmationTokenService::clearMemory();
    }

    public function test_issue_returns_token_and_hash_without_persisting_raw_in_hash(): void
    {
        $service = new AgentConfirmationTokenService;
        $issued = $service->issue([
            'actor_id' => 9,
            'tenant_ref' => 't1',
            'site_ref' => 's1',
            'conversation_id' => 3,
            'execution_ref' => 'aex_1',
            'skill_key' => 'content_project.create',
            'capability_key' => 'content_project.create',
            'input_hash' => $service->hashInput(['project_name' => 'A']),
        ]);

        self::assertStringStartsWith('awconf_', $issued['token']);
        self::assertSame(64, strlen($issued['hash']));
        self::assertNotSame($issued['token'], $issued['hash']);
        self::assertSame($service->hashToken($issued['token']), $issued['hash']);
    }

    public function test_validate_ok_and_actor_mismatch(): void
    {
        $service = new AgentConfirmationTokenService;
        $inputHash = $service->hashInput(['a' => 1]);
        $bind = [
            'actor_id' => 9,
            'tenant_ref' => 't1',
            'site_ref' => 's1',
            'conversation_id' => 3,
            'execution_ref' => 'aex_1',
            'skill_key' => 'content_project.create',
            'capability_key' => 'content_project.create',
            'input_hash' => $inputHash,
        ];
        $issued = $service->issue($bind);

        self::assertSame('ok', $service->validate($issued['token'], $bind + ['stored_hash' => $issued['hash']]));

        $badActor = $bind;
        $badActor['actor_id'] = 99;
        self::assertSame('actor_mismatch', $service->validate($issued['token'], $badActor + ['stored_hash' => $issued['hash']]));
    }

    public function test_one_time_consume(): void
    {
        $service = new AgentConfirmationTokenService;
        $inputHash = $service->hashInput(['x' => 1]);
        $bind = [
            'actor_id' => 1,
            'tenant_ref' => 't',
            'site_ref' => 's',
            'conversation_id' => 1,
            'execution_ref' => 'aex_2',
            'skill_key' => 'k',
            'capability_key' => 'c',
            'input_hash' => $inputHash,
        ];
        $issued = $service->issue($bind);
        self::assertSame('ok', $service->validate($issued['token'], $bind + ['stored_hash' => $issued['hash']]));
        $service->consume($issued['token']);
        self::assertSame('already_used', $service->validate($issued['token'], $bind + ['stored_hash' => $issued['hash']]));
    }

    public function test_input_hash_mismatch(): void
    {
        $service = new AgentConfirmationTokenService;
        $bind = [
            'actor_id' => 1,
            'tenant_ref' => 't',
            'site_ref' => 's',
            'conversation_id' => 1,
            'execution_ref' => 'aex_3',
            'skill_key' => 'k',
            'capability_key' => 'c',
            'input_hash' => $service->hashInput(['a' => 1]),
        ];
        $issued = $service->issue($bind);
        $bind['input_hash'] = $service->hashInput(['a' => 2]);
        self::assertSame('input_mismatch', $service->validate($issued['token'], $bind + ['stored_hash' => $issued['hash']]));
    }

    public function test_mask_hash_never_returns_raw_token(): void
    {
        $service = new AgentConfirmationTokenService;
        $hash = str_repeat('ab', 32);
        $masked = $service->maskHash($hash);
        self::assertStringNotContainsString(str_repeat('ab', 32), $masked);
        self::assertStringContainsString('…', $masked);
    }
}
