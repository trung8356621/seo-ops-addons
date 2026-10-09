<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Welcome\AgentWelcomeQuestions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentWelcomeQuestionsTest extends TestCase
{
    #[Test]
    public function user_questions_are_kept_by_module_and_system_rows_are_ignored(): void
    {
        $saved = AgentWelcomeQuestions::sanitize([
            'seo_audit' => [
                ['id' => 'seo_audit.worst', 'text' => 'System copy', 'system' => true],
                ['id' => 'user-1', 'text' => 'Bài nào cần viết lại?'],
            ],
            'keywords' => [
                ['id' => 'user-2', 'text' => 'Từ khóa thương hiệu của site?'],
            ],
            'agent_testing' => [
                ['id' => 'x', 'text' => 'Không được lưu'],
            ],
        ]);

        self::assertSame(['user-1'], array_column($saved['seo_audit'], 'id'));
        self::assertSame('Từ khóa thương hiệu của site?', $saved['keywords'][0]['text']);
        self::assertArrayNotHasKey('agent_testing', $saved);

        $merged = AgentWelcomeQuestions::merge($saved);
        $audit = $merged[0];
        self::assertSame('seo_audit', $audit['id']);
        self::assertTrue($audit['questions'][0]['system']);
        self::assertFalse($audit['questions'][3]['system']);
        self::assertSame('user-1', $audit['questions'][3]['id']);
    }
}
