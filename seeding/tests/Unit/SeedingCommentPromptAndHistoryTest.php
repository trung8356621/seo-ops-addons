<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Support\CapabilityModeResolver;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateHistoryService;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Services\SeedingCommentPromptService;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use Omnichannel\Addons\Seeding\Support\SeedingAiArchitecture;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptDefaults;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptRenderer;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Focused Gen Comment Manager prompt + debug history contracts (no SEO prompt system).
 */
final class SeedingCommentPromptAndHistoryTest extends TestCase
{
    public function test_only_mcp_context_variable_is_supported(): void
    {
        $renderer = new SeedingCommentPromptRenderer();
        self::assertSame(['{{mcp_context}}'], $renderer->supportedVariables());
        self::assertSame(
            [SeedingCommentPromptDefaults::MCP_CONTEXT_VAR],
            (new SeedingCommentPromptService($renderer))->supportedVariables(),
        );
    }

    public function test_mcp_context_is_replaced_and_other_braces_left_alone(): void
    {
        $renderer = new SeedingCommentPromptRenderer();
        $out = $renderer->render(
            "Hello {{mcp_context}} and keep {{other}}",
            "CTX-BALO",
        );
        self::assertSame('Hello CTX-BALO and keep {{other}}', $out);
    }

    public function test_default_prompt_is_not_empty_and_contains_variable(): void
    {
        $body = SeedingCommentPromptDefaults::promptBody();
        self::assertNotSame('', trim($body));
        self::assertStringContainsString('{{mcp_context}}', $body);
    }

    public function test_ring_slot_overwrites_oldest_after_20(): void
    {
        self::assertSame(1, SeedingCommentGenerateHistoryService::slotForSequence(1));
        self::assertSame(20, SeedingCommentGenerateHistoryService::slotForSequence(20));
        self::assertSame(1, SeedingCommentGenerateHistoryService::slotForSequence(21));
        self::assertSame(2, SeedingCommentGenerateHistoryService::slotForSequence(22));
        self::assertSame(20, SeedingCommentGenerateHistoryService::slotForSequence(40));
        self::assertSame(1, SeedingCommentGenerateHistoryService::slotForSequence(41));
    }

    public function test_concurrent_sequences_map_to_distinct_slots_until_wrap(): void
    {
        $seen = [];
        for ($seq = 1; $seq <= 20; $seq++) {
            $slot = SeedingCommentGenerateHistoryService::slotForSequence($seq);
            self::assertArrayNotHasKey($slot, $seen, "slot {$slot} reused before wrap");
            $seen[$slot] = $seq;
        }
        self::assertCount(20, $seen);
        self::assertNotSame(
            SeedingCommentGenerateHistoryService::slotForSequence(5),
            SeedingCommentGenerateHistoryService::slotForSequence(6),
        );
    }

    public function test_generation_uses_saved_prompt_and_snapshots_chain(): void
    {
        $captured = [];
        $prompt = new class extends SeedingCommentPromptService {
            public function getPromptBody(): string
            {
                return "Manager style GenZ-ish\n{{mcp_context}}\nEnd.";
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

        $textPort = new class($captured) implements AiTextExecutionPort {
            /** @param array<string, mixed> $captured */
            public function __construct(private array &$captured) {}

            public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
            {
                $this->captured['compiled'] = $compiledPrompt;
                unset($hookKey, $options);

                return [
                    'text' => json_encode(['comments' => ['c1', 'c2', 'c3']], JSON_UNESCAPED_UNICODE),
                    'provider' => 'deepseek',
                    'model' => 'deepseek-chat',
                ];
            }
        };

        $service = $this->makeService($prompt, $history, $textPort);

        $comments = $service->generateFromPayload([
            'content' => 'Balo laptop chống sốc',
            'social' => 'threads',
            'quantity' => 3,
            'topic_id' => 42,
        ]);

        self::assertSame(['c1', 'c2', 'c3'], $comments);
        self::assertCount(1, $sink->items);
        $snap = $sink->items[0];
        self::assertSame('Balo laptop chống sốc', $snap['mcp_context']);
        self::assertSame("Manager style GenZ-ish\nBalo laptop chống sốc\nEnd.", $snap['final_prompt']);
        self::assertSame(SeedingCommentGenerateHistoryService::STATUS_SUCCESS, $snap['status']);
        self::assertSame(42, $snap['topic_id']);
        self::assertSame('threads', $snap['social']);
        self::assertSame(3, $snap['quantity']);
        self::assertSame('deepseek', $snap['provider']);
        self::assertSame('deepseek-chat', $snap['model']);
        self::assertStringContainsString('"comments"', (string) $snap['ai_output']);

        self::assertStringContainsString($snap['final_prompt'], $captured['compiled']);
        self::assertStringNotContainsString('Adapt naturally to the supplied social platform', $captured['compiled']);
        self::assertStringNotContainsString('Avoid sounding like spam', $captured['compiled']);
    }

    public function test_failed_generation_still_writes_history_with_prompt_snapshot(): void
    {
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

        $prompt = new class extends SeedingCommentPromptService {
            public function getPromptBody(): string
            {
                return 'FAIL CASE {{mcp_context}}';
            }
        };

        $textPort = new class implements AiTextExecutionPort {
            public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
            {
                unset($compiledPrompt, $hookKey, $options);
                throw new RuntimeException('model blew up');
            }
        };

        $service = $this->makeService($prompt, $history, $textPort);

        try {
            $service->generateFromPayload([
                'content' => 'Chủ đề balo',
                'social' => 'facebook',
                'quantity' => 2,
            ]);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('model blew up', $e->getMessage());
        }

        self::assertCount(1, $sink->items);
        $snap = $sink->items[0];
        self::assertSame(SeedingCommentGenerateHistoryService::STATUS_FAILED, $snap['status']);
        self::assertSame('Chủ đề balo', $snap['mcp_context']);
        self::assertSame('FAIL CASE Chủ đề balo', $snap['final_prompt']);
        self::assertNotNull($snap['error_message']);
    }

    public function test_manager_panel_exposes_prompt_and_history_ui(): void
    {
        $panel = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/seeding/components/ManagerPanel.jsx'
        );
        self::assertStringContainsString("id: 'summary'", $panel);
        self::assertStringContainsString("subTab === 'summary'", $panel);
        self::assertStringContainsString('data-section="gen-comment-prompt"', $panel);
        self::assertStringContainsString('data-section="gen-comment-history"', $panel);
        self::assertStringContainsString('Prompt Gen Comment', $panel);
        self::assertStringContainsString('{{mcp_context}}', $panel);
        self::assertStringContainsString('Biến hỗ trợ duy nhất', $panel);
        self::assertStringContainsString('Lịch sử Gen Comment', $panel);
        self::assertStringContainsString('Chưa có lần Gen nào.', $panel);
        self::assertStringContainsString('promptError', $panel);
        self::assertStringContainsString('fetchCommentPrompt', $panel);
        self::assertStringContainsString('saveCommentPrompt', $panel);
        self::assertStringContainsString('MCP Context', $panel);
        self::assertStringContainsString('Final AI Request / Final Prompt', $panel);
        self::assertStringContainsString('AI Output', $panel);
        self::assertStringNotContainsString('prompt version', strtolower($panel));
        self::assertStringNotContainsString('temperature', strtolower($panel));
    }

    public function test_provider_registers_prompt_routes_without_seo_prompt_system(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/SeedingServiceProvider.php'
        );
        self::assertStringContainsString('SeedingCommentPromptController', $provider);
        self::assertStringContainsString('/manager/comment-prompt', $provider);
        self::assertStringNotContainsString('SeoPrompt', $provider);
        self::assertStringNotContainsString('prompt_versions', $provider);
        self::assertStringNotContainsString('seo_prompts', $provider);
        self::assertStringNotContainsString('SeoTask', $provider);
        self::assertStringNotContainsString('SeoAiHistory', $provider);
    }

    public function test_seeding_gen_comment_does_not_import_seo_prompt_pipeline(): void
    {
        $files = [
            dirname(__DIR__, 2).'/src/Services/SeedingCommentGenerateService.php',
            dirname(__DIR__, 2).'/src/Services/SeedingCommentPromptService.php',
            dirname(__DIR__, 2).'/src/Services/SeedingCommentGenerateHistoryService.php',
            dirname(__DIR__, 2).'/src/Http/Controllers/SeedingCommentPromptController.php',
            dirname(__DIR__, 2).'/src/Http/Controllers/SeedingCommentGenerateController.php',
            dirname(__DIR__, 2).'/src/Support/SeedingCommentPromptRenderer.php',
            dirname(__DIR__, 2).'/src/Support/SeedingCommentPromptDefaults.php',
            dirname(__DIR__, 2).'/src/Support/SeedingAiArchitecture.php',
            dirname(__DIR__, 2).'/src/Models/SeedingCommentPromptSetting.php',
            dirname(__DIR__, 2).'/src/Models/SeedingCommentGenerateLog.php',
            dirname(__DIR__, 2).'/src/System/SeedingCommentGenerateCapabilityHandler.php',
        ];

        foreach ($files as $path) {
            $src = (string) file_get_contents($path);
            self::assertStringNotContainsString('SeoPrompt', $src, $path);
            self::assertStringNotContainsString('SeoTask', $src, $path);
            self::assertStringNotContainsString('seo_prompts', $src, $path);
            self::assertStringNotContainsString('prompt_versions', $src, $path);
            self::assertStringNotContainsString('SeoAiHistory', $src, $path);
            self::assertStringNotContainsString('omi_seo_ai', $src, $path);
        }
    }

    public function test_architecture_marker_documents_permanent_boundary(): void
    {
        self::assertSame(
            'seeding_ai_independent_from_seo_prompt_task_history',
            SeedingAiArchitecture::BOUNDARY,
        );
        self::assertSame(20, SeedingAiArchitecture::RETENTION_MAX_LOGS);
        self::assertSame('{{mcp_context}}', SeedingAiArchitecture::MCP_CONTEXT_VAR);
        self::assertSame(
            SeedingAiArchitecture::MCP_CONTEXT_VAR,
            SeedingCommentPromptDefaults::MCP_CONTEXT_VAR,
        );
        self::assertSame(
            SeedingAiArchitecture::RETENTION_MAX_LOGS,
            SeedingCommentGenerateHistoryService::MAX_LOGS,
        );
    }

    private function makeService(
        SeedingCommentPromptService $prompt,
        SeedingCommentGenerateHistoryService $history,
        AiTextExecutionPort $textPort,
    ): SeedingCommentGenerateService {
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: SeedingCommentGenerateCapabilityHandler::KEY,
            owner: 'seeding',
            handler: new SeedingCommentGenerateCapabilityHandler(
                contextResolver: new SeedingSocialContextResolver(),
                promptService: $prompt,
            ),
            sideEffectFree: false,
        ));

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry, $textPort),
            capabilities: $registry,
        );

        return new SeedingCommentGenerateService(
            systemAi: $client,
            contextResolver: new SeedingSocialContextResolver(),
            promptService: $prompt,
            history: $history,
        );
    }
}
