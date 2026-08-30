<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskUniqueWriter;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryCanaryAccess;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create a real Content Project product shell for Product Gallery manual canary.
 * Uses production models/services — no raw SQL, no demo route.
 */
final class ProductGalleryCanaryFixtureService
{
    public const CANARY_TYPE = 'product_gallery';

    public const DEFAULT_TITLE = 'Túi đeo chéo màu đen Urban Mini';

    public const DEFAULT_KEYWORD = 'túi đeo chéo màu đen';

    public const DEFAULT_DESCRIPTION = 'Túi đeo chéo nhỏ gọn màu đen, chất liệu vải chống thấm, '
        .'một ngăn chính, một túi trước có khóa kéo, dây đeo điều chỉnh được, khóa kéo màu bạc.';

    /**
     * @return array{
     *     title: string,
     *     keyword: string,
     *     description: string,
     *     brand: string,
     *     category: string,
     *     primary_color: string,
     *     secondary_color: string,
     *     material: string,
     *     shape: string,
     *     distinctive_features: list<string>,
     *     negative_constraints: list<string>,
     *     loai_san_pham: string,
     *     language: string
     * }
     */
    public static function defaultProductPayload(): array
    {
        return [
            'title' => self::DEFAULT_TITLE,
            'keyword' => self::DEFAULT_KEYWORD,
            'description' => self::DEFAULT_DESCRIPTION,
            'brand' => 'Demo Brand',
            'category' => 'Túi đeo chéo',
            'primary_color' => 'Đen',
            'secondary_color' => 'Bạc',
            'material' => 'Vải chống thấm',
            'shape' => 'Hình hộp chữ nhật bo góc',
            'distinctive_features' => [
                'một túi trước',
                'hai khóa kéo màu bạc',
                'dây đeo đen điều chỉnh được',
                'logo nhỏ ở góc phải mặt trước',
            ],
            'negative_constraints' => [
                'không đổi màu',
                'không thêm túi',
                'không đổi số lượng khóa kéo',
                'không thêm chữ lớn',
                'không thêm phụ kiện',
            ],
            'loai_san_pham' => 'Túi đeo chéo',
            'language' => 'vi',
        ];
    }

    /**
     * Required vs optional for Product Gallery canary (operator checklist).
     *
     * @return array{required: array<string, string>, optional: array<string, string>}
     */
    public static function inputRequirements(): array
    {
        return [
            'required' => [
                'post_type' => 'Must be product (article.type).',
                'site_id' => 'Domain/site that owns the Content Project.',
                'keyword' => 'Focus keyword for task/article.',
                'title' => 'Product title.',
                'original_media' => 'At least 2 connected product-album media IDs (operator picks from library).',
                'mode1_prompt_binding' => 'Settings binding product.gallery.generate (Mode 1 sprite).',
                'workflow_or_prompt' => 'Product gallery workflow OR Mode 1 prompt configured in Settings.',
            ],
            'optional' => [
                'description / gallery_description' => 'Product brief for prompts.',
                'loai_san_pham' => 'Product category label for Mode 1 variables.',
                'product attributes' => 'brand, colors, material, shape, distinctive_features, negative_constraints (Mode 2).',
                'mode2_bindings' => 'product.gallery.plan / parent / child — required only when testing Parent/Child.',
                'feature_flag' => 'product_gallery.parent_child.enabled + allowlist/auto_allow fixture.',
                'assigned_workflow' => 'Full Content Project workflow run — not required for gallery modal-only canary.',
                'article body' => 'Not required for Product Gallery shell test.',
            ],
        ];
    }

    public function __construct(
        private readonly SeoProjectTaskUniqueWriter $taskWriter,
        private readonly ArticleMediaLocalService $album,
    ) {}

    /**
     * @param  list<int>  $mediaIds
     * @param  array<string, mixed>  $overrides
     * @return array{
     *     project_id: int,
     *     task_id: int,
     *     article_id: int,
     *     original_media_ids: list<int>,
     *     editor_url: string,
     *     project_url: string,
     *     canary_page_url: string
     * }
     */
    public function create(
        int $siteId,
        array $mediaIds,
        ?int $userId = null,
        array $overrides = [],
    ): array {
        if (! ProductGalleryCanaryAccess::allowsUi() && ! app()->runningInConsole()) {
            throw ValidationException::withMessages([
                'access' => 'Product Gallery canary fixture UI is not available.',
            ]);
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            throw ValidationException::withMessages(['site_id' => 'Domain/site not found.']);
        }

        $userId = (int) ($userId ?: auth()->id());
        if ($userId <= 0) {
            throw ValidationException::withMessages(['user_id' => 'User required.']);
        }

        $minMedia = max(2, (int) config('seo-content-ai.product_gallery.canary.min_original_media', 2));
        $mediaIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $mediaIds,
        ), static fn (int $id): bool => $id > 0)));

        if (count($mediaIds) < $minMedia) {
            throw ValidationException::withMessages([
                'media' => "Select at least {$minMedia} original media IDs from Media Library.",
            ]);
        }

        $resolvedMedia = [];
        foreach ($mediaIds as $mediaId) {
            $media = SeoMedia::query()->find($mediaId);
            if (! $media instanceof SeoMedia) {
                throw ValidationException::withMessages([
                    'media' => "SeoMedia #{$mediaId} not found.",
                ]);
            }
            $url = trim((string) ($media->url ?? ''));
            if ($url === '') {
                throw ValidationException::withMessages([
                    'media' => "SeoMedia #{$mediaId} has empty URL.",
                ]);
            }
            $resolvedMedia[] = ['id' => $mediaId, 'url' => $url, 'media' => $media];
        }

        $payload = array_merge(self::defaultProductPayload(), $overrides);
        $title = trim((string) ($payload['title'] ?? self::DEFAULT_TITLE));
        $keyword = trim((string) ($payload['keyword'] ?? self::DEFAULT_KEYWORD));
        $description = trim((string) ($payload['description'] ?? self::DEFAULT_DESCRIPTION));

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $siteId,
            $userId,
            $resolvedMedia,
            $payload,
            $title,
            $keyword,
            $description,
        ): array {
            $month = Carbon::now()->startOfMonth();
            $project = SeoProject::query()->create([
                'name' => '[Canary PG] '.$month->format('Y-m').' '.Str::limit($title, 40, ''),
                'user_id' => $userId,
                'site_id' => $siteId,
                'month' => $month->format('Y-m-d'),
                'status' => SeoProject::STATUS_MANUAL,
                'kind' => SeoProject::KIND_MONTHLY,
                'total_tasks' => 1,
                'description' => 'Product Gallery canary fixture — not production content.',
            ]);

            $task = $this->taskWriter->createOrReturnExisting([
                'project_id' => (int) $project->id,
                'site_id' => $siteId,
                'type' => SeoProjectTask::TYPE_CREATE,
                'source_content' => $keyword !== '' ? $keyword : $title,
                'keyword' => $keyword,
                'title' => $title,
                'post_type' => SeoProjectTask::POST_TYPE_PRODUCT,
                'description' => $description,
                'loai_san_pham' => (string) ($payload['loai_san_pham'] ?? 'Túi đeo chéo'),
                'status' => SeoProjectTask::STATUS_WRITING,
                'target_date' => $month->format('Y-m-d'),
            ]);

            $slug = Str::slug($keyword !== '' ? $keyword : $title);
            $classification = ArticleContentClassification::fromTaskPostType(SeoProjectTask::POST_TYPE_PRODUCT);
            $article = SeoArticle::query()->create([
                'site_id' => $siteId,
                'user_id' => $userId,
                'title' => $title,
                'slug' => $slug !== '' ? $slug.'-canary-'.Str::lower(Str::random(4)) : null,
                'status' => 'draft',
                'body' => '',
                'language' => (string) ($payload['language'] ?? 'vi'),
                'parent_id' => $classification['parent_id'],
            ]);
            ArticleContentClassification::persist($article, $classification);

            $this->writeArticleMetas($article, $payload, $keyword, $userId);
            $this->stampProjectCanaryMeta($project, (int) $article->id, $userId);

            $task->article_id = (int) $article->id;
            $task->connected_at = now();
            $task->save();

            $connectedIds = [];
            foreach ($resolvedMedia as $row) {
                /** @var SeoMedia $media */
                $media = $row['media'];
                $this->album->appendProductAlbumLocal($article, (int) $row['id'], (string) $row['url']);
                $this->album->linkSeoMediaToArticle($media, $article);
                $media->forceFill([
                    'prompt_variables' => array_merge(
                        is_array($media->prompt_variables) ? $media->prompt_variables : [],
                        [
                            ProductGalleryArtifactRole::KEY => ProductGalleryArtifactRole::ORIGINAL,
                            'is_canary_original' => true,
                        ],
                    ),
                ])->save();
                $connectedIds[] = (int) $row['id'];
            }

            $article->unsetRelation('articleMetas');

            $editorUrl = '';
            $projectUrl = '';
            $canaryPageUrl = '';
            try {
                $editorUrl = ArticleResource::getUrl('edit', ['record' => $article->id]);
            } catch (\Throwable) {
                $editorUrl = '/seo/articles/'.$article->id.'/edit';
            }
            try {
                $projectUrl = \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getUrl('edit', ['record' => $project->id]);
            } catch (\Throwable) {
                $projectUrl = '/seo/seo-projects/'.$project->id.'/edit';
            }
            try {
                $canaryPageUrl = \Omnichannel\Addons\Commerce\Filament\Pages\ProductGalleryCanaryPage::getUrl([
                    'articleId' => (int) $article->id,
                ]);
            } catch (\Throwable) {
                $canaryPageUrl = '/seo/product-gallery-canary?articleId='.(int) $article->id;
            }

            return [
                'project_id' => (int) $project->id,
                'task_id' => (int) $task->id,
                'article_id' => (int) $article->id,
                'original_media_ids' => $connectedIds,
                'editor_url' => $editorUrl,
                'project_url' => $projectUrl,
                'canary_page_url' => $canaryPageUrl,
            ];
        });
    }

    /**
     * Connect additional library media to an existing product album.
     *
     * @param  list<int>  $mediaIds
     * @return list<int>
     */
    public function connectOriginalMedia(SeoArticle $article, array $mediaIds): array
    {
        $connected = [];
        foreach ($mediaIds as $mediaId) {
            $mediaId = (int) $mediaId;
            if ($mediaId <= 0) {
                continue;
            }
            $media = SeoMedia::query()->find($mediaId);
            if (! $media instanceof SeoMedia) {
                continue;
            }
            $url = trim((string) ($media->url ?? ''));
            if ($url === '') {
                continue;
            }
            $this->album->appendProductAlbumLocal($article, $mediaId, $url);
            $this->album->linkSeoMediaToArticle($media, $article);
            $connected[] = $mediaId;
        }

        return $connected;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeArticleMetas(SeoArticle $article, array $payload, string $keyword, int $userId): void
    {
        $features = $payload['distinctive_features'] ?? [];
        $negatives = $payload['negative_constraints'] ?? [];
        if (! is_array($features)) {
            $features = array_filter([trim((string) $features)]);
        }
        if (! is_array($negatives)) {
            $negatives = array_filter([trim((string) $negatives)]);
        }

        $attrs = [
            'brand' => (string) ($payload['brand'] ?? ''),
            'category' => (string) ($payload['category'] ?? ''),
            'primary_color' => (string) ($payload['primary_color'] ?? ''),
            'secondary_color' => (string) ($payload['secondary_color'] ?? ''),
            'material' => (string) ($payload['material'] ?? ''),
            'shape' => (string) ($payload['shape'] ?? ''),
            'distinctive_features' => array_values(array_map('strval', $features)),
            'negative_constraints' => array_values(array_map('strval', $negatives)),
        ];

        $pairs = [
            'seo_focus_keyword' => $keyword,
            'loai_san_pham' => (string) ($payload['loai_san_pham'] ?? ''),
            'gallery_description' => (string) ($payload['description'] ?? ''),
            'product_brand' => $attrs['brand'],
            'product_category' => $attrs['category'],
            'primary_color' => $attrs['primary_color'],
            'secondary_color' => $attrs['secondary_color'],
            'material' => $attrs['material'],
            'product_shape' => $attrs['shape'],
            'product_identity' => implode('; ', $attrs['distinctive_features']),
            'product_attributes' => json_encode($attrs, JSON_UNESCAPED_UNICODE) ?: '{}',
            'distinctive_features' => json_encode($attrs['distinctive_features'], JSON_UNESCAPED_UNICODE) ?: '[]',
            'negative_constraints' => json_encode($attrs['negative_constraints'], JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_canary' => '1',
            'canary_type' => self::CANARY_TYPE,
            'canary_created_by' => (string) $userId,
            'canary_created_at' => now()->toIso8601String(),
        ];

        foreach ($pairs as $key => $value) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $value],
            );
        }
    }

    private function stampProjectCanaryMeta(SeoProject $project, int $articleId, int $userId): void
    {
        $project->description = trim((string) ($project->description ?? ''))."\n"
            .'[canary] type=product_gallery article_id='.$articleId
            .' by='.$userId.' at='.now()->toIso8601String();
        $project->save();
    }
}
