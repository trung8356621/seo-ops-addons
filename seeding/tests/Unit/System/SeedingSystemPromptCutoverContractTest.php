<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit\System;

use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Support\CapabilityModeResolver;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateHistoryService;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Services\SeedingSharedCommentPromptResolver;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * STEP 2A: Seeding comment generation enters SystemAiClient (no Social AI fallback).
 */
final class SeedingSystemPromptCutoverContractTest extends TestCase
{
    public function test_canonical_capability_key(): void
    {
        self::assertSame('seeding.comment.generate', SeedingCommentGenerateCapabilityHandler::KEY);
        self::assertSame(SeedingCommentGenerateCapabilityHandler::KEY, SeedingCommentGenerateService::CAPABILITY);
    }

    public function test_service_enters_system_ai_client_not_social_or_remote_http(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateService::class))->getFileName()
        );

        self::assertStringContainsString('SystemAiClient', $src);
        self::assertStringContainsString('AiExecutionRequest', $src);
        self::assertStringContainsString('seeding.comment.generate', $src);
        self::assertStringContainsString('CAPABILITY', $src);

        self::assertStringNotContainsString('SocialAiExecutionService', $src);
        self::assertStringNotContainsString('CanonicalAiTextExecutionService', $src);
        self::assertStringNotContainsString('RemoteHttpAiTransport', $src);
        self::assertStringNotContainsString('generateCommentsDetailed', $src);
        self::assertStringNotContainsString('OpenRouter', $src);
        self::assertStringNotContainsString('DeepSeek', $src);
    }

    public function test_handler_requires_text_port_fail_closed_no_social_fallback(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateCapabilityHandler::class))->getFileName()
        );

        self::assertStringContainsString('AiTextExecutionPort', $src);
        self::assertStringContainsString('fail closed', $src);
        self::assertStringContainsString('SocialCommentGenerateTask', $src);
        self::assertStringContainsString('SeedingSharedCommentPromptResolver', $src);
        self::assertStringNotContainsString('SocialAiExecutionService', $src);
        self::assertStringNotContainsString('social_ai_fallback', $src);
        self::assertStringNotContainsString('RemoteHttpAiTransport', $src);
        self::assertStringNotContainsString('Omnichannel\\Addons\\Seo\\', $src);
    }

    public function test_generation_via_system_ai_passes_capability_prompt_and_shape(): void
    {
        $captured = [];
        $shared = $this->fakeShared("Manager style GenZ-ish\n{{mcp_context}}\nEnd.");

        $sink = new \stdClass();
        $sink->items = [];
        $history = new class($sink) extends SeedingCommentGenerateHistoryService {
            public function __construct(private object $sink) {}

            public function record(array $payload): ?\Omnichannel\Addons\Seeding\Models\SeedingCommentGenerateLog
            {
                $this->sink->items[] = $payload;

                return null;
            }
        };

        $textPort = new class($captured) implements AiTextExecutionPort {
            /** @param array<string, mixed> $captured */
            public function __construct(private array &$captured) {}

            public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
            {
                $this->captured['compiled'] = $compiledPrompt;
                $this->captured['hook_key'] = $hookKey;
                $this->captured['options'] = $options;

                return [
                    'text' => json_encode(['comments' => ['c1', 'c2', 'c3']], JSON_UNESCAPED_UNICODE),
                    'provider' => 'deepseek',
                    'model' => 'deepseek-chat',
                    'physical_route' => '1|deepseek|deepseek-chat',
                ];
            }
        };

        $client = $this->buildClient(
            handler: new SeedingCommentGenerateCapabilityHandler(
                contextResolver: new SeedingSocialContextResolver(),
                sharedPrompt: $shared,
            ),
            textPort: $textPort,
        );

        $service = new SeedingCommentGenerateService(
            systemAi: $client,
            contextResolver: new SeedingSocialContextResolver(),
            sharedPrompt: $shared,
            history: $history,
        );
        $comments = $service->generateFromPayload([
            'content' => 'Balo laptop chống sốc',
            'social' => 'threads',
            'quantity' => 3,
            'topic_id' => 42,
        ]);

        self::assertSame(['c1', 'c2', 'c3'], $comments);
        self::assertSame(SeedingCommentGenerateCapabilityHandler::KEY, $captured['hook_key'] ?? null);
        self::assertStringContainsString("Manager style GenZ-ish\nBalo laptop chống sốc\nEnd.", (string) ($captured['compiled'] ?? ''));
        self::assertStringNotContainsString('Adapt naturally to the supplied social platform', (string) ($captured['compiled'] ?? ''));

        self::assertCount(1, $sink->items);
        $snap = $sink->items[0];
        self::assertSame('Balo laptop chống sốc', $snap['mcp_context']);
        self::assertSame("Manager style GenZ-ish\nBalo laptop chống sốc\nEnd.", $snap['final_prompt']);
        self::assertSame(SeedingCommentGenerateHistoryService::STATUS_SUCCESS, $snap['status']);
        self::assertSame(42, $snap['topic_id']);
        self::assertSame('deepseek', $snap['provider']);
        self::assertSame('deepseek-chat', $snap['model']);
    }

    public function test_system_execution_id_is_captured_on_completed_result(): void
    {
        $shared = $this->fakeShared('{{mcp_context}}');
        $textPort = new class implements AiTextExecutionPort {
            public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
            {
                unset($compiledPrompt, $hookKey, $options);

                return [
                    'text' => json_encode(['comments' => ['only']], JSON_UNESCAPED_UNICODE),
                    'provider' => 'test',
                    'model' => 'test-model',
                ];
            }
        };

        $client = $this->buildClient(
            handler: new SeedingCommentGenerateCapabilityHandler(
                sharedPrompt: $shared,
            ),
            textPort: $textPort,
        );

        $raw = $client->execute(new AiExecutionRequest(
            capability: SeedingCommentGenerateCapabilityHandler::KEY,
            input: ['content' => 'x', 'quantity' => 1, 'social' => 'threads'],
        ));

        self::assertSame('completed', $raw->status);
        self::assertNotSame('', $raw->id);
        self::assertStringStartsWith('ai_', $raw->id);
        self::assertSame(['only'], $raw->output['comments'] ?? null);
        self::assertSame('system_ai_text_port', $raw->output['path'] ?? null);
        self::assertSame(SeedingCommentGenerateCapabilityHandler::KEY, $raw->output['hook_key'] ?? null);

        $fetched = $client->getExecution($raw->id);
        self::assertNotNull($fetched);
        self::assertSame($raw->id, $fetched->id);
    }

    public function test_remote_failure_does_not_call_old_provider_path(): void
    {
        $oldPathCalled = false;
        $failingClient = new class($oldPathCalled) implements SystemAiClient {
            public function __construct(private bool &$oldPathCalled) {}

            public function execute(AiExecutionRequest $request): AiExecutionResult
            {
                return new AiExecutionResult(
                    id: 'ai_fail_closed',
                    status: 'failed',
                    capability: $request->capability,
                    output: [],
                    errorCode: 'remote_transport_unavailable',
                    errorMessage: 'Remote AI transport is unavailable',
                );
            }

            public function getExecution(string $id): ?AiExecutionResult
            {
                return null;
            }
        };

        $sink = new \stdClass();
        $sink->items = [];
        $history = new class($sink) extends SeedingCommentGenerateHistoryService {
            public function __construct(private object $sink) {}

            public function record(array $payload): ?\Omnichannel\Addons\Seeding\Models\SeedingCommentGenerateLog
            {
                $this->sink->items[] = $payload;

                return null;
            }
        };

        $service = new SeedingCommentGenerateService(
            systemAi: $failingClient,
            sharedPrompt: $this->fakeShared('{{mcp_context}}'),
            history: $history,
        );

        try {
            $service->generateFromPayload([
                'content' => 'should fail closed',
                'quantity' => 2,
                'social' => 'threads',
            ]);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Remote AI transport is unavailable', $e->getMessage());
        }

        self::assertFalse($oldPathCalled);
        self::assertCount(1, $sink->items);
        self::assertSame(SeedingCommentGenerateHistoryService::STATUS_FAILED, $sink->items[0]['status']);
        self::assertStringContainsString('system_ai_execution_id=ai_fail_closed', (string) $sink->items[0]['error_message']);
    }

    public function test_handler_without_text_port_fails_closed(): void
    {
        $handler = new SeedingCommentGenerateCapabilityHandler(
            sharedPrompt: $this->fakeShared('{{mcp_context}}'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('System AI text port is required');
        $handler->handle(['content' => 'x', 'quantity' => 1], []);
    }

    public function test_quota_link_share_report_remain_outside_ai_cutover_files(): void
    {
        $service = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateService::class))->getFileName()
        );
        $handler = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateCapabilityHandler::class))->getFileName()
        );

        foreach ([$service, $handler] as $src) {
            self::assertStringNotContainsString('LinkPool', $src);
            self::assertStringNotContainsString('max_comments', $src);
            self::assertStringNotContainsString('WebsiteShare', $src);
            self::assertStringNotContainsString('SeedingReport', $src);
            self::assertStringNotContainsString('localStorage', $src);
        }
    }

    private function fakeShared(string $body): SeedingSharedCommentPromptResolver
    {
        return new class($body) extends SeedingSharedCommentPromptResolver {
            public function __construct(private string $body) {}

            public function resolveActive(): array
            {
                $prompt = new SeoPrompt();
                $prompt->forceFill([
                    'id' => 1,
                    'markdown_content' => $this->body,
                    'hook_key' => SeedingCommentGenerateCapabilityHandler::KEY,
                    'hook_version' => '0.1.0',
                ]);

                return [
                    'prompt' => $prompt,
                    'prompt_id' => 1,
                    'prompt_version_id' => 1,
                    'hook_key' => SeedingCommentGenerateCapabilityHandler::KEY,
                    'hook_version' => '0.1.0',
                    'body' => $this->body,
                ];
            }
        };
    }

    private function buildClient(
        SeedingCommentGenerateCapabilityHandler $handler,
        AiTextExecutionPort $textPort,
    ): SystemAiClient {
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: SeedingCommentGenerateCapabilityHandler::KEY,
            owner: 'seeding',
            handler: $handler,
            sideEffectFree: false,
        ));

        return new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry, $textPort),
            capabilities: $registry,
        );
    }
}
