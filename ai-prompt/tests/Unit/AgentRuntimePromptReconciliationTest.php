<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\SettingsPromptBindingResolver;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use ReflectionClass;
use Tests\TestCase;

final class AgentRuntimePromptReconciliationTest extends TestCase
{
    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = (new SeoPrompt)->getConnectionName()
            ?: (string) config('database.core_connection', config('database.default'));
        $this->createPromptSchema();
        $this->createOptionsSchema();
    }

    protected function tearDown(): void
    {
        Schema::connection($this->connection)->dropIfExists('prompt_versions');
        Schema::connection($this->connection)->dropIfExists('prompts');
        Schema::dropIfExists('wp_options');
        WpOption::clearRequestCache();

        parent::tearDown();
    }

    public function test_fresh_install_provisions_exactly_two_owned_prompts_and_is_idempotent(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);

        $first = $installer->install();
        $versionsAfterFirstInstall = PromptVersion::query()->count();
        $second = $installer->install();

        self::assertSame(['routing', 'response'], array_keys($first));
        self::assertTrue($first['routing']['created']);
        self::assertTrue($first['response']['created']);
        self::assertFalse($second['routing']['created']);
        self::assertFalse($second['response']['created']);
        $this->assertFinalInventory();
        self::assertSame(2, SeoPrompt::query()->count());
        self::assertSame($versionsAfterFirstInstall, PromptVersion::query()->count());
        self::assertSame(2, $versionsAfterFirstInstall);
    }

    public function test_forward_migration_reconciles_jev_only_upgrade_without_duplicates(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);
        $installer->installType('routing');
        $routing = SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->firstOrFail();
        $routingId = (int) $routing->id;
        $bindingId = app(SeoCreateArticleSettingsService::class)->getBoundPromptId('agent.routing.decide');
        $routing->hook_version = '0.1.0';
        $routing->markdown_content = 'Previous routing contract body';
        $routing->save();
        $versionsBeforeMigration = PromptVersion::query()->count();

        $migration = require dirname((new ReflectionClass(DefaultAgentRuntimePromptInstaller::class))->getFileName(), 4)
            .'/database/migrations/2026_10_05_110000_upgrade_agent_routing_contract_to_v020.php';
        $migration->up();

        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->count());
        $routing->refresh();
        self::assertSame($routingId, (int) $routing->id);
        self::assertSame($bindingId, app(SeoCreateArticleSettingsService::class)->getBoundPromptId('agent.routing.decide'));
        self::assertSame('0.2.0', $routing->hook_version);
        self::assertSame(DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('routing'), $routing->markdown_content);
        self::assertStringContainsString('"response_language"', $routing->markdown_content);
        self::assertSame($versionsBeforeMigration + 1, PromptVersion::query()->count());
        $previousVersion = PromptVersion::query()->where('prompt_id', $routingId)->where('hook_version', '0.1.0')->first();
        self::assertNotNull($previousVersion);
        self::assertSame('Previous routing contract body', $previousVersion->markdown_content);

        $versionsAfterReconciliation = PromptVersion::query()->count();
        $migration->up();

        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->count());
        self::assertSame($versionsAfterReconciliation, PromptVersion::query()->count());
    }

    public function test_forward_migration_reconciles_response_only_upgrade_without_duplicates(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);
        $installer->installType('routing');
        $installer->installType('response');
        $routing = SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->firstOrFail();
        $routing->markdown_content = 'Current customized routing instructions';
        $routing->save();
        $routingVersionsBeforeMigration = PromptVersion::query()->where('prompt_id', $routing->id)->count();
        $response = SeoPrompt::query()->where('hook_key', 'agent.response.compose')->firstOrFail();
        $responseId = (int) $response->id;
        $bindingId = app(SeoCreateArticleSettingsService::class)->getBoundPromptId('agent.response.compose');
        $response->hook_version = '0.1.0';
        $response->markdown_content = 'Previous response composer contract body';
        $response->save();
        $versionsBeforeMigration = PromptVersion::query()->count();

        $migration = require dirname((new ReflectionClass(DefaultAgentRuntimePromptInstaller::class))->getFileName(), 4)
            .'/database/migrations/2026_10_05_120000_upgrade_agent_response_contract_to_v020.php';
        $migration->up();

        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.response.compose')->count());
        $response->refresh();
        self::assertSame($responseId, (int) $response->id);
        self::assertSame($bindingId, app(SeoCreateArticleSettingsService::class)->getBoundPromptId('agent.response.compose'));
        self::assertSame('0.2.0', $response->hook_version);
        self::assertSame(DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('response'), $response->markdown_content);
        self::assertSame($versionsBeforeMigration + 1, PromptVersion::query()->count());
        $previousVersion = PromptVersion::query()->where('prompt_id', $responseId)->where('hook_version', '0.1.0')->first();
        self::assertNotNull($previousVersion);
        self::assertSame('Previous response composer contract body', $previousVersion->markdown_content);
        $routing->refresh();
        self::assertSame('0.2.0', $routing->hook_version);
        self::assertSame('Current customized routing instructions', $routing->markdown_content);
        self::assertSame($routingVersionsBeforeMigration, PromptVersion::query()->where('prompt_id', $routing->id)->count());

        $versionsAfterReconciliation = PromptVersion::query()->count();
        $migration->up();

        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.response.compose')->count());
        self::assertSame($versionsAfterReconciliation, PromptVersion::query()->count());
    }

    public function test_forward_migration_fails_visibly_instead_of_swallowing_throwables(): void
    {
        $path = dirname((new ReflectionClass(DefaultAgentRuntimePromptInstaller::class))->getFileName(), 4)
            .'/database/migrations/2026_10_05_100000_reconcile_default_agent_runtime_prompt_bindings.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('DefaultAgentRuntimePromptInstaller::class', $source);
        self::assertStringContainsString('->install()', $source);
        self::assertStringNotContainsString('catch (Throwable', $source);
        self::assertStringNotContainsString('catch (\\Throwable', $source);
    }

    public function test_missing_routing_binding_self_heals_and_returns_owned_prompt(): void
    {
        $prompt = $this->resolver()->resolve('agent.routing.decide');

        self::assertSame('agent.routing.decide', $prompt->hook_key);
        self::assertSame('Agent Routing / JEV', $prompt->name);
        self::assertSame((int) $prompt->id, app(SeoCreateArticleSettingsService::class)->getBoundPromptId('agent.routing.decide'));
        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->count());
    }

    public function test_missing_response_binding_self_heals_without_touching_routing(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);
        $installer->installType('routing');
        $routingId = app(SeoCreateArticleSettingsService::class)->getBoundPromptId('agent.routing.decide');

        $prompt = $this->resolver()->resolve('agent.response.compose');

        self::assertSame('agent.response.compose', $prompt->hook_key);
        self::assertSame('Agent Response Composer', $prompt->name);
        self::assertSame($routingId, app(SeoCreateArticleSettingsService::class)->getBoundPromptId('agent.routing.decide'));
        self::assertSame((int) $prompt->id, app(SeoCreateArticleSettingsService::class)->getBoundPromptId('agent.response.compose'));
    }

    public function test_existing_binding_resolves_without_duplicate_or_destructive_restore(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);
        $installer->installType('routing');
        $prompt = SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->firstOrFail();
        $prompt->markdown_content = <<<'MARKDOWN'
Admin customized routing prompt:
{"is_in_scope":true,"primary_capability":null,"capabilities":[],"response_template":"text","response_language":"en"}
MARKDOWN;
        $prompt->save();
        $promptCount = SeoPrompt::query()->count();
        $versionCount = PromptVersion::query()->count();

        $resolved = $this->resolver()->resolve('agent.routing.decide');

        self::assertSame((int) $prompt->id, (int) $resolved->id);
        self::assertSame($prompt->markdown_content, $resolved->markdown_content);
        self::assertSame($promptCount, SeoPrompt::query()->count());
        self::assertSame($versionCount, PromptVersion::query()->count());
    }

    public function test_unknown_hook_keeps_not_configured_error_without_auto_install(): void
    {
        try {
            $this->resolver()->resolve('something.unknown');
            self::fail('Expected missing unknown hook to fail.');
        } catch (PromptHookException $exception) {
            self::assertSame(PromptHookErrorCode::HookPromptNotConfigured, $exception->errorCode);
        }

        self::assertSame(0, SeoPrompt::query()->count());
        self::assertSame([], app(SeoCreateArticleSettingsService::class)->getPromptHookBindings());
    }

    public function test_bound_non_agent_prompt_is_not_self_healed(): void
    {
        $prompt = SeoPrompt::query()->create([
            'name' => 'Custom Hook Prompt',
            'title' => 'Custom Hook Prompt',
            'markdown_content' => 'Custom content without Agent routing fields.',
            'hook_key' => 'something.custom',
            'hook_version' => '1.0.0',
            'variables' => [],
            'tools' => 'default',
            'is_active' => true,
            'user_id' => 1,
            'settings' => ['is_system_default' => true, 'ownership' => 'settings_binding'],
        ]);
        app(SeoCreateArticleSettingsService::class)->savePromptHookBindings([
            'something.custom' => (int) $prompt->id,
        ]);
        $versionCount = PromptVersion::query()->where('prompt_id', $prompt->id)->count();

        $resolved = $this->resolver()->resolve('something.custom');

        self::assertSame((int) $prompt->id, (int) $resolved->id);
        self::assertSame('Custom content without Agent routing fields.', $resolved->markdown_content);
        self::assertSame($versionCount, PromptVersion::query()->where('prompt_id', $prompt->id)->count());
    }

    public function test_legacy_managed_routing_contract_is_repaired_once_from_canonical_source(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);
        $installer->installType('routing');
        $prompt = SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->firstOrFail();
        $prompt->markdown_content = <<<'MARKDOWN'
Return JSON:
{"intent":"...","primary_module":"site","modules":["site"],"parameters":{}}
MARKDOWN;
        $prompt->hook_version = '0.1.0';
        $prompt->save();

        $resolved = $this->resolver()->resolve('agent.routing.decide');
        $versionsAfterRepair = PromptVersion::query()->where('prompt_id', $prompt->id)->count();

        self::assertSame(DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('routing'), $resolved->markdown_content);
        self::assertStringContainsString('"is_in_scope"', $resolved->markdown_content);
        self::assertStringContainsString('"primary_capability"', $resolved->markdown_content);
        self::assertStringContainsString('"capabilities"', $resolved->markdown_content);
        self::assertStringContainsString('"response_template"', $resolved->markdown_content);
        self::assertStringContainsString('"response_language"', $resolved->markdown_content);

        $resolvedAgain = $this->resolver()->resolve('agent.routing.decide');
        self::assertSame((int) $resolved->id, (int) $resolvedAgain->id);
        self::assertSame($versionsAfterRepair, PromptVersion::query()->where('prompt_id', $prompt->id)->count());
        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->count());
    }

    public function test_previous_modern_routing_contract_missing_language_is_repaired_once(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);
        $installer->installType('routing');
        $prompt = SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->firstOrFail();
        $previousModernContent = <<<'MARKDOWN'
Admin guidance with previous modern schema:
{"is_in_scope":true,"primary_capability":null,"capabilities":[],"response_template":"text"}
MARKDOWN;
        $prompt->markdown_content = $previousModernContent;
        $prompt->hook_version = '0.1.0';
        $prompt->save();

        $resolved = $this->resolver()->resolve('agent.routing.decide');
        $versionsAfterRepair = PromptVersion::query()->where('prompt_id', $prompt->id)->count();

        self::assertSame(DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('routing'), $resolved->markdown_content);
        self::assertStringContainsString('"response_language"', $resolved->markdown_content);
        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->count());

        $resolvedAgain = $this->resolver()->resolve('agent.routing.decide');
        self::assertSame((int) $resolved->id, (int) $resolvedAgain->id);
        self::assertSame($versionsAfterRepair, PromptVersion::query()->where('prompt_id', $prompt->id)->count());
    }

    public function test_current_admin_edited_routing_contract_is_preserved(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);
        $installer->installType('routing');
        $prompt = SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->firstOrFail();
        $currentAdminContent = <<<'MARKDOWN'
Admin customized instructions with current contract:
{"is_in_scope":true,"primary_capability":null,"capabilities":[],"response_template":"text","response_language":"vi"}
MARKDOWN;
        $prompt->markdown_content = $currentAdminContent;
        $prompt->save();
        $versionCount = PromptVersion::query()->where('prompt_id', $prompt->id)->count();

        $resolved = $this->resolver()->resolve('agent.routing.decide');

        self::assertSame('0.2.0', $resolved->hook_version);
        self::assertSame($currentAdminContent, $resolved->markdown_content);
        self::assertSame($versionCount, PromptVersion::query()->where('prompt_id', $prompt->id)->count());
        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.routing.decide')->count());
    }

    public function test_old_managed_response_contract_self_heals_once_and_current_custom_content_is_preserved(): void
    {
        $installer = app(DefaultAgentRuntimePromptInstaller::class);
        $installer->installType('response');
        $prompt = SeoPrompt::query()->where('hook_key', 'agent.response.compose')->firstOrFail();
        $prompt->hook_version = '0.1.0';
        $prompt->markdown_content = 'Old response composer instructions';
        $prompt->save();

        $resolved = $this->resolver()->resolve('agent.response.compose');
        $versionsAfterRepair = PromptVersion::query()->where('prompt_id', $prompt->id)->count();

        self::assertSame('0.2.0', $resolved->hook_version);
        self::assertSame(DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('response'), $resolved->markdown_content);
        self::assertSame(1, SeoPrompt::query()->where('hook_key', 'agent.response.compose')->count());

        $resolvedAgain = $this->resolver()->resolve('agent.response.compose');
        self::assertSame((int) $resolved->id, (int) $resolvedAgain->id);
        self::assertSame($versionsAfterRepair, PromptVersion::query()->where('prompt_id', $prompt->id)->count());

        $resolvedAgain->markdown_content = 'Current customized response instructions';
        $resolvedAgain->save();
        $versionsBeforeCurrentResolve = PromptVersion::query()->where('prompt_id', $prompt->id)->count();

        $current = $this->resolver()->resolve('agent.response.compose');
        self::assertSame('Current customized response instructions', $current->markdown_content);
        self::assertSame($versionsBeforeCurrentResolve, PromptVersion::query()->where('prompt_id', $prompt->id)->count());
    }

    private function resolver(): SettingsPromptBindingResolver
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );

        return new SettingsPromptBindingResolver(
            app(SeoCreateArticleSettingsService::class),
            new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader)),
            app(DefaultAgentRuntimePromptInstaller::class),
        );
    }

    private function assertFinalInventory(): void
    {
        $expected = [
            'agent.routing.decide' => 'Agent Routing / JEV',
            'agent.response.compose' => 'Agent Response Composer',
        ];

        foreach ($expected as $hook => $name) {
            $prompts = SeoPrompt::query()->where('hook_key', $hook)->where('name', $name)->get();
            self::assertCount(1, $prompts);
            self::assertTrue((bool) $prompts->first()?->is_active);
            self::assertTrue((bool) data_get($prompts->first()?->settings, 'is_system_default'));
            self::assertSame('settings_binding', data_get($prompts->first()?->settings, 'ownership'));
            self::assertSame('0.2.0', $prompts->first()?->hook_version);
            self::assertSame(1, PromptVersion::query()->where('prompt_id', $prompts->first()?->id)->count());
        }

        $bindings = app(SeoCreateArticleSettingsService::class)->getPromptHookBindings();
        self::assertSame(array_keys($expected), array_values(array_intersect(array_keys($bindings), array_keys($expected))));
    }

    private function createPromptSchema(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('prompt_versions');
        $schema->dropIfExists('prompts');
        $schema->create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_prompt_version_id')->nullable();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('title')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->longText('markdown_content')->nullable();
            $table->string('hook_key')->nullable();
            $table->string('hook_version')->nullable();
            $table->json('hook_settings')->nullable();
            $table->json('variables')->nullable();
            $table->json('settings')->nullable();
            $table->string('tools')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id')->index();
            $table->string('version_label', 32);
            $table->unsignedInteger('sequence')->default(1);
            $table->longText('markdown_content')->nullable();
            $table->string('hook_key')->nullable();
            $table->string('hook_version')->nullable();
            $table->json('hook_settings')->nullable();
            $table->json('settings')->nullable();
            $table->string('tools', 64)->nullable();
            $table->json('variables')->nullable();
            $table->char('content_fingerprint', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createOptionsSchema(): void
    {
        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        WpOption::clearRequestCache();
    }
}
