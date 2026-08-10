<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\AiPrompt\Support\ProductGallery\ProductGalleryPromptVariableNormalizer;

/**
 * Compile Product Gallery prompts for canary preview — no AI / no base64.
 */
final class ProductGalleryCanaryPromptPreviewService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly PromptRunnerService $promptRunner,
        private readonly ProductGalleryPromptHookRuntime $mode2Runtime,
        private readonly ArticleMediaLocalService $album,
        private readonly ProductGalleryModeOrchestrator $orchestrator,
    ) {}

    /**
     * @return array{
     *     mode1: array{ok: bool, prompt_id: ?int, compiled: string, error: string},
     *     mode2_plan: array{ok: bool, prompt_id: ?int, compiled: string, error: string},
     *     mode2_parent: array{ok: bool, prompt_id: ?int, compiled: string, error: string},
     *     mode2_child: array{ok: bool, prompt_id: ?int, compiled: string, error: string},
     *     meta: array<string, mixed>
     * }
     */
    public function preview(SeoArticle $article, string $provider = 'gemini', ?string $model = null, int $shots = 3): array
    {
        $album = $this->album->resolveProductAlbum($article);
        $originalIds = [];
        foreach ($album as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $originalIds[] = $id;
            }
        }

        $baseVars = [
            'product_title' => (string) ($article->title ?? ''),
            'title' => (string) ($article->title ?? ''),
            'keyword' => $this->meta($article, 'seo_focus_keyword'),
            'product_description' => $this->meta($article, 'gallery_description'),
            'product_attributes' => $this->meta($article, 'product_attributes'),
            'product_identity' => $this->meta($article, 'product_identity') ?: (string) ($article->title ?? ''),
            'negative_constraints' => $this->joinJsonList($this->meta($article, 'negative_constraints')),
            'requested_image_count' => $shots,
            'language' => (string) ($article->language ?? 'vi'),
            'product_category' => $this->meta($article, 'product_category') ?: $this->meta($article, 'loai_san_pham'),
            'product_brand' => $this->meta($article, 'product_brand'),
            'primary_color' => $this->meta($article, 'primary_color'),
            'secondary_color' => $this->meta($article, 'secondary_color'),
            'material' => $this->meta($article, 'material'),
            'product_shape' => $this->meta($article, 'product_shape'),
            'distinctive_features' => $this->joinJsonList($this->meta($article, 'distinctive_features')),
            'original_media_ids' => $originalIds,
            'shot_key' => 'front',
            'shot_label' => 'Mặt trước',
            'aspect_ratio' => '1:1',
            'shot_instruction' => 'Góc mặt trước, nền sạch, một sản phẩm.',
            'parent_media_id' => '0',
        ];

        $decision = $this->orchestrator->decide('auto', $provider, $model);

        return [
            'mode1' => $this->compileMode1($baseVars),
            'mode2_plan' => $this->compileMode2('product.gallery.plan', ProductGalleryPromptVariableNormalizer::forPlan($baseVars)),
            'mode2_parent' => $this->compileMode2('product.gallery.parent.generate', ProductGalleryPromptVariableNormalizer::forParent($baseVars)),
            'mode2_child' => $this->compileMode2('product.gallery.child.generate', ProductGalleryPromptVariableNormalizer::forChild($baseVars)),
            'meta' => [
                'article_id' => (int) $article->id,
                'reference_media_ids' => $originalIds,
                'provider' => $provider,
                'model' => $model,
                'requested_shots' => $shots,
                'resolved_auto_mode' => $decision['route'] ?? null,
                'reference_note' => 'Reference image attachment: supplied at provider runtime — not embedded in text prompt',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{ok: bool, prompt_id: ?int, compiled: string, error: string}
     */
    private function compileMode1(array $variables): array
    {
        $promptId = $this->settings->getBoundPromptId('product.gallery.generate');
        if ($promptId === null) {
            return ['ok' => false, 'prompt_id' => null, 'compiled' => '', 'error' => 'prompt_hook_binding_missing'];
        }
        $prompt = SeoPrompt::query()->find($promptId);
        if (! $prompt instanceof SeoPrompt) {
            return ['ok' => false, 'prompt_id' => $promptId, 'compiled' => '', 'error' => 'prompt_not_found'];
        }

        $stringVars = [];
        foreach ($variables as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $stringVars[$key] = (string) ($value ?? '');
            } else {
                $stringVars[$key] = json_encode($value) ?: '';
            }
        }

        try {
            $compiled = $this->sanitize($this->promptRunner->compilePrompt($prompt, $stringVars));
        } catch (\Throwable $exception) {
            return ['ok' => false, 'prompt_id' => $promptId, 'compiled' => '', 'error' => $exception->getMessage()];
        }

        return ['ok' => $compiled !== '', 'prompt_id' => $promptId, 'compiled' => $compiled, 'error' => ''];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{ok: bool, prompt_id: ?int, compiled: string, error: string}
     */
    private function compileMode2(string $hookKey, array $variables): array
    {
        try {
            $compiled = $this->mode2Runtime->compile($hookKey, $variables);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'prompt_id' => $this->settings->getBoundPromptId($hookKey),
                'compiled' => '',
                'error' => $exception->getMessage(),
            ];
        }

        $promptId = (int) ($compiled['prompt_id'] ?? 0);

        return [
            'ok' => true,
            'prompt_id' => $promptId > 0 ? $promptId : null,
            'compiled' => $this->sanitize((string) ($compiled['final_prompt'] ?? '')),
            'error' => '',
        ];
    }

    private function sanitize(string $text): string
    {
        $text = trim($text);
        if (str_contains($text, 'data:image/')) {
            $text = preg_replace('/data:image\/[a-zA-Z0-9+.-]+;base64,[A-Za-z0-9+\/=]+/', '[binary_stripped]', $text) ?? $text;
        }
        if (preg_match('/\b[A-Za-z0-9+\/=]{800,}\b/', $text) === 1) {
            $text = preg_replace('/\b[A-Za-z0-9+\/=]{800,}\b/', '[binary_stripped]', $text) ?? $text;
        }

        return $text;
    }

    private function meta(SeoArticle $article, string $key): string
    {
        $article->loadMissing('articleMetas');

        return trim((string) ($article->articleMetas->firstWhere('meta_key', $key)?->meta_value ?? ''));
    }

    private function joinJsonList(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return implode('; ', array_map('strval', $decoded));
        }

        return $raw;
    }
}
