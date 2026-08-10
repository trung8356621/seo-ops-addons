<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Console;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ImageProviderCapabilityResolver;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryModeOrchestrator;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryParentChildDispatchService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature;
use Illuminate\Console\Command;

final class ProductGalleryParentChildCanaryCommand extends Command
{
    protected $signature = 'seo:product-gallery-parent-child-canary
        {articleId : SEO article id}
        {--provider=gemini : Provider slug}
        {--model= : Image model slug (Gemini native)}
        {--shots=3 : Requested shot count}
        {--dry-run : Validate only, no AI / no persist}
        {--execute : Dispatch live Mode 2 job}
        {--force : Bypass feature flag / allowlist}';

    protected $description = 'Dry-run or execute Product Gallery Mode 2 Parent/Child canary for one article.';

    public function handle(
        ImageProviderCapabilityResolver $capabilities,
        ProductGalleryModeOrchestrator $orchestrator,
        ProductGalleryParentChildDispatchService $dispatch,
        ArticleMediaLocalService $album,
    ): int {
        $articleId = (int) $this->argument('articleId');
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            $this->error('Article not found: '.$articleId);

            return self::FAILURE;
        }

        $postType = ArticlePostTypeResolver::resolve($article);
        if ($postType !== 'product') {
            $this->error('Article is not a product (post_type='.$postType.').');

            return self::FAILURE;
        }

        $provider = (string) $this->option('provider');
        $model = trim((string) $this->option('model')) ?: null;
        $shots = max(1, (int) $this->option('shots'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if (! $dryRun && ! $execute) {
            $this->warn('Specify --dry-run or --execute.');

            return self::FAILURE;
        }

        $items = $album->resolveProductAlbum($article);
        $originalIds = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $originalIds[] = $id;
            }
        }

        $caps = $capabilities->resolve($provider, $model);
        $decision = $orchestrator->decide('parent_child', $provider, $model);

        $this->table(['Field', 'Value'], [
            ['article_id', (string) $articleId],
            ['title', (string) ($article->title ?? '')],
            ['feature_enabled', ProductGalleryParentChildFeature::enabled() ? 'yes' : 'no'],
            ['allows_article', ProductGalleryParentChildFeature::allowsArticle($articleId) ? 'yes' : 'no'],
            ['original_media_count', (string) count($originalIds)],
            ['provider', $caps->provider],
            ['model', $caps->model !== '' ? $caps->model : '(auto)'],
            ['support_status', $caps->supportStatus],
            ['reference_transport_ready', $caps->referenceTransportReady ? 'yes' : 'no'],
            ['allows_parent_child', $caps->allowsParentChild() ? 'yes' : 'no'],
            ['resolved_route', $decision['route']],
            ['shots', (string) $shots],
        ]);

        if ($originalIds === []) {
            $this->error('No original/reference media on product album.');

            return self::FAILURE;
        }

        if (! $caps->allowsParentChild()) {
            $this->error('Provider/model unsupported for reference images — Mode 2 blocked.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $promptDoctor = app(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPromptsDoctorService::class);
            $promptReport = $promptDoctor->diagnose();
            foreach ($promptReport['lines'] as $line) {
                $this->line(str_pad($line['label'].' ', 40, '.', STR_PAD_RIGHT).' '.$line['status']);
            }
            if (! $promptReport['ok']) {
                $this->error('Dry-run blocked — Prompt/Hook binding incomplete.');
                foreach ($promptReport['errors'] as $error) {
                    $this->line(' - '.$error);
                }

                return self::FAILURE;
            }

            $this->info('Dry-run OK — prompts/hooks/capability validated; no AI call.');

            return self::SUCCESS;
        }

        if (! $force && ! ProductGalleryParentChildFeature::allowsArticle($articleId)) {
            $this->error('Feature disabled / article not in canary allowlist. Use --force for explicit override.');

            return self::FAILURE;
        }

        $started = $dispatch->start(
            article: $article,
            requestedMode: 'parent_child',
            originalSnapshotIds: $originalIds,
            variables: [
                'product_title' => (string) ($article->title ?? ''),
                'title' => (string) ($article->title ?? ''),
                'product_identity' => (string) ($article->title ?? ''),
                'requested_image_count' => $shots,
                'original_media_ids' => $originalIds,
            ],
            provider: $provider,
            model: $model,
            requestedImageCount: $shots,
            force: $force,
        );

        $this->line(json_encode($started, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');

        return ($started['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
