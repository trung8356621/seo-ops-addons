<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\Ai\ClaudeMessagesClient;
use Omnichannel\Addons\AiPrompt\Services\AiExecutionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ClaudeWhitespaceTextBlockContractTest extends TestCase
{
    public function test_execution_service_preserves_whitespace_only_text_blocks(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(AiExecutionService::class))->getFileName() ?: '',
        );

        self::assertStringNotContainsString('filled($chunk->text)', $src);
        self::assertStringContainsString("\$chunk->text !== ''", $src);
        self::assertStringContainsString('is_string($chunk->text)', $src);
    }

    public function test_messages_client_preserves_whitespace_only_text_blocks(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ClaudeMessagesClient::class))->getFileName() ?: '',
        );

        self::assertStringNotContainsString('filled($chunk->text)', $src);
        self::assertStringContainsString("\$chunk->text !== ''", $src);
        self::assertStringContainsString('is_string($chunk->text)', $src);
    }
}
