<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit\System;

use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Support\CapabilityModeResolver;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateHistoryService;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Services\SeedingCommentPromptService;
use Omnichannel\Addons\Seeding\Services\SeedingSharedCommentPromptResolver;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;
use Omnichannel\Addons\Seeding\Support\SeedingAiArchitecture;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * STEP 2A.1 — shared Prompt SSOT for seeding.comment.generate.
 */
final class SeedingSharedPromptRegistrationContractTest extends TestCase
{
    public function test_hook_definition_json_exists_and_is_settings_visible(): void
    {
        $path = dirname(__DIR__, 4).'/ai-prompt/resources/prompt-hooks/v01/seeding.comment.generate@0.1.0.json';
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);
        self::assertSame('seeding.comment.generate', $json['key'] ?? null);
        self::assertSame('0.1.0', $json['version'] ?? null);
        self::assertTrue((bool) ($json['settings_visible'] ?? false));
        self::assertSame('legacy_prompt_content', $json['template']['source'] ?? null);
        self::assertArrayHasKey('mcp_context', $json['input_schema'] ?? []);
    }

    public function test_prompt_editor_catalog_discovers_seeding_hook(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $registry = new PromptHookRuntimeRegistry($loader);
        $catalog = new PromptHookEditorCatalog($registry);

        self::assertTrue($catalog->isSettingsVisible(DefaultSeedingCommentPromptInstaller::HOOK_KEY));
        $options = $catalog->selectOptions();
        self::assertArrayHasKey(DefaultSeedingCommentPromptInstaller::HOOK_KEY, $options);
    }

    public function test_installer_constants_match_capability(): void
    {
        self::assertSame(
            SeedingCommentGenerateCapabilityHandler::KEY,
            DefaultSeedingCommentPromptInstaller::HOOK_KEY,
        );
        self::assertSame('0.1.0', DefaultSeedingCommentPromptInstaller::HOOK_VERSION);
        self::assertStringContainsString('{{mcp_context}}', DefaultSeedingCommentPromptInstaller::DEFAULT_MARKDOWN);
    }

    public function test_handler_uses_shared_prompt_not_local_settings_service(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateCapabilityHandler::class))->getFileName()
        );
        self::assertStringContainsString('SeedingSharedCommentPromptResolver', $src);
        self::assertStringContainsString('PromptResult', $src);
        self::assertStringContainsString('prompt_version_id', $src);
        self::assertStringNotContainsString('SeedingCommentPromptService', $src);
        self::assertStringNotContainsString('SocialAiExecutionService', $src);
        self::assertStringNotContainsString('RemoteHttpAiTransport', $src);
        self::assertStringNotContainsString('social_ai_fallback', $src);
    }

    public function test_service_uses_shared_prompt_resolver_and_system_ai(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentGenerateService::class))->getFileName()
        );
        self::assertStringContainsString('SystemAiClient', $src);
        self::assertStringContainsString('SeedingSharedCommentPromptResolver', $src);
        self::assertStringNotContainsString('SeedingCommentPromptService', $src);
        self::assertStringNotContainsString('SocialAiExecutionService', $src);
    }

    public function test_local_settings_service_is_not_execution_authority_marker(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SeedingCommentPromptService::class))->getFileName()
        );
        self::assertStringContainsString('NOT execution authority', $src);
        self::assertStringContainsString('SeedingSharedCommentPromptResolver', $src);
    }

    public function test_no_new_prompt_ui_resource_created_in_seeding(): void
    {
        $filament = glob(dirname(__DIR__, 3).'/src/Filament/**/*.php') ?: [];
        foreach ($filament as $file) {
            $base = basename((string) $file);
            self::assertStringNotContainsString('PromptResource', $base);
            self::assertDoesNotMatchRegularExpression('/Prompt.*Page\\.php$/', $base);
        }

        $controller = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Http/Controllers/SeedingCommentPromptController.php'
        );
        self::assertStringContainsString('authority', $controller);
        self::assertStringContainsString('shared_prompt', $controller);
        self::assertStringContainsString('409', $controller);
        self::assertStringContainsString('PromptResource::getUrl', $controller);
    }

    public function test_manager_panel_disables_local_prompt_edit(): void
    {
        $panel = (string) file_get_contents(
            dirname(__DIR__, 3).'/resources/js/seeding/components/ManagerPanel.jsx'
        );
        self::assertStringContainsString('data-authority="shared_prompt"', $panel);
        self::assertStringContainsString('readOnly', $panel);
        self::assertStringContainsString('shared-prompt-editor', $panel);
        self::assertStringNotContainsString('saveCommentPrompt', $panel);
        self::assertStringNotContainsString('onChange={(e) => setPromptBody', $panel);
    }

    public function test_generation_uses_shared_prompt_body_and_mcp_context(): void
    {
        $captured = [];
        $prompt = new SeoPrompt();
        $prompt->forceFill([
            'id' => 77,
            'markdown_content' => "SHARED STYLE\n{{mcp_context}}\nEND",
            'hook_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
            'hook_version' => '0.1.0',
            'current_prompt_version_id' => 9,
        ]);

        $shared = new class($prompt) extends SeedingSharedCommentPromptResolver {
            public function __construct(private SeoPrompt $prompt) {}

            public function resolveActive(): array
            {
                return [
                    'prompt' => $this->prompt,
                    'prompt_id' => (int) $this->prompt->id,
                    'prompt_version_id' => 9,
                    'hook_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
                    'hook_version' => '0.1.0',
                    'body' => (string) $this->prompt->markdown_content,
                ];
            }
        };

        $textPort = new class($captured) implements AiTextExecutionPort {
            /** @param array<string, mixed> $captured */
            public function __construct(private array &$captured) {}

            public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
            {
                $this->captured['compiled'] = $compiledPrompt;
                $this->captured['hook_key'] = $hookKey;

                return [
                    'text' => json_encode(['comments' => ['shared-1']], JSON_UNESCAPED_UNICODE),
                    'provider' => 'test',
                    'model' => 'test-model',
                ];
            }
        };

        $handler = new SeedingCommentGenerateCapabilityHandler(
            contextResolver: new SeedingSocialContextResolver(),
            sharedPrompt: $shared,
        );

        // Avoid PromptResult DB write in isolated unit test by catching persist failure (returns null).
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: SeedingCommentGenerateCapabilityHandler::KEY,
            owner: 'seeding',
            handler: $handler,
            sideEffectFree: false,
        ));
        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry, $textPort),
            capabilities: $registry,
        );

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
            systemAi: $client,
            contextResolver: new SeedingSocialContextResolver(),
            sharedPrompt: $shared,
            history: $history,
        );

        $comments = $service->generateFromPayload([
            'content' => 'Balo chống sốc shared',
            'social' => 'threads',
            'quantity' => 1,
        ]);

        self::assertSame(['shared-1'], $comments);
        self::assertSame(SeedingCommentGenerateCapabilityHandler::KEY, $captured['hook_key'] ?? null);
        self::assertStringContainsString("SHARED STYLE\nBalo chống sốc shared\nEND", (string) ($captured['compiled'] ?? ''));
        self::assertSame("SHARED STYLE\nBalo chống sốc shared\nEND", $sink->items[0]['final_prompt'] ?? null);
    }

    public function test_changing_shared_prompt_body_affects_next_compile(): void
    {
        $bodies = [
            "V1 {{mcp_context}}",
            "V2 IDENTIFIER_SMOKE {{mcp_context}}",
        ];
        $call = 0;

        $shared = new class($bodies, $call) extends SeedingSharedCommentPromptResolver {
            /** @param list<string> $bodies */
            public function __construct(private array $bodies, private int &$call) {}

            public function resolveActive(): array
            {
                $body = $this->bodies[$this->call] ?? $this->bodies[array_key_last($this->bodies)];
                $this->call++;
                $prompt = new SeoPrompt();
                $prompt->forceFill([
                    'id' => 1,
                    'markdown_content' => $body,
                    'hook_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
                ]);

                return [
                    'prompt' => $prompt,
                    'prompt_id' => 1,
                    'prompt_version_id' => $this->call,
                    'hook_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
                    'hook_version' => '0.1.0',
                    'body' => $body,
                ];
            }
        };

        $compiled = [];
        $textPort = new class($compiled) implements AiTextExecutionPort {
            /** @param list<string> $compiled */
            public function __construct(private array &$compiled) {}

            public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
            {
                $this->compiled[] = $compiledPrompt;

                return [
                    'text' => json_encode(['comments' => ['x']], JSON_UNESCAPED_UNICODE),
                    'provider' => 't',
                    'model' => 'm',
                ];
            }
        };

        $handler = new SeedingCommentGenerateCapabilityHandler(
            contextResolver: new SeedingSocialContextResolver(),
            sharedPrompt: $shared,
        );

        $out1 = $handler->handle(
            ['content' => 'CTX', 'quantity' => 1, 'social' => 'threads'],
            ['system_ai_text_port' => $textPort],
        );
        $out2 = $handler->handle(
            ['content' => 'CTX', 'quantity' => 1, 'social' => 'threads'],
            ['system_ai_text_port' => $textPort],
        );

        self::assertSame(['x'], $out1['comments'] ?? null);
        self::assertSame(['x'], $out2['comments'] ?? null);
        self::assertStringContainsString('V1 CTX', $compiled[0] ?? '');
        self::assertStringContainsString('V2 IDENTIFIER_SMOKE CTX', $compiled[1] ?? '');
        self::assertStringNotContainsString('IDENTIFIER_SMOKE', $compiled[0] ?? '');
    }

    public function test_architecture_boundary_marker_updated(): void
    {
        self::assertSame('seeding_ai_uses_shared_prompt_system_ai', SeedingAiArchitecture::BOUNDARY);
        self::assertSame(
            'seeding_ai_independent_from_seo_prompt_task_history',
            SeedingAiArchitecture::LEGACY_BOUNDARY,
        );
    }
}
