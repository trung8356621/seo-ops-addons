<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\AiRoutingProfile;
use Omnichannel\Addons\AiPrompt\Models\AiRoutingTarget;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiCapabilitySource;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiRuntimeRoutingRefactorTest extends TestCase
{
    private ModelCapabilityRegistry $registry;

    private AiRoutingTargetService $targets;

    private AiModelRouterService $router;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('name');
            $table->text('api_key');
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_ai_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('api_connection_id');
            $table->string('category');
            $table->string('raw_model_name');
            $table->string('display_name');
            $table->integer('priority')->default(100);
            $table->string('status')->default('active');
            $table->boolean('is_hidden')->default(false);
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_model_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->unsignedBigInteger('api_connection_id')->nullable();
            $table->string('model_key');
            $table->string('capability');
            $table->string('source')->default('built_in');
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_routing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('key');
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('required_capabilities')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_routing_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->string('profile_key');
            $table->unsignedBigInteger('api_connection_id');
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->string('model_key');
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        $this->registry = new ModelCapabilityRegistry();
        $this->targets = new AiRoutingTargetService($this->registry);
        $bootstrap = new AiRoutingBootstrapService($this->registry, $this->targets);
        $this->router = new AiModelRouterService($this->registry, $this->targets, $bootstrap);
    }

    public function test_deepseek_is_an_ai_provider_option(): void
    {
        $catalog = new \Omnichannel\Addons\AiPrompt\Services\SeoApiConnectionProviderCatalog();
        $this->assertTrue($catalog->has(ApiConnectionProviders::DEEPSEEK));
        $this->assertSame('deepseek', ApiConnectionProviders::DEEPSEEK);
    }

    public function test_deepseek_text_model_capabilities(): void
    {
        $connection = $this->makeConnection(1, ApiConnectionProviders::DEEPSEEK);

        $this->assertTrue($this->registry->supports($connection, 'deepseek-chat', AiModelCapability::TextGenerate->value));
        $this->assertFalse($this->registry->supports($connection, 'deepseek-chat', AiModelCapability::ImageGenerate->value));
        $this->assertFalse($this->registry->supports($connection, 'deepseek-chat', AiModelCapability::VideoGenerate->value));
        $this->assertTrue($this->registry->supports($connection, 'deepseek-reasoner', AiModelCapability::TextReasoning->value));
    }

    public function test_unknown_model_cannot_claim_multimedia(): void
    {
        $connection = $this->makeConnection(1, ApiConnectionProviders::GEMINI);
        $this->assertSame([], $this->registry->capabilitiesFor($connection, 'totally-unknown-xyz-9'));
        $this->assertFalse($this->registry->supports($connection, 'totally-unknown-xyz-9', AiModelCapability::ImageGenerate->value));
        $this->assertFalse($this->registry->isEligibleForAutomaticRouting($connection, 'totally-unknown-xyz-9'));
    }

    public function test_manual_capability_override_works(): void
    {
        $connection = $this->makeConnection(1, ApiConnectionProviders::DEEPSEEK);
        AiModelCapabilityRow::query()->create([
            'api_connection_id' => $connection->id,
            'model_key' => 'deepseek-chat',
            'capability' => AiModelCapability::TextReasoning->value,
            'source' => AiCapabilitySource::Manual->value,
            'enabled' => true,
        ]);

        $registry = new ModelCapabilityRegistry();
        $this->assertTrue($registry->supports($connection, 'deepseek-chat', AiModelCapability::TextReasoning->value));
    }

    public function test_longform_prefers_deepseek_then_falls_back_on_infrastructure_failure(): void
    {
        $deepseek = $this->makeConnection(9, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->makeConnection(9, ApiConnectionProviders::GEMINI, 'Gemini');
        $this->addModel($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT);
        $this->addModel($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH);

        $this->targets->replaceTargets(9, AiExecutionProfile::TextLongform->value, [
            ['api_connection_id' => (int) $deepseek->id, 'model_key' => 'deepseek-chat'],
            ['api_connection_id' => (int) $gemini->id, 'model_key' => 'gemini-3-flash-preview'],
        ]);

        $context = new AiRoutingContext(userId: 9);
        $first = $this->router->resolve(AiExecutionProfile::TextLongform->value, $context);
        $this->assertSame('deepseek', $first->provider);
        $this->assertSame('deepseek-chat', $first->model);

        $calls = [];
        [$output, , $used, $fallbackCount] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            $context,
            function ($candidate) use (&$calls): array {
                $calls[] = $candidate->provider;
                if ($candidate->provider === 'deepseek') {
                    throw new \RuntimeException('429 rate limit');
                }

                return ['ok-gemini', ['tokens' => 1]];
            },
        );

        $this->assertSame('ok-gemini', $output);
        $this->assertSame('gemini', $used->provider);
        $this->assertSame(1, $fallbackCount);
        $this->assertSame(['deepseek', 'gemini'], $calls);
    }

    public function test_successful_mediocre_text_does_not_call_fallback(): void
    {
        $deepseek = $this->makeConnection(9, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->makeConnection(9, ApiConnectionProviders::GEMINI, 'Gemini');
        $this->addModel($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT);
        $this->addModel($gemini, 'gemini-2.5-flash', AiModelCategory::GEMINI_FLASH);
        $this->targets->replaceTargets(9, AiExecutionProfile::TextLongform->value, [
            ['api_connection_id' => (int) $deepseek->id, 'model_key' => 'deepseek-chat'],
            ['api_connection_id' => (int) $gemini->id, 'model_key' => 'gemini-2.5-flash'],
        ]);

        $calls = 0;
        [$output] = $this->router->executeWithProfile(
            AiExecutionProfile::TextLongform->value,
            new AiRoutingContext(userId: 9),
            function ($candidate) use (&$calls): array {
                $calls++;
                $this->assertSame('deepseek', $candidate->provider);

                return ['meh but valid', null];
            },
        );

        $this->assertSame('meh but valid', $output);
        $this->assertSame(1, $calls);
    }

    public function test_image_profile_rejects_deepseek_configuration(): void
    {
        $deepseek = $this->makeConnection(3, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $this->addModel($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT);

        $this->expectException(AiRoutingException::class);
        $this->expectExceptionMessage('image.generate');
        $this->targets->replaceTargets(3, AiExecutionProfile::ImageGeneral->value, [
            ['api_connection_id' => (int) $deepseek->id, 'model_key' => 'deepseek-chat'],
        ]);
    }

    public function test_image_profile_accepts_gemini_image_model(): void
    {
        $gemini = $this->makeConnection(3, ApiConnectionProviders::GEMINI, 'Gemini');
        $this->addModel($gemini, 'gemini-3.1-flash-image-preview', AiModelCategory::IMAGEN_PRO);
        $this->targets->replaceTargets(3, AiExecutionProfile::ImageGeneral->value, [
            ['api_connection_id' => (int) $gemini->id, 'model_key' => 'gemini-3.1-flash-image-preview'],
        ]);

        $resolved = $this->router->resolve(AiExecutionProfile::ImageGeneral->value, new AiRoutingContext(userId: 3));
        $this->assertSame('gemini', $resolved->provider);
        $this->assertTrue(in_array(AiModelCapability::ImageGenerate->value, $resolved->capabilities, true));
    }

    public function test_legacy_prompt_runs_via_fallback_then_uses_routing(): void
    {
        $gemini = $this->makeConnection(4, ApiConnectionProviders::GEMINI, 'Gemini');
        $this->addModel($gemini, 'gemini-2.5-flash', AiModelCategory::GEMINI_FLASH);

        $prompt = new SeoPrompt();
        $prompt->hook_key = 'article.content.generate';
        $prompt->tools = 'default';
        $prompt->routing_mode = 'auto';
        $prompt->setRelation('aiConnection', $gemini);

        $profile = (new PromptExecutionProfileResolver())->resolve($prompt);
        $this->assertSame(AiExecutionProfile::TextLongform, $profile);

        $legacy = $this->router->resolve(
            $profile->value,
            new AiRoutingContext(userId: 4, legacyConnection: $gemini, allowLegacyFallback: true),
        );
        $this->assertSame('gemini', $legacy->provider);
        $this->assertSame('gemini-2.5-flash', $legacy->model);

        $deepseek = $this->makeConnection(4, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $this->addModel($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT);
        $this->targets->replaceTargets(4, AiExecutionProfile::TextLongform->value, [
            ['api_connection_id' => (int) $deepseek->id, 'model_key' => 'deepseek-chat'],
            ['api_connection_id' => (int) $gemini->id, 'model_key' => 'gemini-2.5-flash'],
        ]);

        $routed = $this->router->resolve(
            $profile->value,
            new AiRoutingContext(userId: 4, legacyConnection: $gemini, allowLegacyFallback: true),
        );
        $this->assertFalse($routed->legacyFallback);
        $this->assertSame('deepseek', $routed->provider);
        $this->assertSame('deepseek-chat', $routed->model);
    }

    public function test_cross_tenant_connection_cannot_be_added(): void
    {
        $other = $this->makeConnection(99, ApiConnectionProviders::GEMINI, 'Other');
        $this->addModel($other, 'gemini-2.5-flash', AiModelCategory::GEMINI_FLASH);

        $this->expectException(AiRoutingException::class);
        $this->targets->replaceTargets(4, AiExecutionProfile::TextFast->value, [
            ['api_connection_id' => (int) $other->id, 'model_key' => 'gemini-2.5-flash'],
        ]);
    }

    public function test_eligible_options_exclude_deepseek_from_image_profile(): void
    {
        $deepseek = $this->makeConnection(5, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->makeConnection(5, ApiConnectionProviders::GEMINI, 'Gemini');
        $this->addModel($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT);
        $this->addModel($gemini, 'gemini-3.1-flash-image-preview', AiModelCategory::IMAGEN_PRO);

        $options = $this->targets->eligibleOptionMap(5, AiExecutionProfile::ImageGeneral);
        $joined = implode(' ', array_keys($options));
        $this->assertStringNotContainsString('deepseek-chat', $joined);
        $this->assertStringContainsString('gemini-3.1-flash-image-preview', $joined);
        $joinedLabels = implode(' ', array_values($options));
        $this->assertStringNotContainsString('JSON', $joinedLabels);
        $this->assertStringNotContainsString('Tools', $joinedLabels);
        $this->assertStringNotContainsString('legacy_version', $joinedLabels);
    }

    public function test_hidden_model_is_absent_from_routing_options(): void
    {
        $gemini = $this->makeConnection(7, ApiConnectionProviders::GEMINI, 'Gemini');
        $visible = $this->addModel($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH);
        $hidden = $this->addModel($gemini, 'gemini-3.1-pro-preview', AiModelCategory::GEMINI_PRO);
        $hidden->is_hidden = true;
        $hidden->save();

        $options = $this->targets->eligibleOptionMap(7, AiExecutionProfile::TextFast);
        $joined = implode(' ', array_keys($options));
        $this->assertStringContainsString('gemini-3-flash-preview', $joined);
        $this->assertStringNotContainsString('gemini-3.1-pro-preview', $joined);
        unset($visible);
    }

    public function test_table_order_wins_over_economy_and_profile_target_order(): void
    {
        $gemini = $this->makeConnection(8, ApiConnectionProviders::GEMINI, 'Gemini');
        $flash = $this->addModel($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH);
        $pro = $this->addModel($gemini, 'gemini-3.1-pro-preview', AiModelCategory::GEMINI_PRO);
        $flash->priority = 20;
        $flash->save();
        $pro->priority = 10;
        $pro->save();
        $this->targets->replaceTargets(8, AiExecutionProfile::TextFast->value, [
            ['api_connection_id' => (int) $gemini->id, 'model_key' => 'gemini-3-flash-preview'],
            ['api_connection_id' => (int) $gemini->id, 'model_key' => 'gemini-3.1-pro-preview'],
        ]);
        $this->targets->writeProfileSettings(8, AiExecutionProfile::TextFast, [
            'usage_mode' => 'economy',
            'preserve_explicit_order' => false,
            'allowed_family_keys' => [],
        ]);

        $resolved = $this->router->resolveAll(
            AiExecutionProfile::TextFast->value,
            new AiRoutingContext(userId: 8),
        );
        $this->assertSame('gemini-3.1-pro-preview', $resolved[0]->model);
        $this->assertSame('gemini-3-flash-preview', $resolved[1]->model);
    }

    public function test_gemini_flash_targets_are_connection_scoped(): void
    {
        $gemini = $this->makeConnection(11, ApiConnectionProviders::GEMINI, 'Gemini');
        $openrouter = $this->makeConnection(11, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $this->addModel($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH);
        $orFlash = $this->addModel($openrouter, 'google/gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH);
        $orFlash->is_hidden = false;
        $orFlash->save();

        $options = $this->targets->eligibleExecutionOptionMap(11, AiExecutionProfile::TextFast);
        $this->assertArrayHasKey($gemini->id.'|gemini.flash', $options);
        $this->assertArrayHasKey($openrouter->id.'|gemini.flash', $options);
        $this->assertNotSame($options[$gemini->id.'|gemini.flash'], $options[$openrouter->id.'|gemini.flash']);

        $this->targets->saveSimplifiedSelection(
            11,
            AiExecutionProfile::TextFast,
            [(string) $gemini->id.'|gemini.flash'],
            \Omnichannel\Addons\AiPrompt\Support\AiUsageMode::Economy,
            true,
            false,
        );
        $targets = $this->targets->targetsFor(11, AiExecutionProfile::TextFast->value);
        $this->assertCount(1, $targets);
        $this->assertSame((int) $gemini->id, (int) $targets[0]->api_connection_id);

        $this->targets->saveSimplifiedSelection(
            11,
            AiExecutionProfile::TextFast,
            [(string) $gemini->id.'|gemini.flash', (string) $openrouter->id.'|gemini.flash'],
            \Omnichannel\Addons\AiPrompt\Support\AiUsageMode::Economy,
            true,
            false,
        );
        $this->assertCount(2, $this->targets->targetsFor(11, AiExecutionProfile::TextFast->value));
    }

    public function test_openrouter_prefixed_models_get_upstream_text_capabilities_without_manual_rows(): void
    {
        $openrouter = $this->makeConnection(12, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $flashCaps = $this->registry->capabilitiesFor($openrouter, 'google/gemini-3-flash-preview');
        $chatCaps = $this->registry->capabilitiesFor($openrouter, 'deepseek/deepseek-chat');

        $this->assertContains(AiModelCapability::TextGenerate->value, $flashCaps);
        $this->assertContains(AiModelCapability::TextGenerate->value, $chatCaps);
        $this->assertTrue($this->registry->satisfiesAll(
            $openrouter,
            'google/gemini-3-flash-preview',
            AiExecutionProfile::TextFast->requiredCapabilityKeys(),
        ));
    }

    private function makeConnection(int $userId, string $provider, string $name = 'Conn'): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'test-key',
            'is_global' => false,
            'status' => 'active',
        ]);
    }

    private function addModel(ApiConnection $connection, string $raw, string $category): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => $category,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
        ]);
    }
}
