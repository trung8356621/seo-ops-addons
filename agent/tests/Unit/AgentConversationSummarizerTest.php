<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentSummarizationRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DefaultAgentConversationSummarizer;
use PHPUnit\Framework\TestCase;

final class AgentConversationSummarizerTest extends TestCase
{
    public function test_threshold_and_isolation_fallback(): void
    {
        $summarizer = new DefaultAgentConversationSummarizer(
            messageThreshold: 5,
            tokenThreshold: 1000,
        );

        self::assertFalse($summarizer->shouldSummarize(4, 100));
        self::assertTrue($summarizer->shouldSummarize(5, 100));
        self::assertTrue($summarizer->shouldSummarize(1, 1000));

        $summary = $summarizer->summarize(new AgentSummarizationRequest(
            messages: [
                ['role' => 'user', 'content' => 'Mục tiêu: tạo project tháng 8'],
                ['role' => 'assistant', 'content' => 'Đã ghi nhận'],
            ],
            workingContext: [
                'site_ref' => 'site_a',
                'project_ref' => 'cp_1',
                'connection_id' => 1,
            ],
        ));

        self::assertStringContainsString('tạo project', mb_strtolower($summary->text));
        self::assertSame('site_a', $summary->payload['active_refs']['site_ref'] ?? null);
        self::assertSame('cp_1', $summary->payload['active_refs']['project_ref'] ?? null);
    }
}
