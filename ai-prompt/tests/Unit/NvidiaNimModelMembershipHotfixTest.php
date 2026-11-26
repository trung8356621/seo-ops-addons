<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPrimaryTypeClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\BuiltInModelCapabilityCatalog;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class NvidiaNimModelMembershipHotfixTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRoutingTargetService $targets;

    protected function setUp(): void
    {
        parent::setUp();
        ModelCapabilityRegistry::flushRequestMemo();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections', 'wp_options'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('seo_ai_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('api_connection_id');
            $table->string('category')->nullable();
            $table->string('raw_model_name');
            $table->string('display_name')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('status')->default('active');
            $table->boolean('is_hidden')->default(true);
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        Schema::create('ai_routing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('key');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        $this->priorities = new AiModelPriorityService();
        $this->targets = new AiRoutingTargetService(new ModelCapabilityRegistry(), priorities: $this->priorities);
    }

    public function test_classifier_does_not_stamp_enabled_false_and_repairs_legacy_auto_disable(): void
    {
        $conn = $this->nvidia(40);
        $model = SeoAiModel::query()->create([
            'api_connection_id' => $conn->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => 'google/gemma-3-12b-it',
            'display_name' => 'google/gemma-3-12b-it',
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => true,
            'capabilities' => [
                'omi_areas' => [
                    'fast_text' => [
                        'enabled' => false,
                        'priority' => 100,
                        'source' => 'auto',
                    ],
                ],
            ],
        ]);

        (new AiModelPrimaryTypeClassifier($this->priorities))->classifyConnection($conn);
        $model->refresh();
        $areas = is_array($model->capabilities['omi_areas'] ?? null) ? $model->capabilities['omi_areas'] : [];
        $this->assertArrayNotHasKey('fast_text', $areas);
        $this->assertNotSame('', (string) ($model->capabilities['omi_primary_type'] ?? ''));
        $this->assertTrue((bool) $model->is_hidden);
    }

    public function test_nvidia_chat_models_resolve_text_capabilities_without_hardcoded_names(): void
    {
        $caps = BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('mistralai/mistral-large-4-1t-a52b');
        $this->assertContains(AiModelCapability::TextGenerate->value, $caps ?? []);
        $this->assertSame([], BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('nvidia/embed-qa-4'));
        $this->assertSame([], BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('nvidia/nemotron-3.5-content-safety'));
        $this->assertSame([], BuiltInModelCapabilityCatalog::openAiCompatibleTextCapabilitiesFor('nvidia/riva-translate-4b-instruct-v2'));

        $conn = $this->nvidia(41);
        $registry = new ModelCapabilityRegistry();
        $this->assertContains(
            AiModelCapability::TextGenerate->value,
            $registry->capabilitiesFor($conn, 'google/gemma-3-4b-it'),
        );
        $this->assertSame([], $registry->capabilitiesFor($conn, 'nvidia/embed-qa-4'));
    }

    public function test_manual_fast_text_membership_makes_nvidia_model_eligible(): void
    {
        $conn = $this->nvidia(42);
        $model = SeoAiModel::query()->create([
            'api_connection_id' => $conn->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => 'google/gemma-3-12b-it',
            'display_name' => 'google/gemma-3-12b-it',
            'priority' => 10,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => true,
            'capabilities' => [],
        ]);

        // Simulate Add from Unknown picker: unhide + append.
        $model->is_hidden = false;
        $model->save();
        $this->priorities->appendToArea(42, AiModelArea::TextFast, [(int) $model->id]);

        $enabled = $this->priorities->areaEnabledModels(42, AiModelArea::TextFast);
        $this->assertSame(['google/gemma-3-12b-it'], array_map(
            static fn (SeoAiModel $row): string => (string) $row->raw_model_name,
            $enabled,
        ));

        $candidates = $this->targets->liveCompatibleCandidates(42, AiExecutionProfile::TextFast);
        $this->assertSame(['google/gemma-3-12b-it'], array_map(
            static fn ($c): string => $c->model,
            $candidates,
        ));
    }

    public function test_unknown_nvidia_models_are_not_auto_enabled_into_fast_text(): void
    {
        $conn = $this->nvidia(43);
        SeoAiModel::query()->create([
            'api_connection_id' => $conn->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => 'google/gemma-3-12b-it',
            'display_name' => 'google/gemma-3-12b-it',
            'priority' => 10,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => true,
            'capabilities' => [],
        ]);
        (new AiModelPrimaryTypeClassifier($this->priorities))->classifyConnection($conn);
        $this->assertSame([], $this->priorities->areaEnabledModels(43, AiModelArea::TextFast));
    }

    private function nvidia(int $userId): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::NVIDIA,
            'name' => 'NVIDIA NIM',
            'api_key' => 'sk-test-key-long-enough',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }
}
