<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryCanaryAccess;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature;
use Omnichannel\Addons\AiPrompt\Support\ProductGallery\ProductGalleryPromptVariableNormalizer;

/**
 * Readiness checklist for Product Gallery canary — no provider AI calls.
 */
final class ProductGalleryCanaryReadinessService
{
    public const STATUS_OK = 'OK';

    public const STATUS_MISSING = 'Thiếu';

    public const STATUS_UNSUPPORTED = 'Không hỗ trợ';

    public function __construct(
        private readonly ArticleMediaLocalService $album,
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly ImageProviderCapabilityResolver $capabilities,
        private readonly ProductGalleryModeOrchestrator $orchestrator,
        private readonly ProductGalleryPromptsDoctorService $promptDoctor,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     article_id: int,
     *     is_canary: bool,
     *     items: list<array{key: string, label: string, status: string, detail: string}>,
     *     resolved_auto_mode: string|null,
     *     original_media_ids: list<int>,
     *     requirements: array{required: array<string, string>, optional: array<string, string>}
     * }
     */
    public function check(SeoArticle $article, string $provider = 'gemini', ?string $model = null): array
    {
        $items = [];
        $postType = ArticlePostTypeResolver::resolve($article);
        $items[] = $this->row(
            'post_type',
            'post_type = product',
            $postType === 'product' ? self::STATUS_OK : self::STATUS_MISSING,
            $postType === 'product' ? 'product' : $postType,
        );

        $article->loadMissing('articleMetas');
        $metaOk = trim((string) ($article->title ?? '')) !== ''
            && trim((string) ($article->articleMetas->firstWhere('meta_key', 'gallery_description')?->meta_value
                ?? $article->articleMetas->firstWhere('meta_key', 'loai_san_pham')?->meta_value
                ?? '')) !== '';
        $items[] = $this->row(
            'metadata',
            'product metadata',
            $metaOk ? self::STATUS_OK : self::STATUS_MISSING,
            $metaOk ? 'title + description/loai_san_pham' : 'thiếu title hoặc gallery brief',
        );

        $album = $this->album->resolveProductAlbum($article);
        $originalIds = [];
        foreach ($album as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $originalIds[] = $id;
            }
        }
        $minMedia = max(2, (int) config('seo-content-ai.product_gallery.canary.min_original_media', 2));
        $items[] = $this->row(
            'original_media',
            'original media count',
            count($originalIds) >= $minMedia ? self::STATUS_OK : self::STATUS_MISSING,
            count($originalIds).' / min '.$minMedia,
        );

        $mode1Id = $this->settings->getBoundPromptId('product.gallery.generate');
        $items[] = $this->row(
            'mode1_binding',
            'Mode 1 prompt binding',
            $mode1Id !== null ? self::STATUS_OK : self::STATUS_MISSING,
            $mode1Id !== null ? 'product.gallery.generate #'.$mode1Id : 'prompt_hook_binding_missing',
        );

        foreach ([
            'product.gallery.plan' => 'Mode 2 planner binding',
            'product.gallery.parent.generate' => 'Mode 2 parent binding',
            'product.gallery.child.generate' => 'Mode 2 child binding',
        ] as $hook => $label) {
            $id = $this->settings->getBoundPromptId($hook);
            $items[] = $this->row(
                'binding_'.str_replace('.', '_', $hook),
                $label,
                $id !== null ? self::STATUS_OK : self::STATUS_MISSING,
                $id !== null ? $hook.' #'.$id : 'prompt_hook_binding_missing',
            );
        }

        $caps = $this->capabilities->resolve($provider, $model);
        $items[] = $this->row('provider', 'provider', self::STATUS_OK, $caps->provider !== '' ? $caps->provider : $provider);
        $items[] = $this->row(
            'model',
            'model',
            $caps->model !== '' ? self::STATUS_OK : self::STATUS_MISSING,
            $caps->model !== '' ? $caps->model : '(empty — Auto may resolve sprite)',
        );

        $refStatus = match ($caps->supportStatus) {
            'supported' => self::STATUS_OK,
            'unsupported' => self::STATUS_UNSUPPORTED,
            default => self::STATUS_MISSING,
        };
        $items[] = $this->row(
            'reference_capability',
            'reference capability',
            $refStatus,
            $caps->supportStatus.($caps->allowsParentChild() ? ' (parent_child ok)' : ''),
        );

        $items[] = $this->row(
            'feature_flag',
            'feature flag',
            ProductGalleryParentChildFeature::enabled() ? self::STATUS_OK : self::STATUS_MISSING,
            ProductGalleryParentChildFeature::enabled() ? 'enabled' : 'product_gallery.parent_child.enabled=false',
        );

        $articleId = (int) $article->id;
        $items[] = $this->row(
            'allowlist',
            'article allowlist / canary fixture',
            ProductGalleryParentChildFeature::allowsArticle($articleId) ? self::STATUS_OK : self::STATUS_MISSING,
            ProductGalleryParentChildFeature::allowsArticle($articleId)
                ? 'allowed'
                : (ProductGalleryParentChildFeature::enabled()
                    ? 'not in allowlist (enable flag or is_canary meta)'
                    : 'feature disabled — Mode 2 blocked'),
        );

        $items[] = $this->row(
            'queue',
            'queue running',
            self::STATUS_OK,
            'manual check: queue seo,media_generation,default (not probed)',
        );

        $auto = $this->orchestrator->decide('auto', $provider, $model);
        $blocking = array_values(array_filter(
            $items,
            static fn (array $row): bool => in_array($row['key'], [
                'post_type',
                'original_media',
                'mode1_binding',
            ], true) && $row['status'] !== self::STATUS_OK,
        ));

        return [
            'ok' => $blocking === [],
            'article_id' => $articleId,
            'is_canary' => ProductGalleryCanaryAccess::isCanaryArticle($article),
            'items' => $items,
            'resolved_auto_mode' => (string) ($auto['route'] ?? null),
            'original_media_ids' => $originalIds,
            'requirements' => ProductGalleryCanaryFixtureService::inputRequirements(),
            'prompt_doctor_ok' => ($this->promptDoctor->diagnose()['ok'] ?? false),
            'sample_variables' => ProductGalleryPromptVariableNormalizer::sampleForHook('product.gallery.plan'),
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function row(string $key, string $label, string $status, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
