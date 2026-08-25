<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use PHPUnit\Framework\TestCase;

final class AiRuntimeHealthNotificationTest extends TestCase
{
    public function test_connection_dedup_key_format(): void
    {
        $dedup = sprintf(
            'ai-connection-health:%d:%d:%s',
            5,
            9,
            AiRuntimeHealthStatus::BudgetLimited->value,
        );

        $this->assertSame('ai-connection-health:5:9:budget_limited', $dedup);
    }

    public function test_model_dedup_key_format(): void
    {
        $dedup = sprintf(
            'ai-model-health:%d:%d:%s',
            7,
            843,
            AiRuntimeHealthStatus::Degraded->value,
        );

        $this->assertSame('ai-model-health:7:843:degraded', $dedup);
    }

    public function test_subject_key_is_stable(): void
    {
        $this->assertSame('model:843', AiRuntimeHealthState::subjectKey('model', 843));
        $this->assertSame('connection:12', AiRuntimeHealthState::subjectKey('connection', 12));
    }
}
