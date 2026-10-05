<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller;
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
        $routing->description = 'Admin-preserved description';
        $routing->save();
        $versionsBeforeMigration = PromptVersion::query()->count();

        $migration = require dirname((new ReflectionClass(DefaultAgentRuntimePromptInstaller::class))->getFileName(), 4)
            .'/database/migrations/2026_10_05_100000_reconcile_default_agent_runtime_prompt_bindings.php';
        $migration->up();

        $this->assertFinalInventory();
        self::assertSame('Admin-preserved description', $routing->fresh()->description);
        self::assertSame($versionsBeforeMigration + 1, PromptVersion::query()->count());

        $versionsAfterReconciliation = PromptVersion::query()->count();
        $migration->up();

        $this->assertFinalInventory();
        self::assertSame(2, SeoPrompt::query()->count());
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
