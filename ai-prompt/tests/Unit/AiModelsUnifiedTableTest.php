<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Tests\TestCase;

final class AiModelsUnifiedTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['seo_ai_models', 'api_connections'] as $table) {
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
            $table->string('display_name');
            $table->integer('priority')->default(100);
            $table->string('status')->default('active');
            $table->boolean('is_hidden')->default(false);
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
    }

    public function test_known_flash_releases_collapse_to_one_family_row(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::GEMINI,
            'name' => 'Gemini',
            'api_key' => 'x',
            'status' => 'active',
        ]);
        foreach (['gemini-3-flash-preview', 'gemini-3.5-flash-preview', 'gemini-3.1-flash-lite'] as $raw) {
            SeoAiModel::query()->create([
                'api_connection_id' => $connection->id,
                'category' => 'gemini_flash',
                'raw_model_name' => $raw,
                'display_name' => $raw,
                'status' => 'active',
                'is_hidden' => false,
            ]);
        }
        $rows = (new AiCenterModelPresenter())->tableRows(1);
        $flash = array_values(array_filter($rows, static fn (array $row): bool => ($row['family_key'] ?? '') === 'gemini.flash'));
        $this->assertCount(1, $flash);
        $this->assertSame('Gemini Flash', $flash[0]['label']);
        $this->assertSame(3, $flash[0]['release_count']);
        $this->assertCount(3, $flash[0]['ids']);
        $this->assertNotSame($flash[0]['ids'][0], $flash[0]['ids'][1]);
    }

    public function test_unknown_models_are_not_in_normal_table(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'x',
            'status' => 'active',
        ]);
        SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => 'unknown',
            'raw_model_name' => 'acme-experimental-9b',
            'display_name' => 'Acme Experimental',
            'status' => 'active',
            'is_hidden' => true,
        ]);
        $normal = (new AiCenterModelPresenter())->tableRows(1);
        $this->assertSame([], $normal);
        $technical = (new AiCenterModelPresenter())->tableRows(1, ['technical' => true]);
        $this->assertCount(1, $technical);
        $this->assertTrue($technical[0]['unknown']);
    }

    public function test_hidden_families_are_omitted_until_show_hidden(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'name' => 'DeepSeek',
            'api_key' => 'x',
            'status' => 'active',
        ]);
        $model = SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => 'deepseek',
            'raw_model_name' => 'deepseek-chat',
            'display_name' => 'DeepSeek Chat',
            'status' => 'active',
            'is_hidden' => true,
        ]);
        $presenter = new AiCenterModelPresenter();
        $this->assertSame([], $presenter->tableRows(1));
        $shown = $presenter->tableRows(1, ['show_hidden' => true]);
        $this->assertCount(1, $shown);
        $this->assertFalse($shown[0]['visible']);
        $presenter->setHidden(1, [(int) $model->id], false);
        $this->assertTrue($presenter->tableRows(1)[0]['visible']);
    }

    public function test_view_has_one_models_table_not_nested_provider_cards(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/../seo-content-ai-compat/resources/views/filament/pages/seo-settings-ai-center.blade.php');
        $addons = str_replace('\\', '/', dirname(__DIR__, 3));
        unset($addons);
        $path = (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter::class))->getFileName();
        $viewPath = dirname($path, 5).'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-ai-center.blade.php';
        if (! is_file($viewPath)) {
            $viewPath = \Tests\Support\ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-ai-center.blade.php';
        }
        $view = (string) file_get_contents($viewPath);
        $this->assertStringNotContainsString('<table class="seo-ai-models-table w-full table-fixed', $view);
        $this->assertStringContainsString('sortable-ai-model-list', $view);
        $this->assertStringContainsString('areaModelRowsFor', $view);
        $this->assertStringContainsString('openModelPicker', $view);
        $this->assertStringContainsString('add_area_models', $view);
        $this->assertStringNotContainsString('seo-ai-connection-block', $view);
        $this->assertStringNotContainsString('seo-ai-models-table--grouped', $view);
    }

    public function test_catalog_does_not_naively_truncate_ids(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(AiModelFamilyCatalog::class))->getFileName());
        $this->assertStringNotContainsString("explode('-',", $src);
        $this->assertNull((new AiModelFamilyCatalog())->familyForModelId('totally-unknown-flash-model'));
        $this->assertNotNull((new AiModelFamilyCatalog())->familyForModelId('gemini-3-flash-preview'));
    }

    public function test_short_family_label_strips_provider_prefix_only(): void
    {
        $presenter = new AiCenterModelPresenter();
        $this->assertSame('Flash', $presenter->shortFamilyLabel('Gemini Flash', 'Google Gemini'));
        $this->assertSame('Chat', $presenter->shortFamilyLabel('DeepSeek Chat', 'DeepSeek'));
        $this->assertSame('Nano Banana Pro', $presenter->shortFamilyLabel('Nano Banana Pro', 'Google Gemini'));
        $this->assertSame('Imagen', $presenter->shortFamilyLabel('Imagen', 'Google Gemini'));
        $this->assertSame(
            'Google · Gemini Flash',
            $presenter->displayLabel(ApiConnectionProviders::OPENROUTER, 'google/gemini-3-flash-preview', 'Gemini Flash'),
        );
        $this->assertSame(
            'Anthropic · Claude Sonnet',
            $presenter->displayLabel(ApiConnectionProviders::OPENROUTER, 'anthropic/claude-sonnet-4-20250514', 'Claude Sonnet'),
        );
        $this->assertSame('Flash', $presenter->shortFamilyLabel('Gemini Flash', 'Google Gemini'));
    }

    public function test_openrouter_does_not_inherit_direct_provider_enabled_state(): void
    {
        $gemini = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::GEMINI,
            'name' => 'Gemini',
            'api_key' => 'g',
            'status' => 'active',
        ]);
        $openrouter = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'o',
            'status' => 'active',
        ]);
        SeoAiModel::query()->create([
            'api_connection_id' => $gemini->id,
            'category' => 'gemini_flash',
            'raw_model_name' => 'gemini-3-flash-preview',
            'display_name' => 'Gemini Flash',
            'status' => 'active',
            'is_hidden' => false,
        ]);
        SeoAiModel::query()->create([
            'api_connection_id' => $openrouter->id,
            'category' => 'gemini_flash',
            'raw_model_name' => 'google/gemini-3-flash-preview',
            'display_name' => 'Gemini Flash',
            'status' => 'active',
            'is_hidden' => true,
        ]);
        $presenter = new AiCenterModelPresenter();
        $rows = $presenter->tableRows(1);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $gemini->id, $rows[0]['connection_id']);
        $this->assertTrue($rows[0]['visible']);
        $available = $presenter->availablePage(1, (int) $openrouter->id);
        $this->assertSame(1, $available['total']);
        $this->assertSame((int) $openrouter->id, $available['rows'][0]['connection_id']);
        $this->assertFalse($available['rows'][0]['visible']);
        $presenter->setHidden(1, $available['rows'][0]['ids'], false);
        $enabled = $presenter->tableRows(1);
        $this->assertCount(2, $enabled);
        $ids = array_map(static fn (array $row): int => (int) $row['connection_id'], $enabled);
        $this->assertContains((int) $gemini->id, $ids);
        $this->assertContains((int) $openrouter->id, $ids);
        $presenter->setHidden(1, $available['rows'][0]['ids'], true);
        $this->assertCount(1, $presenter->tableRows(1));
        $this->assertSame(1, $presenter->availablePage(1, (int) $openrouter->id)['total']);
    }

    public function test_new_openrouter_inventory_starts_disabled_and_paginates(): void
    {
        $openrouter = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'o',
            'status' => 'active',
        ]);
        for ($i = 1; $i <= 120; $i++) {
            SeoAiModel::query()->create([
                'api_connection_id' => $openrouter->id,
                'category' => 'unknown',
                'raw_model_name' => 'vendor/model-'.$i,
                'display_name' => 'Model '.$i,
                'status' => 'active',
                'is_hidden' => true,
            ]);
        }
        SeoAiModel::query()->create([
            'api_connection_id' => $openrouter->id,
            'category' => 'gemini_flash',
            'raw_model_name' => 'google/gemini-3-flash-preview',
            'display_name' => 'Gemini Flash',
            'status' => 'active',
            'is_hidden' => true,
        ]);
        $presenter = new AiCenterModelPresenter();
        $this->assertSame([], $presenter->tableRows(1));
        $counts = $presenter->counts(1);
        $this->assertSame(121, $counts['discovered']);
        $this->assertSame(0, $counts['enabled']);
        $this->assertSame(121, $counts['available']);
        $page = $presenter->availablePage(1, (int) $openrouter->id, ['search' => 'gemini'], 1, 50);
        $this->assertGreaterThanOrEqual(1, $page['total']);
        $this->assertLessThanOrEqual(50, count($page['rows']));
        $all = $presenter->availablePage(1, (int) $openrouter->id, ['area' => 'text'], 1, 50);
        $this->assertLessThanOrEqual(50, count($all['rows']));
        $this->assertSame(1, $all['total']);
        $unknown = $presenter->availablePage(1, (int) $openrouter->id, ['status' => 'unknown'], 1, 50);
        $this->assertSame(50, count($unknown['rows']));
        $this->assertSame(120, $unknown['total']);
    }

    public function test_unknown_enabled_model_appears_in_main_list(): void
    {
        $connection = ApiConnection::query()->create([
            'user_id' => 1,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OpenRouter',
            'api_key' => 'x',
            'status' => 'active',
        ]);
        SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => 'unknown',
            'raw_model_name' => 'acme-experimental-9b',
            'display_name' => 'Acme Experimental',
            'status' => 'active',
            'is_hidden' => false,
        ]);
        $rows = (new AiCenterModelPresenter())->tableRows(1);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['unknown']);
        $this->assertSame([], (new AiCenterModelPresenter())->areaRows(1, \Omnichannel\Addons\AiPrompt\Support\AiModelArea::Text));
    }
}
