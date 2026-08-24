<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryActionResult;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\Concerns\InteractsWithTaskWorkflow;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplicationService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trang «Lịch sử AI» — xem / preview / apply / xóa typed artifact của article.
 * Logic nghiệp vụ nằm ở {@see ArticleAiHistoryApplicationService}; Livewire chỉ điều phối.
 */
final class ViewArticlePrompts extends Page
{
    use InteractsWithTaskWorkflow;

    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.view-article-prompts';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record;

    public ?SeoArticle $articleRecord = null;

    public string $activeTab = 'workflow';

    public string $filterType = 'all';

    public string $filterStatus = 'all';

    /** @var list<string> */
    public array $selectedRefs = [];

    public ?string $previewRef = null;

    /** @var array<string, mixed>|null */
    public ?array $previewPayload = null;

    public bool $previewLoading = false;

    /** Artifact đang chờ xác nhận dirty / applied-delete */
    public ?string $pendingConfirmRef = null;

    public ?string $pendingConfirmAction = null;

    public function mount(int|string $record): void
    {
        self::authorizeResourceAccess();
        abort_unless(SeoAccessControl::canAccessContentFeatures(), 403);

        $this->record = (int) $record;
        $this->articleRecord = ArticleResource::getRecordRouteBindingEloquentQuery()
            ->findOrFail($this->record);
    }

    public function getTitle(): string|Htmlable
    {
        $title = trim((string) ($this->articleRecord?->title ?? 'Bài viết'));

        return __('seo-content-ai::filament.article_ai_history.page_title').' — '.$title;
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return $this->activeTab === 'workflow' ? MaxWidth::Full : MaxWidth::SevenExtraLarge;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExecutionRuns(): array
    {
        if (! $this->articleRecord instanceof SeoArticle) {
            return [];
        }

        return app(ArticleAiHistoryApplicationService::class)->executionHistory(
            $this->articleRecord,
            $this->accessibleProjectIds(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAiCallGroups(): array
    {
        if (! $this->articleRecord instanceof SeoArticle) {
            return [];
        }

        return app(ArticleAiHistoryApplicationService::class)->listAiCalls(
            $this->articleRecord,
            $this->accessibleProjectIds(),
            [
                'type' => $this->filterType,
                'status' => $this->filterStatus,
                'include_deleted' => $this->filterStatus === 'deleted',
            ],
        );
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['workflow', 'ai_calls'], true) ? $tab : 'workflow';
    }

    /**
     * @return list<int>
     */
    private function accessibleProjectIds(): array
    {
        return SeoProjectResource::getEloquentQuery()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRunGroups(): array
    {
        if (! $this->articleRecord instanceof SeoArticle) {
            return [];
        }

        return app(ArticleAiHistoryApplicationService::class)->list(
            $this->articleRecord,
            $this->accessibleProjectIds(),
            [
                'type' => $this->filterType,
                'status' => $this->filterStatus,
                'include_deleted' => $this->filterStatus === 'deleted',
            ],
        );
    }

    public function getArticleId(): int
    {
        return (int) ($this->articleRecord?->getKey() ?? 0);
    }

    public function getArticleEditUrl(): ?string
    {
        $articleId = $this->getArticleId();

        return $articleId > 0
            ? ArticleResource::panelUrl('edit', ['record' => $articleId])
            : null;
    }

    public function clearFilters(): void
    {
        $this->filterType = 'all';
        $this->filterStatus = 'all';
        $this->selectedRefs = [];
    }

    public function toggleSelect(string $artifactRef): void
    {
        $ref = trim($artifactRef);
        if ($ref === '') {
            return;
        }

        if (in_array($ref, $this->selectedRefs, true)) {
            $this->selectedRefs = array_values(array_filter(
                $this->selectedRefs,
                static fn (string $item): bool => $item !== $ref,
            ));

            return;
        }

        $this->selectedRefs[] = $ref;
    }

    public function clearSelection(): void
    {
        $this->selectedRefs = [];
    }

    public function loadPreview(string $artifactRef): void
    {
        if (! $this->articleRecord instanceof SeoArticle) {
            return;
        }

        $this->previewLoading = true;
        $this->previewRef = trim($artifactRef);
        $this->previewPayload = null;

        $result = app(ArticleAiHistoryApplicationService::class)->preview(
            $this->articleRecord,
            $this->previewRef,
            $this->accessibleProjectIds(),
        );

        $this->previewPayload = [
            'success' => $result->success,
            'code' => $result->code,
            'message' => $result->message,
            'metadata' => $result->metadata,
        ];
        $this->previewLoading = false;
    }

    /**
     * @return array{success: bool, title?: string, prompt?: string, output?: string, meta?: string, message?: string, prompt_result_id?: int, artifact_ref?: string}
     */
    public function loadRawAiCallDetail(string $artifactRef): array
    {
        if (! $this->articleRecord instanceof SeoArticle) {
            return [
                'success' => false,
                'message' => 'Article not found.',
            ];
        }

        return app(ArticleAiHistoryApplicationService::class)->rawAiCallDetail(
            $this->articleRecord,
            trim($artifactRef),
            $this->accessibleProjectIds(),
        );
    }

    public function closePreview(): void
    {
        $this->previewRef = null;
        $this->previewPayload = null;
        $this->previewLoading = false;
    }

    public function applyOutline(string $artifactRef, bool $confirmDirty = false): void
    {
        $this->runApply('outline', $artifactRef, $confirmDirty);
    }

    public function applyContent(string $artifactRef, bool $confirmDirty = false): void
    {
        $this->runApply('content', $artifactRef, $confirmDirty);
    }

    public function confirmPendingAction(): void
    {
        $ref = trim((string) $this->pendingConfirmRef);
        $action = trim((string) $this->pendingConfirmAction);
        $this->pendingConfirmRef = null;
        $this->pendingConfirmAction = null;

        if ($ref === '' || $action === '') {
            return;
        }

        match ($action) {
            'apply_outline' => $this->applyOutline($ref, true),
            'apply_content' => $this->applyContent($ref, true),
            'delete' => $this->deleteArtifact($ref, true),
            'bulk_delete' => $this->bulkDeleteSelected(true),
            default => null,
        };
    }

    public function cancelPendingConfirm(): void
    {
        $this->pendingConfirmRef = null;
        $this->pendingConfirmAction = null;
    }

    private function runApply(string $target, string $artifactRef, bool $confirmDirty): void
    {
        if (! $this->articleRecord instanceof SeoArticle) {
            return;
        }

        abort_unless(SeoAccessControl::canAccessContentFeatures(), 403);

        $userId = (int) (auth()->id() ?? 0);
        $service = app(ArticleAiHistoryApplicationService::class);
        $result = $target === 'outline'
            ? $service->applyOutline($this->articleRecord, $artifactRef, $this->accessibleProjectIds(), $userId, $confirmDirty)
            : $service->applyContent($this->articleRecord, $artifactRef, $this->accessibleProjectIds(), $userId, $confirmDirty);

        if (! $result->success && $result->code === 'requires_dirty_confirm') {
            $this->pendingConfirmRef = $artifactRef;
            $this->pendingConfirmAction = $target === 'outline' ? 'apply_outline' : 'apply_content';
            Notification::make()
                ->title(__('seo-content-ai::filament.article_ai_history.dirty_confirm_title'))
                ->body($result->message.' '.__('seo-content-ai::filament.article_ai_history.dirty_confirm_hint'))
                ->warning()
                ->send();

            return;
        }

        if (! $result->success) {
            $this->notifyResult($result);

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_ai_history.apply_ok'))
            ->body($result->message)
            ->success()
            ->send();

        $editUrl = $this->getArticleEditUrl();
        if ($editUrl !== null) {
            $this->redirect($editUrl.(str_contains($editUrl, '?') ? '&' : '?').'ai_history_applied=1');
        }
    }

    public function deleteArtifact(string $artifactRef, bool $confirmPreviouslyApplied = false): void
    {
        if (! $this->articleRecord instanceof SeoArticle) {
            return;
        }

        abort_unless(SeoAccessControl::canAccessContentFeatures(), 403);

        $result = app(ArticleAiHistoryApplicationService::class)->delete(
            $this->articleRecord,
            $artifactRef,
            $this->accessibleProjectIds(),
            (int) (auth()->id() ?? 0),
            $confirmPreviouslyApplied,
        );

        if (! $result->success && $result->code === 'requires_apply_confirm') {
            $this->pendingConfirmRef = $artifactRef;
            $this->pendingConfirmAction = 'delete';
            Notification::make()
                ->title(__('seo-content-ai::filament.article_ai_history.delete_applied_title'))
                ->body(__('seo-content-ai::filament.article_ai_history.delete_applied_body'))
                ->warning()
                ->send();

            return;
        }

        $this->notifyResult($result);
        $this->selectedRefs = array_values(array_filter(
            $this->selectedRefs,
            static fn (string $item): bool => $item !== $artifactRef,
        ));
    }

    public function bulkDeleteSelected(bool $confirmPreviouslyApplied = false): void
    {
        if (! $this->articleRecord instanceof SeoArticle || $this->selectedRefs === []) {
            return;
        }

        abort_unless(SeoAccessControl::canAccessContentFeatures(), 403);

        $result = app(ArticleAiHistoryApplicationService::class)->bulkDelete(
            $this->articleRecord,
            $this->selectedRefs,
            $this->accessibleProjectIds(),
            (int) (auth()->id() ?? 0),
            $confirmPreviouslyApplied,
        );

        if (! $result->success && $result->code === 'requires_apply_confirm') {
            $this->pendingConfirmRef = 'bulk';
            $this->pendingConfirmAction = 'bulk_delete';
            Notification::make()
                ->title(__('seo-content-ai::filament.article_ai_history.delete_applied_title'))
                ->body(__('seo-content-ai::filament.article_ai_history.delete_applied_body'))
                ->warning()
                ->send();

            return;
        }

        $this->notifyResult($result);
        if ($result->success) {
            $this->selectedRefs = [];
        }
    }

    private function notifyResult(ArticleAiHistoryActionResult $result): void
    {
        $notification = Notification::make()
            ->title($result->success
                ? __('seo-content-ai::filament.article_ai_history.action_ok')
                : __('seo-content-ai::filament.article_ai_history.action_failed'))
            ->body($result->message);

        if ($result->success) {
            $notification->success()->send();

            return;
        }

        $notification->danger()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_article')
                ->label(__('seo-content-ai::filament.article_ai_history.back_to_article'))
                ->icon('heroicon-o-arrow-left')
                ->url(fn (): ?string => $this->getArticleEditUrl()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPromptsForWorkflowCanvas(): array
    {
        return $this->getPromptsForBuilder();
    }

    protected function persistTaskFlow(string $taskName, array $flowData): bool
    {
        return false;
    }
}
