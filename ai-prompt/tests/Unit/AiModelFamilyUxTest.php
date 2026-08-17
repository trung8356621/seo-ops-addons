<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\AiCandidateOrderingService;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\ImageFamilySelectionAdapter;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Media\Support\ImageRoutingStrategy;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Seo\Support\RenderingPreference;
use App\Models\ApiConnection;
use PHPUnit\Framework\TestCase;

final class AiModelFamilyUxTest extends TestCase
{
    public function test_openrouter_style_id_maps_to_family_without_rewriting_raw_id(): void
    {
        $catalog = new AiModelFamilyCatalog();
        $family = $catalog->familyForModelId('deepseek/deepseek-chat');
        $this->assertNotNull($family);
        $this->assertSame('deepseek.chat', $family->familyKey);
        $this->assertNotContains('deepseek/deepseek-chat', $family->memberModelIds);
    }

    public function test_family_keeps_exact_provider_model_id(): void
    {
        $catalog = new AiModelFamilyCatalog();
        $family = $catalog->familyForModelId('gemini-3.1-flash-image-preview');
        $this->assertNotNull($family);
        $this->assertSame('nano_banana', $family->familyKey);
        $this->assertSame('gemini-3.1-flash-image-preview', $catalog->currentModelId($family));
        $this->assertContains('gemini-3.1-flash-image-preview', $family->memberModelIds);
    }

    public function test_presenter_hides_raw_id_in_normal_label(): void
    {
        $label = (new AiModelLabelPresenter())->normal('deepseek-chat');
        $this->assertSame('DeepSeek Chat', $label);
        $this->assertStringNotContainsString('deepseek-chat', $label);
    }

    public function test_economy_orders_flash_before_pro(): void
    {
        $flash = $this->candidate('gemini-3-flash-preview', 2);
        $pro = $this->candidate('gemini-3.1-pro-preview', 1);
        $ordered = (new AiCandidateOrderingService())->sort(
            [$pro, $flash],
            AiExecutionProfile::TextFast,
            AiUsageMode::Economy,
            false,
        );
        $this->assertSame(['gemini-3-flash-preview', 'gemini-3.1-pro-preview'], array_map(
            static fn (RoutedAiCandidate $c): string => $c->model,
            $ordered,
        ));
    }

    public function test_quality_first_orders_pro_before_flash(): void
    {
        $flash = $this->candidate('gemini-3-flash-preview', 1);
        $pro = $this->candidate('gemini-3.1-pro-preview', 2);
        $ordered = (new AiCandidateOrderingService())->sort(
            [$flash, $pro],
            AiExecutionProfile::TextLongform,
            AiUsageMode::QualityFirst,
            false,
        );
        $this->assertSame(['gemini-3.1-pro-preview', 'gemini-3-flash-preview'], array_map(
            static fn (RoutedAiCandidate $c): string => $c->model,
            $ordered,
        ));
    }

    public function test_image_profiles_do_not_reorder_by_usage_mode(): void
    {
        $flash = $this->candidate('gemini-3.1-flash-image-preview', 1);
        $pro = $this->candidate('gemini-3-pro-image-preview', 2);
        $ordered = (new AiCandidateOrderingService())->sort(
            [$flash, $pro],
            AiExecutionProfile::ImageGeneral,
            AiUsageMode::QualityFirst,
            false,
        );
        $this->assertSame('gemini-3.1-flash-image-preview', $ordered[0]->model);
        $this->assertSame('gemini-3-pro-image-preview', $ordered[1]->model);
    }

    public function test_image_family_adapter_preserves_existing_slug_order(): void
    {
        $existing = GoogleAiModelRegistry::defaultImageModelPriority();
        $adapter = new ImageFamilySelectionAdapter();
        $families = $adapter->familiesFromSlugs($existing);
        $expanded = $adapter->expandPreservingOrder($families, $existing);
        foreach ($existing as $index => $slug) {
            $this->assertSame($slug, $expanded[$index] ?? null);
        }
    }

    public function test_image_routing_strategy_default_sequence_unchanged(): void
    {
        $before = (new ImageRoutingStrategy())->modelsToTry(
            toolType: ImageToolType::Image,
            preference: RenderingPreference::Balanced,
            compiledPromptLength: 400,
            configuredPriorityList: GoogleAiModelRegistry::defaultImageModelPriority(),
        );
        $this->assertSame(
            ['gemini-3.1-flash-image-preview', 'gemini-3-pro-image-preview', 'imagen-4.0-generate-001'],
            $before,
        );
    }

    public function test_typography_profile_stays_separate_from_general_image(): void
    {
        $prompt = new SeoPrompt();
        $prompt->tools = 'image_typography';
        $prompt->hook_key = null;
        $this->assertSame(
            AiExecutionProfile::ImageTypography,
            (new PromptExecutionProfileResolver())->resolve($prompt),
        );
        $prompt->hook_key = 'product.gallery.generate';
        $prompt->tools = 'image';
        $this->assertSame(
            AiExecutionProfile::ImageProduct,
            (new PromptExecutionProfileResolver())->resolve($prompt),
        );
        $prompt->hook_key = 'article.content.generate';
        $prompt->tools = 'default';
        $this->assertSame(
            AiExecutionProfile::TextLongform,
            (new PromptExecutionProfileResolver())->resolve($prompt),
        );
    }

    private function candidate(string $model, int $priority): RoutedAiCandidate
    {
        $connection = new ApiConnection();
        $connection->id = 1;
        $connection->provider = 'gemini';
        $connection->name = 'Gemini';

        return new RoutedAiCandidate(
            profile: 'text.fast',
            connection: $connection,
            provider: 'gemini',
            model: $model,
            capabilities: ['text.generate'],
            priority: $priority,
        );
    }
}
