<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit\System;

use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityRegistry;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;
use PHPUnit\Framework\TestCase;

/**
 * Non-SEO acceptance: System AI runs seeding.comment.generate without SEO imports in the handler path.
 */
final class SeedingSystemAiCapabilityProofTest extends TestCase
{
    public function test_handler_source_does_not_import_seo(): void
    {
        $path = dirname(__DIR__, 3).'/src/System/SeedingCommentGenerateCapabilityHandler.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString('Omnichannel\\Addons\\Seo\\', $source);
        self::assertStringNotContainsString('Omnichannel\\Addons\\ContentProjects\\', $source);
        self::assertStringNotContainsString('PromptRunnerService', $source);
        self::assertStringNotContainsString('SocialAiExecutionService', $source);
        self::assertStringContainsString('AiTextExecutionPort', $source);
        self::assertStringContainsString('seeding.comment.generate', $source);
        self::assertStringContainsString('fail closed', $source);
    }

    public function test_system_ai_executes_seeding_capability_via_text_port(): void
    {
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: SeedingCommentGenerateCapabilityHandler::KEY,
            owner: 'seeding',
            handler: new class implements \App\System\Capability\SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    $textPort = $context['system_ai_text_port'] ?? null;
                    if (! $textPort instanceof AiTextExecutionPort) {
                        return ['error' => 'missing_port'];
                    }
                    $generated = $textPort->generate('compiled', 'social.comment.generate', []);

                    return [
                        'comments' => ['c1', 'c2'],
                        'raw_output' => (string) ($generated['text'] ?? ''),
                        'seo_runtime_participated' => false,
                        'path' => 'system_ai_text_port',
                    ];
                }
            },
            sideEffectFree: true,
        ));

        $textPort = new class implements AiTextExecutionPort
        {
            public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
            {
                return [
                    'text' => "1. hello\n2. world",
                    'provider' => 'test',
                    'model' => 'test-model',
                    'physical_route' => '1|test|test-model',
                ];
            }
        };

        $transport = new LegacyLocalAiTransport($registry, $textPort);
        $result = $transport->execute(new AiExecutionRequest(
            capability: SeedingCommentGenerateCapabilityHandler::KEY,
            input: ['quantity' => 2, 'social' => 'threads'],
        ));

        self::assertSame('completed', $result->status);
        self::assertFalse($result->output['seo_runtime_participated'] ?? true);
        self::assertSame('system_ai_text_port', $result->output['path'] ?? null);
        self::assertSame(['c1', 'c2'], $result->output['comments'] ?? null);
    }
}
