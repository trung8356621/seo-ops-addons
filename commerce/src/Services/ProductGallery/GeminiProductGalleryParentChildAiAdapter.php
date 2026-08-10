<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Seo\Contracts\ProductGalleryParentChildAiPort;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\Media\Services\GeminiMediaGenerationService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ImageRoutingStrategy;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGenerationMode;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGlobalContext;
use Omnichannel\Addons\AiPrompt\Support\ProductGallery\ProductGalleryPromptVariableNormalizer;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryShotDefinition;
use Omnichannel\Addons\AiPrompt\Support\PromptMediaPersistContext;
use App\Models\ApiConnection;
use App\Support\RuntimeLogger;

/**
 * Live Mode 2 AI port — Gemini native image + Prompt Hook runtime compile (no fallback brief).
 */
final class GeminiProductGalleryParentChildAiAdapter implements ProductGalleryParentChildAiPort
{
    public const HOOK_PLAN = 'product.gallery.plan';

    public const HOOK_PARENT = 'product.gallery.parent.generate';

    public const HOOK_CHILD = 'product.gallery.child.generate';

    /** Live runtime must never invent a hardcoded brief when Hook compile fails. */
    public const FALLBACK_BRIEF_ENABLED = false;

    public function __construct(
        private readonly ProductGalleryPromptHookRuntime $promptHooks,
        private readonly GeminiMediaGenerationService $geminiImages,
        private readonly ProductGalleryReferenceImageResolver $references,
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly ImageProviderCapabilityResolver $capabilities,
        private readonly ImageRoutingStrategy $imageRouting,
    ) {}

    public function runPlanner(SeoArticle $article, array $variables): string
    {
        $input = ProductGalleryPromptVariableNormalizer::forPlan(
            $variables,
            (string) ($article->title ?? ''),
        );

        try {
            return $this->promptHooks->executeText(self::HOOK_PLAN, $input, [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
            ]);
        } catch (\Throwable $exception) {
            throw new \RuntimeException($this->mapHookFailure($exception), 0, $exception);
        }
    }

    public function generateParent(SeoArticle $article, array $variables): ?SeoMedia
    {
        $connection = $this->resolveGeminiConnection($variables);
        $model = $this->resolveImageModel($variables, $connection);
        $caps = $this->capabilities->resolve('gemini', $model);
        if (! $caps->allowsParentChild()) {
            throw new \RuntimeException('reference_transport_unsupported');
        }

        $promptText = $this->compileImageHookPrompt(self::HOOK_PARENT, $article, $variables);
        $refs = $this->optionalOriginalReferences($variables, $model);

        $rendered = $this->geminiImages->generateNativeImageWithReferences(
            $connection,
            $promptText,
            $model,
            $refs,
        );

        return $this->persistGeneratedMedia(
            $article,
            $rendered['binary'],
            $rendered['mime'],
            $rendered['model_used'],
            ProductGalleryArtifactRole::GENERATED_PARENT,
            $variables,
        );
    }

    public function generateChild(
        SeoArticle $article,
        SeoMedia $parent,
        ProductGalleryShotDefinition $shot,
        ProductGalleryGlobalContext $context,
        array $variables,
    ): ?SeoMedia {
        $connection = $this->resolveGeminiConnection($variables);
        $model = $this->resolveImageModel($variables, $connection);
        $caps = $this->capabilities->resolve('gemini', $model);
        if (! $caps->allowsParentChild()) {
            throw new \RuntimeException('reference_transport_unsupported');
        }

        $payload = $this->references->resolveFromMedia($parent, 'gemini', $model);
        if (! $payload->isUsable()) {
            $code = (string) ($payload->meta['error_code'] ?? 'reference_media_missing');
            throw new \RuntimeException($code);
        }

        RuntimeLogger::info('seo.product_gallery.child_reference_ready', $payload->toLogContext() + [
            'article_id' => (int) $article->id,
            'shot_key' => $shot->shotKey,
            'slot' => $shot->slot,
        ]);

        $childVars = array_merge($variables, [
            'shot_key' => $shot->shotKey,
            'shot_label' => $shot->label,
            'shot_instruction' => $shot->instruction,
            'aspect_ratio' => $shot->aspectRatio,
            'parent_media_id' => (string) $parent->id,
            'product_identity' => $context->productIdentity,
            'negative_constraints' => implode('; ', $context->negativeConstraints),
            'primary_color' => $context->primaryColor,
            'secondary_color' => $context->secondaryColor,
            'material' => $context->material,
            'product_shape' => $context->shape,
            'distinctive_features' => is_array($context->distinctiveFeatures)
                ? implode('; ', $context->distinctiveFeatures)
                : (string) $context->distinctiveFeatures,
        ]);

        $promptText = $this->compileImageHookPrompt(self::HOOK_CHILD, $article, $childVars);

        $rendered = $this->geminiImages->generateNativeImageWithReferences(
            $connection,
            $promptText,
            $model,
            [$payload->toGeminiInlinePart()],
        );

        return $this->persistGeneratedMedia(
            $article,
            $rendered['binary'],
            $rendered['mime'],
            $rendered['model_used'],
            ProductGalleryArtifactRole::GENERATED_CHILD_REFERENCE,
            array_merge($childVars, [
                'parent_media_id' => (int) $parent->id,
                'slot_index' => $shot->slot,
                'shot_key' => $shot->shotKey,
                'gallery_generation_mode' => ProductGalleryGenerationMode::ParentChild->value,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return list<array{mime_type: string, base64: string}>
     */
    private function optionalOriginalReferences(array $variables, string $model): array
    {
        $ids = $variables['original_media_ids'] ?? $variables['original_media_snapshot_ids'] ?? [];
        if (! is_array($ids) || $ids === []) {
            return [];
        }

        $out = [];
        foreach (array_slice($ids, 0, 2) as $id) {
            $media = SeoMedia::query()->find((int) $id);
            if (! $media instanceof SeoMedia) {
                continue;
            }
            $payload = $this->references->resolveFromMedia($media, 'gemini', $model);
            if ($payload->isUsable()) {
                $out[] = $payload->toGeminiInlinePart();
            }
        }

        return $out;
    }

    /**
     * Assemble Prompt Hook text only — never execute image AI, never invent fallback brief.
     *
     * @param  array<string, mixed>  $variables
     */
    private function compileImageHookPrompt(string $hookKey, SeoArticle $article, array $variables): string
    {
        if (self::FALLBACK_BRIEF_ENABLED) {
            throw new \RuntimeException('fallback_brief_misconfigured');
        }

        $normalized = $hookKey === self::HOOK_CHILD
            ? ProductGalleryPromptVariableNormalizer::forChild($variables, (string) ($article->title ?? ''))
            : ProductGalleryPromptVariableNormalizer::forParent($variables, (string) ($article->title ?? ''));

        if (trim((string) ($normalized['product_title'] ?? '')) === '') {
            throw new \RuntimeException('prompt_variable_missing:product_title');
        }

        if ($hookKey === self::HOOK_CHILD && trim((string) ($normalized['shot_instruction'] ?? '')) === '') {
            throw new \RuntimeException('prompt_variable_missing:shot_instruction');
        }

        if ($hookKey === self::HOOK_CHILD && trim((string) ($normalized['shot_key'] ?? '')) === '') {
            throw new \RuntimeException('prompt_variable_missing:shot_key');
        }

        try {
            $compiled = $this->promptHooks->compile($hookKey, $normalized);
        } catch (InvalidInput $exception) {
            throw new \RuntimeException('prompt_variable_missing', 0, $exception);
        } catch (\Throwable $exception) {
            throw new \RuntimeException($this->mapHookFailure($exception), 0, $exception);
        }

        $final = trim((string) ($compiled['final_prompt'] ?? ''));
        if ($final === '') {
            throw new \RuntimeException('prompt_variable_missing:compiled_empty');
        }

        // Guard: never leak accidental binary into compiled text vars.
        if (str_contains($final, 'data:image/') || preg_match('/\b[A-Za-z0-9+\/=]{800,}\b/', $final) === 1) {
            throw new \RuntimeException('prompt_variable_missing:binary_in_text_prompt');
        }

        return $final;
    }

    private function mapHookFailure(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        foreach ([
            'prompt_hook_binding_missing',
            'prompt_not_found',
            'prompt_variable_missing',
            'planner_invalid_output',
        ] as $code) {
            if ($message === $code || str_starts_with($message, $code)) {
                return $message;
            }
        }

        if ($exception instanceof InvalidInput) {
            return 'prompt_variable_missing';
        }

        return 'prompt_hook_binding_missing:'.mb_substr($message, 0, 120);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function persistGeneratedMedia(
        SeoArticle $article,
        string $binary,
        string $mime,
        string $modelUsed,
        string $artifactRole,
        array $variables,
    ): SeoMedia {
        $promptVariables = array_merge($variables, [
            ProductGalleryArtifactRole::KEY => $artifactRole,
            'gallery_generation_mode' => ProductGalleryGenerationMode::ParentChild->value,
        ]);

        return PromptMediaPersistContext::using(
            (int) ($article->site_id ?? 0) ?: null,
            (int) $article->id,
            null,
            function () use ($binary, $mime, $modelUsed, $promptVariables, $article): SeoMedia {
                $url = app(\Omnichannel\Addons\AiPrompt\Services\PromptMediaStorageService::class)
                    ->storeBinaryMedia($binary, $mime, 'image', $modelUsed);

                $relative = ltrim(str_replace('\\', '/', (string) parse_url($url, PHP_URL_PATH)), '/');
                $relative = preg_replace('#^storage/#', '', $relative) ?? $relative;

                $media = SeoMedia::query()
                    ->where('url', $url)
                    ->orWhere('path', $relative)
                    ->latest('id')
                    ->first();

                if (! $media instanceof SeoMedia) {
                    $media = SeoMedia::query()->create([
                        'site_id' => (int) ($article->site_id ?? 0) ?: null,
                        'path' => $relative,
                        'url' => $url,
                        'source' => 'ai_prompt',
                        'ai_generator' => $modelUsed,
                        'status' => 'completed',
                        'prompt_variables' => $promptVariables,
                        'editor_block_id' => 'product-gallery',
                    ]);
                } else {
                    $media->update([
                        'prompt_variables' => array_merge(
                            is_array($media->prompt_variables) ? $media->prompt_variables : [],
                            $promptVariables,
                        ),
                        'status' => 'completed',
                        'editor_block_id' => 'product-gallery',
                    ]);
                }

                return $media->fresh() ?? $media;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function resolveGeminiConnection(array $variables): ApiConnection
    {
        $id = (int) ($variables['ai_connection_id'] ?? 0);
        if ($id > 0) {
            $conn = ApiConnection::query()->find($id);
            if ($conn instanceof ApiConnection && $conn->provider === 'gemini') {
                return $conn;
            }
        }

        $conn = ApiConnection::query()
            ->where('provider', 'gemini')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if (! $conn instanceof ApiConnection) {
            throw new \RuntimeException('parent_generation_failed:no_gemini_connection');
        }

        return $conn;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function resolveImageModel(array $variables, ApiConnection $connection): string
    {
        $requested = trim((string) ($variables['model'] ?? $variables['image_model'] ?? ''));
        if ($requested !== '') {
            $slug = GoogleAiModelRegistry::normalizeSlug($requested);
            if ($this->capabilities->resolve('gemini', $slug)->allowsParentChild()) {
                return $slug;
            }
        }

        $eligible = $this->imageRouting->modelsToTry(
            toolType: ImageToolType::Image,
            preference: $this->settings->getRenderingPreference(),
            productContext: true,
            configuredPriorityList: $this->settings->getImageModelPriority(),
            adminEnabledUnknownSlugs: $this->settings->getAdminEnabledUnknownImageModels(),
        );

        foreach ($eligible as $slug) {
            if ($this->capabilities->resolve('gemini', $slug)->allowsParentChild()) {
                return $slug;
            }
        }

        throw new \RuntimeException('Không có model ảnh hỗ trợ Parent/Child trong cấu hình hiện tại.');
    }
}
