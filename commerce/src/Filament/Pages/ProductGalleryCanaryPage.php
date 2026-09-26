<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Filament\Pages;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Commerce\Models\SeoProductGalleryExecution;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryCleanupService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryFixtureService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryPromptPreviewService;
use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryReadinessService;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryCanaryAccess;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

final class ProductGalleryCanaryPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'product-gallery-canary';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_SYSTEM + 1;

    protected static string $view = 'seo-content-ai::filament.pages.product-gallery-canary';

    public static function getNavigationGroup(): ?string
    {
        return \Omnichannel\Addons\Seo\Support\SeoUserNavigation::systemGroup();
    }

    /** @var array<string, mixed> */
    public array $createData = [];

    public ?int $articleId = null;

    /** @var array<string, mixed>|null */
    public ?array $lastCreate = null;

    /** @var array<string, mixed>|null */
    public ?array $readiness = null;

    /** @var array<string, mixed>|null */
    public ?array $promptPreview = null;

    /** @var list<array<string, mixed>> */
    public array $executionHistory = [];

    public static function canAccess(): bool
    {
        return ProductGalleryCanaryAccess::allowsUi();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ProductGalleryCanaryAccess::allowsUi();
    }

    public function mount(): void
    {
        $fromQuery = (int) request()->query('articleId', 0);
        if ($fromQuery > 0) {
            $this->articleId = $fromQuery;
        }

        $siteId = SeoAccessControl::globalSiteId() ?? 0;
        $this->createData = [
            'site_id' => $siteId > 0 ? $siteId : null,
            'media_ids' => '',
            'title' => ProductGalleryCanaryFixtureService::DEFAULT_TITLE,
        ];
        $this->form->fill($this->createData);

        if ($this->articleId) {
            $this->refreshReadiness();
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('commerce::filament.product_gallery_canary.section_title'))
                    ->description(__('commerce::filament.product_gallery_canary.section_description'))
                    ->schema([
                        Forms\Components\Select::make('site_id')
                            ->label(__('commerce::filament.product_gallery_canary.site_label'))
                            ->options(fn (): array => SeoAccessControl::accessibleSitesQuery()
                                ->orderBy('domain')
                                ->pluck('domain', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('title')
                            ->label(__('commerce::filament.product_gallery_canary.title_label'))
                            ->required()
                            ->maxLength(200),
                        Forms\Components\TextInput::make('media_ids')
                            ->label(__('commerce::filament.product_gallery_canary.media_ids_label'))
                            ->helperText(__('commerce::filament.product_gallery_canary.media_ids_hint'))
                            ->required()
                            ->placeholder('101,102,103'),
                        Forms\Components\Placeholder::make('requirements')
                            ->label(__('commerce::filament.product_gallery_canary.requirements_label'))
                            ->content(function (): HtmlString {
                                $req = ProductGalleryCanaryFixtureService::inputRequirements();
                                $html = '<div class="text-sm space-y-2"><p><strong>'
                                    .e(__('commerce::filament.product_gallery_canary.required_heading'))
                                    .'</strong></p><ul class="list-disc pl-5">';
                                foreach ($req['required'] as $k => $v) {
                                    $html .= '<li><code>'.e($k).'</code> — '.e($v).'</li>';
                                }
                                $html .= '</ul><p><strong>'
                                    .e(__('commerce::filament.product_gallery_canary.optional_heading'))
                                    .'</strong></p><ul class="list-disc pl-5">';
                                foreach ($req['optional'] as $k => $v) {
                                    $html .= '<li><code>'.e($k).'</code> — '.e($v).'</li>';
                                }
                                $html .= '</ul></div>';

                                return new HtmlString($html);
                            }),
                    ]),
            ])
            ->statePath('createData');
    }

    public function createFixture(
        ProductGalleryCanaryFixtureService $fixture,
    ): void {
        $state = $this->form->getState();
        $mediaIds = array_values(array_filter(array_map(
            static fn (string $part): int => (int) trim($part),
            explode(',', (string) ($state['media_ids'] ?? '')),
        ), static fn (int $id): bool => $id > 0));

        try {
            $result = $fixture->create(
                siteId: (int) ($state['site_id'] ?? 0),
                mediaIds: $mediaIds,
                userId: (int) auth()->id(),
                overrides: [
                    'title' => (string) ($state['title'] ?? ProductGalleryCanaryFixtureService::DEFAULT_TITLE),
                ],
            );
        } catch (\Throwable $exception) {
            Notification::make()->danger()->title(__('commerce::filament.product_gallery_canary.create_failed_title'))->body($exception->getMessage())->send();

            return;
        }

        $this->lastCreate = $result;
        $this->articleId = (int) $result['article_id'];
        $this->refreshReadiness();

        Notification::make()
            ->success()
            ->title(__('commerce::filament.product_gallery_canary.fixture_ready_title'))
            ->body(__('commerce::filament.product_gallery_canary.fixture_ready_body', ['id' => $result['article_id']]))
            ->actions([
                \Filament\Notifications\Actions\Action::make('edit')
                    ->label(__('commerce::filament.product_gallery_canary.open_editor'))
                    ->url($result['editor_url']),
            ])
            ->send();
    }

    public function refreshReadiness(): void
    {
        $readiness = app(ProductGalleryCanaryReadinessService::class);
        $article = $this->resolveArticle();
        if (! $article instanceof SeoArticle) {
            $this->readiness = null;
            $this->executionHistory = [];

            return;
        }

        $this->readiness = $readiness->check($article);
        $this->executionHistory = SeoProductGalleryExecution::query()
            ->where('article_id', (int) $article->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(static fn (SeoProductGalleryExecution $row): array => [
                'id' => (int) $row->id,
                'execution_id' => (string) ($row->execution_id ?? ''),
                'mode' => (string) ($row->generation_mode ?? ''),
                'status' => (string) ($row->status ?? ''),
                'failure_reason' => (string) ($row->failure_reason ?? ''),
                'started_at' => optional($row->started_at)?->toDateTimeString(),
                'completed_at' => optional($row->completed_at)?->toDateTimeString(),
                'selection' => is_array($row->selection_snapshot) ? $row->selection_snapshot : [],
            ])
            ->all();
    }

    /**
     * @return array<int, \Filament\Forms\Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form($this->makeForm()),
        ];
    }

    public function loadPromptPreview(ProductGalleryCanaryPromptPreviewService $preview): void
    {
        $article = $this->resolveArticle();
        if (! $article instanceof SeoArticle) {
            Notification::make()->warning()->title(__('commerce::filament.product_gallery_canary.missing_article_title'))->send();

            return;
        }

        $this->promptPreview = $preview->preview($article, 'gemini', 'gemini-2.5-flash-image', 3);
    }

    public function discardGenerated(ProductGalleryCanaryCleanupService $cleanup): void
    {
        $article = $this->resolveArticle();
        if (! $article instanceof SeoArticle) {
            return;
        }

        try {
            $result = $cleanup->discardGenerated($article);
        } catch (\Throwable $exception) {
            Notification::make()->danger()->title(__('commerce::filament.product_gallery_canary.cleanup_failed_title'))->body($exception->getMessage())->send();

            return;
        }

        $this->refreshReadiness();
        Notification::make()
            ->success()
            ->title(__('commerce::filament.product_gallery_canary.cleanup_done_title'))
            ->body(__('commerce::filament.product_gallery_canary.cleanup_done_body', [
                'discarded' => count($result['discarded_media_ids']),
                'originals' => count($result['original_media_ids']),
            ]))
            ->send();
    }

    public static function getNavigationLabel(): string
    {
        return __('commerce::filament.product_gallery_canary.nav_label');
    }

    public function getTitle(): string
    {
        return __('commerce::filament.product_gallery_canary.title');
    }

    public function editorUrl(): ?string
    {
        if (! $this->articleId) {
            return null;
        }

        return ArticleResource::getUrl('edit', ['record' => $this->articleId]);
    }

    private function resolveArticle(): ?SeoArticle
    {
        if (! $this->articleId) {
            return null;
        }

        $article = SeoArticle::query()->find($this->articleId);

        return $article instanceof SeoArticle ? $article : null;
    }
}
