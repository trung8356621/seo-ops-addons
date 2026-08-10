<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages;

use Omnichannel\Addons\Seo\Exceptions\AiModelsNotReadyException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Media\Filament\Pages\MediaImageEditor;
use Omnichannel\Addons\Media\Filament\Pages\MediaLibrary;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptPart;
use Omnichannel\Addons\AiPrompt\Services\AiModelsReadinessService;
use Omnichannel\Addons\AiPrompt\Services\PromptLoaiSanPhamOptionsService;
use Omnichannel\Addons\AiPrompt\Services\PromptPostProcessingApplyService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use Omnichannel\Addons\Media\Services\SeoMediaImageEditorResolverService;
use Omnichannel\Addons\Media\Services\SeoMediaLibraryImageActionService;
use Omnichannel\Addons\WordPress\Services\WordPressCommentReviewService;
use Omnichannel\Addons\AiPrompt\Support\PromptLoaiSanPhamVariable;
use Omnichannel\Addons\AiPrompt\Support\PromptMediaPersistContext;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use Omnichannel\Addons\AiPrompt\Support\PromptSiteContextVariable;
use Omnichannel\Addons\AiPrompt\Support\PromptTokenUsage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\Utf8Sanitizer;
use App\Models\Site;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;

class TestPrompt extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = PromptResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.prompt-resource.pages.test-prompt';

    protected static ?string $title = 'Test prompt';

    /** @var array<string, string> */
    public array $variableValues = [];

    /** Prompt raw (chưa thay biến) — cột trái, sửa tay trước khi gọi AI. */
    public string $editablePrompt = '';

    public ?string $compiledPreview = null;

    public ?string $outputText = null;

    public ?string $errorMessage = null;

    public ?string $errorTechnicalDetails = null;

    public ?string $errorClassification = null;

    public bool $errorRetryable = false;

    /** Nhãn token của lần chạy đang xem, VD: "12.450 token". */
    public ?string $tokenUsageLabel = null;

    /** Model API thực tế (slug) của lần chạy đang xem. */
    public ?string $lastRawModelUsed = null;

    public bool $isRunning = false;

    public ?int $selectedResultId = null;

    public ?int $publishArticleId = null;

    public bool $isPublishingTest = false;

    /** Kết quả bước trước trong chuỗi test (gán vào {{PARENT_RESULT}} cho prompt con). */
    public ?string $chainLastOutput = null;

    public bool $chainParentCompleted = false;

    public int $chainSubTasksCompleted = 0;

    protected bool $applyPostProcessingOnNextSelect = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        if (! $this->ensureAiModelsReadyOrRedirect()) {
            return;
        }

        $this->syncVariableValueKeys();
        $this->applySiteContextDefaults(true);
        $this->refreshRawPrompt();
        $this->refreshCompiledPreview();

        $latest = $this->promptResults->first();
        if ($latest !== null) {
            $this->selectResult((int) $latest->id);
        }
    }

    #[Computed]
    public function promptUsesInput(): bool
    {
        return PromptResource::promptUsesInputVariable($this->getPrompt());
    }

    #[Computed]
    public function promptUsesLoaiSanPham(): bool
    {
        return PromptLoaiSanPhamVariable::usesInPrompt($this->getPrompt());
    }

    #[Computed]
    public function hasDependentSubTasks(): bool
    {
        return app(PromptRunnerService::class)->hasDependentSubTasks($this->getPrompt());
    }

    /**
     * @return list<array{index: int, name: string}>
     */
    #[Computed]
    public function dependentSubTaskSteps(): array
    {
        return app(PromptRunnerService::class)
            ->getDependentSubTaskParts($this->getPrompt())
            ->map(static fn (SeoPromptPart $part, int $index): array => [
                'index' => $index,
                'name' => filled($part->name) ? (string) $part->name : ('Prompt con '.($index + 1)),
            ])
            ->values()
            ->all();
    }

    public function hasMoreSubTasksToRun(): bool
    {
        if (! $this->usesStepByStepChain()) {
            return false;
        }

        return $this->chainParentCompleted
            && $this->chainSubTasksCompleted < count($this->dependentSubTaskSteps);
    }

    public function usesStepByStepChain(): bool
    {
        return $this->hasDependentSubTasks && ! $this->isImageToolPrompt();
    }

    public function nextSubTaskButtonLabel(): string
    {
        $steps = $this->dependentSubTaskSteps;
        $idx = $this->chainSubTasksCompleted;

        return 'Run sub-prompt: '.($steps[$idx]['name'] ?? ('Step '.($idx + 1)));
    }

    /**
     * @return array<int, array{name: string, label: string, description: ?string}>
     */
    #[Computed]
    public function variableDefinitions(): array
    {
        return PromptResource::variableDefinitionsForPrompt($this->getPrompt());
    }

    /**
     * @return Collection<int, PromptResult>
     */
    #[Computed]
    public function promptResults(): Collection
    {
        return PromptResult::query()
            ->where('prompt_id', $this->getPrompt()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Bài viết có wp_post_id để thử đăng comment/review lên WordPress.
     *
     * @return Collection<int, SeoArticle>
     */
    #[Computed]
    public function articlesForCommentPublish(): Collection
    {
        return SeoArticle::query()
            ->hasWpPostId()
            ->with(['site', 'wordpressLink'])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    public function publishTest(string $mode, PromptTestPublishService $skeletonPublisher, WordPressCommentReviewService $reviewPublisher): void
    {
        if (! filled($this->outputText)) {
            Notification::make()
                ->title('No AI result yet')
                ->body('Run prompt test before publishing.')
                ->warning()
                ->send();

            return;
        }

        $article = $this->resolvePublishTargetArticle();
        if ($article === null) {
            return;
        }

        $variables = $this->normalizedVariableValues();

        $this->isPublishingTest = true;

        try {
            $result = match ($mode) {
                'skeleton' => $skeletonPublisher->publishSkeleton($article, (string) $this->outputText, $variables),
                'article' => $skeletonPublisher->publishArticle($article, (string) $this->outputText, $variables),
                'reviews' => $reviewPublisher->publishFromAiOutput($article, (string) $this->outputText),
                default => ['success' => false, 'message' => 'Invalid action.'],
            };

            $notification = Notification::make()
                ->title($result['success'] ? 'Success' : 'Failed')
                ->body((string) ($result['message'] ?? ''));

            $result['success'] ? $notification->success() : $notification->danger();
            $notification->send();
        } finally {
            $this->isPublishingTest = false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function normalizedVariableValues(): array
    {
        $values = $this->variableValues;
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = is_string($value) ? $value : (string) $value;
        }

        if ($this->promptUsesLoaiSanPham) {
            $normalized = PromptLoaiSanPhamVariable::mergeIntoVariables($normalized);
            $normalized = PromptLoaiSanPhamVariable::withAliases($normalized);
        }

        $normalized = PromptSiteContextVariable::mergeInto($normalized);

        return Utf8Sanitizer::variables($normalized);
    }

    public function copyMergedPreviewToEditable(): void
    {
        if (blank($this->compiledPreview)) {
            $this->refreshCompiledPreview();
        }

        $this->editablePrompt = (string) ($this->compiledPreview ?? '');

        Notification::make()
            ->title('Đã đưa bản ghép sang cột Prompt')
            ->body('Cột Prompt không còn raw — dùng khi muốn chạy thử nhanh không thay biến tay.')
            ->success()
            ->send();
    }

    private function resolvePublishTargetArticle(): ?SeoArticle
    {
        if ($this->publishArticleId === null || $this->publishArticleId <= 0) {
            Notification::make()
                ->title('Choose target article')
                ->body('Select an article/product already synced from WordPress.')
                ->warning()
                ->send();

            return null;
        }

        $query = SeoArticle::query()->with('site')->whereKey($this->publishArticleId);

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            SeoAccessControl::applyAccessibleSiteScope($query);
        }

        $article = $query->first();

        if ($article === null) {
            Notification::make()
                ->title('Article not found')
                ->danger()
                ->send();
        }

        return $article;
    }

    public function selectResult(int $resultId): void
    {
        $result = PromptResult::query()
            ->where('prompt_id', $this->getPrompt()->id)
            ->findOrFail($resultId);

        $this->selectedResultId = $resultId;
        $this->applyResultToView($result);
        $this->applyChainStateFromResult($result);
        $this->syncTestResultSeoMediaContext();
        if ($this->applyPostProcessingOnNextSelect) {
            $this->applyPostProcessingOnNextSelect = false;
            $this->applyPostProcessingToTestResult();
        }
        unset($this->promptResults);
    }

    public function deleteResult(int $resultId): void
    {
        $result = PromptResult::query()
            ->where('prompt_id', $this->getPrompt()->id)
            ->findOrFail($resultId);

        $wasSelected = $this->selectedResultId === $resultId;
        $result->delete();

        unset($this->promptResults);

        if ($wasSelected) {
            $this->clearResultView();

            $latest = $this->promptResults->first();
            if ($latest !== null) {
                $this->selectResult((int) $latest->id);
            }
        }

        Notification::make()
            ->title('Test run deleted')
            ->success()
            ->send();
    }

    protected function clearResultView(): void
    {
        $this->selectedResultId = null;
        $this->refreshCompiledPreview();
        $this->outputText = null;
        $this->clearImageErrorState();
        $this->tokenUsageLabel = null;
        $this->lastRawModelUsed = null;
        $this->resetChainProgress();
    }

    private function clearImageErrorState(): void
    {
        $this->errorMessage = null;
        $this->errorTechnicalDetails = null;
        $this->errorClassification = null;
        $this->errorRetryable = false;
    }

    /**
     * @param  array<string, mixed>  $presented
     */
    private function applyPresentedImageError(array $presented): void
    {
        $this->errorMessage = (string) ($presented['user_message'] ?? '');
        $this->errorTechnicalDetails = (string) ($presented['technical_details'] ?? '');
        $this->errorClassification = isset($presented['classification']) ? (string) $presented['classification'] : null;
        $this->errorRetryable = (bool) ($presented['retryable'] ?? false);
    }

    protected function resetChainProgress(): void
    {
        $this->chainLastOutput = null;
        $this->chainParentCompleted = false;
        $this->chainSubTasksCompleted = 0;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([])
            ->statePath('variableValues');
    }

    protected function getForms(): array
    {
        return [
            'form',
        ];
    }

    public function getTitle(): string|Htmlable
    {
        $name = (string) ($this->getPrompt()->name ?: $this->getPrompt()->title);

        return 'Test: '.$name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit')
                ->label('Edit prompt')
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => PromptResource::getUrl('edit', ['record' => $this->getRecord()])),
            Actions\Action::make('back')
                ->label('List')
                ->icon('heroicon-o-arrow-left')
                ->url(PromptResource::getUrl('index')),
            Actions\Action::make('refresh_preview')
                ->label('Refresh preview')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $this->refreshRawPrompt();
                    $this->refreshCompiledPreview();
                }),
        ];
    }

    protected function ensureAiModelsReadyOrRedirect(): bool
    {
        $readiness = app(AiModelsReadinessService::class);

        if ($readiness->isPromptReady($this->getPrompt())) {
            return true;
        }

        Notification::make()
            ->title('AI model sync required')
            ->body($readiness->blockMessage())
            ->warning()
            ->send();

        $this->redirect($readiness->overviewUrl(), navigate: true);

        return false;
    }

    protected function redirectIfAiModelsNotReady(\Throwable $exception): bool
    {
        if (! $exception instanceof AiModelsNotReadyException) {
            return false;
        }

        Notification::make()
            ->title('AI model sync required')
            ->body($exception->getMessage())
            ->warning()
            ->send();

        $this->redirect($exception->overviewUrl(), navigate: true);

        return true;
    }

    public function runTest(PromptRunnerService $runner): void
    {
        if (! $this->ensureAiModelsReadyOrRedirect()) {
            return;
        }

        $this->isRunning = true;
        $this->clearImageErrorState();
        $this->outputText = null;
        $this->resetChainProgress();

        $normalized = $this->normalizedVariableValues();
        $prompt = $this->getPrompt();

        try {
            $hookBinding = \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBinding::tryFromPrompt($prompt);
        } catch (\InvalidArgumentException $exception) {
            $this->isRunning = false;
            $this->errorMessage = $exception->getMessage();
            Notification::make()
                ->title(__('seo-content-ai::prompt_hooks.execution_failed_title'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($hookBinding !== null && ! $this->isImageToolPrompt()) {
            try {
                $persistContext = $this->resolvePromptMediaPersistContext($normalized);
                $hookResult = app(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor::class)
                    ->execute(
                        $prompt,
                        $normalized,
                        [
                            'site_id' => $persistContext['site_id'] ?? null,
                            'article_id' => $persistContext['article_id'] ?? null,
                            'locale' => $normalized['language'] ?? $normalized['locale'] ?? null,
                        ],
                    );
                $this->outputText = (string) ($hookResult['output'] ?? '');
                $this->isRunning = false;
                Notification::make()
                    ->title('Test Hook successful')
                    ->body(
                        $hookResult['hook_key'].'@'.$hookResult['hook_version']
                        .' · '.$hookResult['duration_ms'].'ms'
                        .' · correlation_id='.$hookResult['correlation_id']
                        .($hookResult['model'] ? ' · model='.$hookResult['model'] : '')
                    )
                    ->success()
                    ->send();

                return;
            } catch (\Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure $exception) {
                $this->isRunning = false;
                $mapped = app(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookUiFailureMapper::class)
                    ->map($exception, $hookBinding->hookKey, $hookBinding->hookVersion, null);
                $this->errorMessage = $mapped['body'];
                Notification::make()
                    ->title($mapped['title'])
                    ->body($mapped['body'])
                    ->danger()
                    ->send();

                return;
            }
        }

        if (trim($this->editablePrompt) === '') {
            $this->isRunning = false;

            Notification::make()
                ->title('Prompt trống')
                ->body('Nhập nội dung prompt ở cột trái trước khi chạy thử.')
                ->warning()
                ->send();

            return;
        }

        try {
            $runFullChain = ! $this->hasDependentSubTasks;
            $persistContext = $this->resolvePromptMediaPersistContext($normalized);
            $compiled = trim($this->editablePrompt);
            $useLegacyImageChain = $this->isImageToolPrompt() && $this->usesStepByStepChain();

            $result = PromptMediaPersistContext::using(
                $persistContext['site_id'],
                $persistContext['article_id'],
                $persistContext['prompt_id'],
                fn () => $useLegacyImageChain
                    ? $runner->run($this->getPrompt(), $normalized, null, false, $runFullChain)
                    : ($this->usesStepByStepChain()
                        ? $runner->runWithCompiledPrompt($this->getPrompt(), $compiled, $normalized, false, true)
                        : $runner->runWithCompiledPrompt($this->getPrompt(), $compiled, $normalized, false)),
            );

            if ($this->usesStepByStepChain()) {
                $this->chainParentCompleted = true;
                $this->chainLastOutput = (string) ($result->output_text ?? '');
                $this->chainSubTasksCompleted = 0;
            }

            Notification::make()
                ->title($this->usesStepByStepChain() && $this->hasMoreSubTasksToRun()
                    ? 'Parent prompt completed'
                    : 'Test successful')
                ->body($this->usesStepByStepChain() && $this->hasMoreSubTasksToRun()
                    ? 'Click button below to run sub-prompts step by step.'
                    : null)
                ->success()
                ->send();

            unset($this->promptResults);
            $this->applyPostProcessingOnNextSelect = true;
            $this->selectResult((int) $result->id);
        } catch (PromptRunException $exception) {
            if ($this->redirectIfAiModelsNotReady($exception)) {
                return;
            }

            $this->errorMessage = $exception->userMessage();
            $this->errorTechnicalDetails = $exception->technicalDetails();
            $this->errorClassification = $exception->classification();
            $this->errorRetryable = $exception->isRetryable()
                || \Omnichannel\Addons\Media\Support\ImagenProviderErrorClassifier::isRetryableClassification(
                    (string) ($exception->classification() ?? ''),
                );

            try {
                $this->compiledPreview = $runner->compilePrompt($this->getPrompt(), $normalized);
            } catch (\Throwable) {
                // Preview optional when compile itself fails.
            }

            Notification::make()
                ->title('Test failed')
                ->body($exception->userMessage())
                ->danger()
                ->send();

            unset($this->promptResults);
            $failed = PromptResult::query()
                ->where('prompt_id', $this->getPrompt()->id)
                ->orderByDesc('id')
                ->first();

            if ($failed !== null) {
                $audit = $exception->audit();
                if ($audit !== []) {
                    $snapshot = is_array($failed->input_snapshot) ? $failed->input_snapshot : [];
                    $snapshot['imagen_provider_audit'] = $audit;
                    $failed->update([
                        'input_snapshot' => $snapshot,
                        'error_message' => mb_substr($exception->technicalDetails(), 0, 2000),
                    ]);
                }
                $this->applyPostProcessingOnNextSelect = false;
                $this->selectResult((int) $failed->id);
            }
        } finally {
            $this->isRunning = false;
        }
    }

    protected function applyPostProcessingToTestResult(): void
    {
        if (! $this->isImageToolPrompt() || $this->currentMediaOutputUrl() === null) {
            return;
        }

        $config = PromptPostProcessing::fromPrompt($this->getPrompt());
        if (! PromptPostProcessing::isActive($config)) {
            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media === null) {
            return;
        }

        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        if (PromptPostProcessing::fromVariablesSnapshot($variables) === null) {
            $variables = PromptPostProcessing::attachSnapshotToVariables($variables, $config);
            $media->update(['prompt_variables' => $variables]);
            $media = $media->fresh() ?? $media;
        }

        try {
            $result = app(PromptPostProcessingApplyService::class)
                ->applyIfConfigured($media, $this->getPrompt());
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Hậu kỳ ảnh thất bại')
                ->body(mb_substr($exception->getMessage(), 0, 500))
                ->warning()
                ->send();

            return;
        }

        if (! $result->applied) {
            if ($result->message !== null && $result->message !== '') {
                Notification::make()
                    ->title('Hậu kỳ ảnh')
                    ->body($result->message)
                    ->warning()
                    ->send();
            }

            return;
        }

        $urls = $result->publicUrls();
        if ($urls !== []) {
            $this->outputText = implode("\n", $urls);

            if ($this->selectedResultId !== null) {
                PromptResult::query()
                    ->where('prompt_id', $this->getPrompt()->id)
                    ->whereKey($this->selectedResultId)
                    ->update(['output_text' => $this->outputText]);
            }
        }

        Notification::make()
            ->title('Hậu kỳ ảnh')
            ->body($result->message ?? 'Đã xử lý ảnh sau khi tạo.')
            ->success()
            ->send();
    }

    public function reapplyPostProcessing(): void
    {
        $this->applyPostProcessingToTestResult();
    }

    public function canReapplyPostProcessing(): bool
    {
        if (! $this->isImageToolPrompt() || $this->currentMediaOutputUrl() === null) {
            return false;
        }

        $config = PromptPostProcessing::fromPrompt($this->getPrompt());
        if (! PromptPostProcessing::isActive($config)) {
            return false;
        }

        return $this->testResultSourceSeoMedia() !== null;
    }

    /**
     * @return list<string>
     */
    public function testResultMediaUrls(): array
    {
        $raw = trim((string) ($this->outputText ?? ''));
        if ($raw === '') {
            return [];
        }

        $urls = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '/storage/') || preg_match('#^https?://#i', $line)) {
                $urls[] = $line;
            }
        }

        return $urls;
    }

    public function runNextSubTask(PromptRunnerService $runner): void
    {
        if (! $this->ensureAiModelsReadyOrRedirect()) {
            return;
        }

        if (! $this->usesStepByStepChain()) {
            Notification::make()
                ->title('This prompt does not require sub-prompts')
                ->body('Image prompt renders directly in one run.')
                ->warning()
                ->send();

            return;
        }

        if (! $this->chainParentCompleted || ! $this->hasMoreSubTasksToRun()) {
            Notification::make()
                ->title('Cannot run next step')
                ->body('Run parent prompt first, or no sub-prompts remain.')
                ->warning()
                ->send();

            return;
        }

        if (blank($this->chainLastOutput)) {
            Notification::make()
                ->title('Missing previous step result')
                ->body('Run parent prompt again.')
                ->warning()
                ->send();

            return;
        }

        $this->isRunning = true;
        $this->errorMessage = null;

        $normalized = $this->normalizedVariableValues();
        $normalized['PARENT_RESULT'] = (string) $this->chainLastOutput;
        $subTaskIndex = $this->chainSubTasksCompleted;

        try {
            $persistContext = $this->resolvePromptMediaPersistContext($normalized);
            $result = PromptMediaPersistContext::using(
                $persistContext['site_id'],
                $persistContext['article_id'],
                $persistContext['prompt_id'],
                fn () => $runner->run(
                    $this->getPrompt(),
                    $normalized,
                    null,
                    false,
                    true,
                    $subTaskIndex,
                ),
            );

            $this->chainLastOutput = (string) ($result->output_text ?? '');
            $this->chainSubTasksCompleted++;

            $hasMore = $this->hasMoreSubTasksToRun();

            Notification::make()
                ->title('Completed '.($this->dependentSubTaskSteps[$subTaskIndex]['name'] ?? 'sub-prompt'))
                ->body($hasMore ? 'Click button below to run next sub-prompt.' : 'Prompt chain completed.')
                ->success()
                ->send();

            unset($this->promptResults);
            $this->applyPostProcessingOnNextSelect = true;
            $this->selectResult((int) $result->id);
        } catch (PromptRunException $exception) {
            if ($this->redirectIfAiModelsNotReady($exception)) {
                return;
            }

            $this->errorMessage = $exception->getMessage();

            Notification::make()
                ->title('Sub-prompt failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            unset($this->promptResults);
            $failed = PromptResult::query()
                ->where('prompt_id', $this->getPrompt()->id)
                ->orderByDesc('id')
                ->first();

            if ($failed !== null) {
                $this->applyPostProcessingOnNextSelect = false;
                $this->selectResult((int) $failed->id);
            }
        } finally {
            $this->isRunning = false;
        }
    }

    public function refreshRawPrompt(): void
    {
        $this->getRecord()->refresh();

        try {
            $this->editablePrompt = app(PromptRunnerService::class)->compileRawPrompt(
                $this->getPrompt(),
            );
        } catch (\Throwable) {
            $this->editablePrompt = '';
        }
    }

    public function refreshCompiledPreview(): void
    {
        $this->getRecord()->refresh();

        $normalized = $this->normalizedVariableValues();
        $prompt = $this->getPrompt();

        try {
            $hookKey = trim((string) ($prompt->hook_key ?? ''));
            if ($hookKey !== '') {
                $preview = app(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCompositionPreviewService::class)
                    ->preview(
                        $hookKey,
                        (string) ($prompt->hook_version ?? ''),
                        (string) ($prompt->markdown_content ?? ''),
                        is_array($prompt->hook_settings) ? $prompt->hook_settings : [],
                    );
                // Preview keeps {{placeholders}}; Test execution substitutes $normalized later.
                $this->compiledPreview = $preview['final_prompt'];
            } else {
                $this->compiledPreview = app(PromptRunnerService::class)->compilePrompt(
                    $prompt,
                    $normalized,
                );
            }
        } catch (\Throwable) {
            $this->compiledPreview = null;
        }
    }

    /**
     * @return array{
     *     output_mode: string,
     *     quick_split_enabled: bool,
     *     grid_size: int,
     *     grid: string|null,
     *     expected_children: int,
     *     snapshot_source: string,
     * }|null
     */
    public function imageOutputModeMetaForView(): ?array
    {
        if (! $this->isImageToolPrompt()) {
            return null;
        }

        if ($this->selectedResultId !== null) {
            $result = PromptResult::query()
                ->where('prompt_id', $this->getPrompt()->id)
                ->find($this->selectedResultId);

            if ($result instanceof PromptResult) {
                $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
                $meta = $snapshot['image_output_mode'] ?? null;
                if (is_array($meta) && isset($meta['output_mode'])) {
                    return [
                        'output_mode' => (string) $meta['output_mode'],
                        'quick_split_enabled' => (bool) ($meta['quick_split_enabled'] ?? false),
                        'grid_size' => (int) ($meta['grid_size'] ?? 0),
                        'grid' => isset($meta['grid']) ? (is_string($meta['grid']) ? $meta['grid'] : null) : null,
                        'expected_children' => (int) ($meta['expected_children'] ?? 0),
                        'snapshot_source' => (string) ($meta['snapshot_source'] ?? 'generation_snapshot'),
                    ];
                }

                $compiled = (string) ($snapshot['compiled_prompt'] ?? '');
                if ($compiled !== '') {
                    return $this->inferImageOutputModeFromCompiled($compiled, 'generation_snapshot');
                }
            }
        }

        $config = PromptPostProcessing::fromPrompt($this->getPrompt());

        return app(\Omnichannel\Addons\AiPrompt\Services\ImageOutputModePromptInjector::class)
            ->auditMeta($config, 'live_preview');
    }

    /**
     * @return array{
     *     output_mode: string,
     *     quick_split_enabled: bool,
     *     grid_size: int,
     *     grid: string|null,
     *     expected_children: int,
     *     snapshot_source: string,
     * }
     */
    private function inferImageOutputModeFromCompiled(string $compiled, string $source): array
    {
        $enabled = str_contains($compiled, 'MODE=SQUARE_SPRITE_SHEET');
        $grid = PromptPostProcessing::GRID_SIZE_DEFAULT;
        if (preg_match('/GRID_ROWS=(\d+)/', $compiled, $match)) {
            $grid = PromptPostProcessing::clampGridSize((int) $match[1]);
        }

        $config = PromptPostProcessing::normalize([
            'split_enabled' => $enabled,
            'split_grid_size' => $grid,
        ]);

        return app(\Omnichannel\Addons\AiPrompt\Services\ImageOutputModePromptInjector::class)
            ->auditMeta($config, $source);
    }

    protected function applyChainStateFromResult(PromptResult $result): void
    {
        if (! $this->usesStepByStepChain()) {
            $this->resetChainProgress();

            return;
        }

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        if (! ($snapshot['chain_mode'] ?? false)) {
            $this->resetChainProgress();

            return;
        }

        if ($result->status !== 'completed') {
            return;
        }

        $this->chainParentCompleted = true;
        $this->chainLastOutput = (string) ($result->output_text ?? '');

        $step = (string) ($snapshot['chain_step'] ?? '');
        if ($step === 'task') {
            $this->chainSubTasksCompleted = 0;

            return;
        }

        if ($step === 'sub_task') {
            $this->chainSubTasksCompleted = max(1, (int) ($snapshot['chain_step_index'] ?? 1));
        }
    }

    protected function applyResultToView(PromptResult $result): void
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        $savedVariables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];
        foreach ($savedVariables as $key => $value) {
            $name = (string) $key;
            if (PromptSiteContextVariable::isName($name)) {
                continue;
            }

            $this->variableValues[$name] = is_string($value) ? $value : (string) $value;
        }

        if ($this->promptUsesLoaiSanPham) {
            $this->stripLoaiSanPhamComputedVariableKeys();
        }

        $this->syncVariableValueKeys();
        $this->applySiteContextDefaults(true);

        if (filled($snapshot['compiled_prompt'] ?? null)) {
            $this->compiledPreview = (string) $snapshot['compiled_prompt'];
        } else {
            $this->refreshCompiledPreview();
        }

        $usage = is_array($result->token_usage) ? $result->token_usage : null;
        $this->tokenUsageLabel = PromptTokenUsage::formatLabel($usage);
        $this->lastRawModelUsed = filled($snapshot['raw_model_used'] ?? null)
            ? (string) $snapshot['raw_model_used']
            : null;

        if ($result->status === 'completed') {
            $this->outputText = (string) ($result->output_text ?? '');
            $this->clearImageErrorState();
        } elseif ($result->status === 'failed') {
            $this->outputText = null;
            $raw = (string) ($result->error_message ?? 'Test failed.');
            $presented = \Omnichannel\Addons\Media\Support\ImagenProviderErrorClassifier::present($raw);
            $audit = is_array($snapshot['imagen_provider_audit'] ?? null) ? $snapshot['imagen_provider_audit'] : [];
            if (isset($audit['final_classification']) && is_string($audit['final_classification'])) {
                $presented['classification'] = $audit['final_classification'];
                $presented['retryable'] = \Omnichannel\Addons\Media\Support\ImagenProviderErrorClassifier::isRetryableClassification(
                    $audit['final_classification'],
                );
            }
            $this->applyPresentedImageError($presented);
        } else {
            $this->outputText = null;
            $this->clearImageErrorState();
        }
    }

    public function tokenUsageLabelFor(PromptResult $result): ?string
    {
        $usage = is_array($result->token_usage) ? $result->token_usage : null;

        return PromptTokenUsage::formatLabel($usage);
    }

    public function modelUsedLabelFor(PromptResult $result): ?string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $raw = trim((string) ($snapshot['raw_model_used'] ?? $snapshot['model'] ?? ''));

        return $raw !== '' ? $raw : null;
    }

    public function resultToolBadgeFor(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $tool = strtolower(trim((string) ($snapshot['tools'] ?? 'default')));

        return match ($tool) {
            'image' => 'IMAGE',
            'video' => 'VIDEO',
            default => 'TEXT',
        };
    }

    public function aiResultSectionHeading(): string
    {
        $parts = array_values(array_filter([
            $this->tokenUsageLabel,
            filled($this->lastRawModelUsed) ? 'Model: '.$this->lastRawModelUsed : null,
        ]));

        return $parts !== [] ? 'AI result ('.implode(' · ', $parts).')' : 'AI result';
    }

    public function shouldShowCompiledPreview(): bool
    {
        return true;
    }

    public function currentMediaOutputUrl(): ?string
    {
        $raw = trim((string) ($this->outputText ?? ''));
        if ($raw === '') {
            return null;
        }

        $firstLine = trim(explode("\n", $raw, 2)[0] ?? '');
        $isUrl = str_starts_with($firstLine, '/storage/') || (bool) preg_match('#^https?://#i', $firstLine);

        return $isUrl ? $firstLine : null;
    }

    public function isImageToolPrompt(): bool
    {
        return \Omnichannel\Addons\Media\Support\ImageToolType::fromMixed(
            $this->getPrompt()->tools ?? 'default',
        )->isImagePipeline();
    }

    public function isVideoToolPrompt(): bool
    {
        return trim((string) ($this->getPrompt()->tools ?? 'default')) === 'video';
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{site_id: ?int, article_id: ?int, prompt_id: ?int}
     */
    protected function resolvePromptMediaPersistContext(array $variables): array
    {
        $articleId = null;

        if ($this->publishArticleId !== null && $this->publishArticleId > 0) {
            $articleId = $this->publishArticleId;
        } elseif (filled($variables['article_id'] ?? null)) {
            $articleId = (int) $variables['article_id'];
        }

        $siteId = null;
        if ($articleId !== null && $articleId > 0) {
            $siteId = (int) (SeoArticle::query()->whereKey($articleId)->value('site_id') ?? 0);
        }

        if (($siteId === null || $siteId <= 0) && $this->promptUsesLoaiSanPham) {
            $siteId = (int) ($variables[PromptLoaiSanPhamVariable::SITE_FIELD] ?? 0);
        }

        return [
            'site_id' => $siteId > 0 ? $siteId : null,
            'article_id' => $articleId > 0 ? $articleId : null,
            'prompt_id' => (int) $this->getPrompt()->id,
        ];
    }

    protected function syncTestResultSeoMediaContext(): void
    {
        if (! $this->isImageToolPrompt() && ! $this->isVideoToolPrompt()) {
            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media === null) {
            return;
        }

        $context = $this->resolvePromptMediaPersistContext($this->normalizedVariableValues());
        PromptMediaPersistContext::using(
            $context['site_id'],
            $context['article_id'],
            $context['prompt_id'],
            static function () use ($media): void {
                $updates = PromptMediaPersistContext::fillMissingOnMedia($media);
                if ($updates !== []) {
                    $media->update($updates);
                }
            },
        );
    }

    public function testResultSiteId(): ?int
    {
        $media = $this->testResultSeoMedia();
        if ($media !== null && (int) ($media->site_id ?? 0) > 0) {
            return (int) $media->site_id;
        }

        if ($this->promptUsesLoaiSanPham) {
            $siteId = (int) ($this->normalizedVariableValues()[PromptLoaiSanPhamVariable::SITE_FIELD] ?? 0);
            if ($siteId > 0) {
                return $siteId;
            }
        }

        if ($this->publishArticleId !== null && $this->publishArticleId > 0) {
            $siteId = (int) (SeoArticle::query()->whereKey($this->publishArticleId)->value('site_id') ?? 0);
            if ($siteId > 0) {
                return $siteId;
            }
        }

        return null;
    }

    public function testResultSeoMedia(): ?SeoMedia
    {
        $url = $this->currentMediaOutputUrl();
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
            return null;
        }

        $relative = ltrim(substr($path, strlen('/storage/')), '/');
        if ($relative === '') {
            return null;
        }

        return SeoMedia::query()
            ->where('path', $relative)
            ->orWhere('url', $url)
            ->orWhere('url', 'like', '%'.$relative)
            ->orderByDesc('id')
            ->first();
    }

    public function testResultSourceSeoMedia(): ?SeoMedia
    {
        $media = $this->testResultSeoMedia();
        if ($media === null) {
            return null;
        }

        return app(PromptPostProcessingApplyService::class)->resolveSourceMedia($media);
    }

    /**
     * @return array<string, mixed>
     */
    public function testResultImageRow(): array
    {
        $url = (string) ($this->currentMediaOutputUrl() ?? '');
        $media = $this->testResultSeoMedia();
        $siteId = $this->testResultSiteId();
        $kind = 'local';

        if ($media !== null) {
            $source = (string) $media->source;
            if (str_starts_with($source, 'ai_') && (int) ($media->site_id ?? 0) <= 0 && ($siteId === null || $siteId <= 0)) {
                $kind = 'generated';
            }
        }

        return [
            'url' => $url,
            'seo_media_id' => $media !== null ? (int) $media->id : 0,
            'wp_attachment_id' => $media !== null ? (int) ($media->wp_attachment_id ?? 0) : 0,
            'slug' => $media !== null ? (string) ($media->slug ?? '') : '',
            'kind' => $kind,
            'article_id' => $this->publishArticleId,
        ];
    }

    public function testResultImageSplitterUrl(): ?string
    {
        $media = $this->testResultSourceSeoMedia();
        if ($media === null) {
            return null;
        }

        return MediaImageEditor::urlForMedia((int) $media->id, 'splitter');
    }

    public function testResultCanOpenImageEditor(): bool
    {
        return $this->testResultSeoMedia() !== null
            && ! $this->testResultNeedsSiteForMediaActions();
    }

    public function openResultImageEditor(): void
    {
        $siteId = $this->testResultSiteId();
        if ($siteId === null || $siteId <= 0) {
            Notification::make()
                ->title('Domain not selected')
                ->body('Select a domain or target article before editing image.')
                ->warning()
                ->send();

            return;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            Notification::make()->title('Domain not found')->danger()->send();

            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media === null) {
            Notification::make()
                ->title('Image file not found')
                ->body('Image is not saved on server yet - run prompt again.')
                ->warning()
                ->send();

            return;
        }

        if ((int) ($media->site_id ?? 0) <= 0) {
            $media->update(['site_id' => $siteId]);
            $media->refresh();
        }

        $imageRow = $this->testResultImageRow();
        $imageRow['seo_media_id'] = (int) $media->id;
        $imageRow['kind'] = 'local';

        try {
            $resolved = app(SeoMediaImageEditorResolverService::class)
                ->resolve($site, $imageRow);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Unable to open editor')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->js('window.open('.json_encode($resolved['editor_url']).', "_blank")');
    }

    public function testResultMediaLibraryUrl(): ?string
    {
        $siteId = $this->testResultSiteId();
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        return MediaLibrary::getUrl(['siteId' => $siteId]);
    }

    public function testResultNeedsSiteForMediaActions(): bool
    {
        return $this->testResultSiteId() === null || $this->testResultSiteId() <= 0;
    }

    public function testResultIsGeneratedMedia(): bool
    {
        return ($this->testResultImageRow()['kind'] ?? '') === 'generated';
    }

    public function assignResultToSiteLibrary(): void
    {
        $siteId = $this->testResultSiteId();
        if ($siteId === null || $siteId <= 0) {
            Notification::make()
                ->title('Domain not selected')
                ->body('Select domain in product-category variables or choose a synced target article before assigning image to library.')
                ->warning()
                ->send();

            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media === null) {
            Notification::make()
                ->title('Image file not found')
                ->body('AI image is not saved on server yet - rerun prompt or check /storage/ path.')
                ->warning()
                ->send();

            return;
        }

        if ((int) ($media->site_id ?? 0) === $siteId) {
            Notification::make()
                ->title('Image already belongs to this domain library')
                ->success()
                ->send();

            return;
        }

        $media->update(['site_id' => $siteId]);

        Notification::make()
            ->title('Image assigned to library')
            ->body('You can now apply watermark or open media library.')
            ->success()
            ->send();
    }

    public function applyResultWatermark(): void
    {
        $siteId = $this->testResultSiteId();
        if ($siteId === null || $siteId <= 0) {
            Notification::make()
                ->title('Domain not selected')
                ->body('Select domain or target article before watermarking. For AI-generated images, click "Assign to library" first.')
                ->warning()
                ->send();

            return;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            Notification::make()->title('Domain not found')->danger()->send();

            return;
        }

        $media = $this->testResultSeoMedia();
        if ($media !== null && (int) ($media->site_id ?? 0) <= 0) {
            $media->update(['site_id' => $siteId]);
            $media->refresh();
        }

        $imageRow = $this->testResultImageRow();
        if (($imageRow['kind'] ?? '') === 'generated') {
            Notification::make()
                ->title('AI-generated image has no domain yet')
                ->body('Click "Assign to library" then try watermark again.')
                ->warning()
                ->send();

            return;
        }

        $result = app(SeoMediaLibraryImageActionService::class)->applyWatermark($site, $imageRow);

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title((string) ($result['message'] ?? 'Unable to apply watermark.'))
                ->warning()
                ->send();

            return;
        }

        $newUrl = (string) ($result['url'] ?? $imageRow['url']);
        if ($newUrl !== '') {
            $this->outputText = $newUrl;
        }

        Notification::make()
            ->title((string) ($result['message'] ?? 'Watermark applied.'))
            ->success()
            ->send();
    }

    public function resultSummary(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];

        foreach (['post_title', 'focus_keyword'] as $preferred) {
            if (filled($variables[$preferred] ?? null)) {
                return (string) $variables[$preferred];
            }
        }

        foreach ($variables as $value) {
            if (filled($value)) {
                return mb_strlen((string) $value) > 48
                    ? mb_substr((string) $value, 0, 48).'…'
                    : (string) $value;
            }
        }

        if ($result->status === 'completed' && filled($result->output_text)) {
            $text = trim((string) $result->output_text);

            return mb_strlen($text) > 48 ? mb_substr($text, 0, 48).'…' : $text;
        }

        return '#'.$result->id;
    }

    protected function syncVariableValueKeys(): void
    {
        foreach ($this->variableDefinitions as $definition) {
            $name = (string) $definition['name'];
            if (! array_key_exists($name, $this->variableValues)) {
                $this->variableValues[$name] = '';
            }
        }

        if ($this->promptUsesInput && ! array_key_exists('input', $this->variableValues)) {
            $this->variableValues['input'] = '';
        }

        if ($this->promptUsesLoaiSanPham) {
            $this->stripLoaiSanPhamComputedVariableKeys();

            foreach ([
                PromptLoaiSanPhamVariable::SITE_FIELD => '',
                PromptLoaiSanPhamVariable::CATEGORY_FIELD => '',
                PromptLoaiSanPhamVariable::CUSTOM_FIELD => '',
            ] as $key => $default) {
                if (! array_key_exists($key, $this->variableValues)) {
                    $this->variableValues[$key] = $default;
                }
            }
        }

        if (! array_key_exists(PromptSiteContextVariable::POST_TYPE_FIELD, $this->variableValues)) {
            $this->variableValues[PromptSiteContextVariable::POST_TYPE_FIELD] = 'article';
        }
    }

    protected function applySiteContextDefaults(bool $force = false): void
    {
        $postType = trim((string) ($this->variableValues[PromptSiteContextVariable::POST_TYPE_FIELD] ?? 'article'));
        if ($postType === '') {
            $postType = 'article';
            $this->variableValues[PromptSiteContextVariable::POST_TYPE_FIELD] = $postType;
        }

        $resolved = PromptSiteContextVariable::resolveForGlobalSite($postType, $this->variableValues);

        foreach (PromptSiteContextVariable::names() as $name) {
            if ($force || ! filled($this->variableValues[$name] ?? null)) {
                $this->variableValues[$name] = $resolved[$name] ?? '';
            }
        }
    }

    public function updatedVariableValues(mixed $value): void
    {
        if (! is_array($value) || ! array_key_exists(PromptSiteContextVariable::POST_TYPE_FIELD, $value)) {
            return;
        }

        $this->applySiteContextDefaults(true);
        $this->form->fill($this->variableValues);
        $this->refreshCompiledPreview();
    }

    /**
     * Bỏ khóa chỉ dùng khi compile — không bind Filament/Alpine (tránh entangle lỗi).
     */
    protected function stripLoaiSanPhamComputedVariableKeys(): void
    {
        foreach ([
            PromptLoaiSanPhamVariable::NAME,
            'LOAI_SAN_PHAM',
            'loai_san_pham_preview',
        ] as $key) {
            unset($this->variableValues[$key]);
        }
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected function getVariableFormSchema(): array
    {
        if (! $this->record) {
            return [];
        }

        if ($this->promptUsesInput) {
            $this->syncVariableValueKeys();
        }

        $definitions = $this->variableDefinitions;

        if ($definitions === [] && ! $this->promptUsesInput && ! $this->promptUsesLoaiSanPham) {
            return [
                Forms\Components\Placeholder::make('no_variables')
                    ->label('')
                    ->content('This prompt does not declare @{{name}} variables. You can test it directly.'),
            ];
        }

        if ($definitions === [] && $this->promptUsesInput) {
            return [
                $this->makeInputSupplementField(),
            ];
        }

        $inputDefinition = collect($definitions)->firstWhere('name', 'input');
        $otherDefinitions = collect($definitions)->where('name', '!=', 'input')->values();

        $schema = [];

        if ($inputDefinition !== null) {
            $schema[] = $this->makeInputSupplementField($inputDefinition);
        }

        if ($this->promptUsesLoaiSanPham) {
            $schema[] = $this->makeLoaiSanPhamVariableGroup();
        }

        $schema[] = $this->makeSiteContextVariablesGroup();

        foreach ($otherDefinitions as $definition) {
            $name = (string) ($definition['name'] ?? '');
            if (PromptLoaiSanPhamVariable::isLoaiSanPhamName($name) || PromptSiteContextVariable::isName($name)) {
                continue;
            }

            $schema[] = $this->makeVariableField($definition);
        }

        return $schema;
    }

    protected function makeSiteContextVariablesGroup(): Forms\Components\Section
    {
        $labels = PromptResource::defaultVariableLabels();
        $siteId = SeoAccessControl::globalSiteId();
        $siteLabel = $siteId !== null && $siteId > 0
            ? (string) (Site::query()->whereKey($siteId)->value('domain') ?? '#'.$siteId)
            : __('seo-content-ai::filament.prompt_test.no_domain_selected');

        return Forms\Components\Section::make(__('seo-content-ai::filament.prompt_test.site_context_section'))
            ->description(__('seo-content-ai::filament.prompt_test.site_context_description', ['domain' => $siteLabel]))
            ->schema([
                Forms\Components\Select::make(PromptSiteContextVariable::POST_TYPE_FIELD)
                    ->label(__('seo-content-ai::filament.prompt_test.post_type_for_defaults'))
                    ->options([
                        'article' => __('seo-content-ai::filament.article_list.post_type_article'),
                        'product' => __('seo-content-ai::filament.article_list.post_type_product'),
                    ])
                    ->default('article')
                    ->native(false)
                    ->live()
                    ->helperText(__('seo-content-ai::filament.prompt_test.post_type_for_defaults_hint')),
                Forms\Components\Grid::make(2)
                    ->schema(
                        collect(['tone', 'keyword_density', 'article_length', 'site_cta', 'site_short_description', 'site_domain'])
                            ->map(fn (string $name): Forms\Components\Placeholder => Forms\Components\Placeholder::make('_preview_'.$name)
                                ->dehydrated(false)
                                ->label($labels[$name] ?? $name)
                                ->content(function () use ($name): HtmlString {
                                    $value = trim((string) ($this->variableValues[$name] ?? ''));
                                    if ($value === '') {
                                        return new HtmlString('<span class="text-sm text-gray-500">—</span>');
                                    }

                                    $preview = mb_strlen($value) > 220
                                        ? mb_substr($value, 0, 220).'…'
                                        : $value;

                                    return new HtmlString(
                                        '<span class="text-sm font-medium text-gray-950 dark:text-white whitespace-pre-wrap">'
                                        .e($preview)
                                        .'</span>',
                                    );
                                }),
                            )
                            ->all(),
                    ),
            ])
            ->columnSpanFull()
            ->collapsible();
    }

    protected function makeLoaiSanPhamVariableGroup(): Forms\Components\Section
    {
        $options = app(PromptLoaiSanPhamOptionsService::class);

        return Forms\Components\Section::make('Product category (product_cat)')
            ->description('Applies only when post_type = product. Select a domain, then choose product_cat or fill Custom (either one is enough).')
            ->schema([
                Forms\Components\Select::make(PromptLoaiSanPhamVariable::SITE_FIELD)
                    ->label('Domain')
                    ->options(fn (): array => $options->siteOptionsForUser())
                    ->searchable()
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(function (Forms\Set $set): void {
                        $set(PromptLoaiSanPhamVariable::CATEGORY_FIELD, null);
                    }),
                Forms\Components\Select::make(PromptLoaiSanPhamVariable::CATEGORY_FIELD)
                    ->label('Product category (product_cat)')
                    ->options(fn (Get $get): array => $options->productCategoryOptionsForSite(
                        (int) $get(PromptLoaiSanPhamVariable::SITE_FIELD),
                    ))
                    ->searchable()
                    ->required(fn (Get $get): bool => trim((string) $get(PromptLoaiSanPhamVariable::CUSTOM_FIELD)) === '')
                    ->native(false)
                    ->helperText('Optional when Custom is provided. Loaded from synced categories (product_category); sync domain first if list is empty.')
                    ->hidden(fn (Get $get): bool => blank($get(PromptLoaiSanPhamVariable::SITE_FIELD)))
                    ->live(),
                Forms\Components\TextInput::make(PromptLoaiSanPhamVariable::CUSTOM_FIELD)
                    ->label('Custom')
                    ->placeholder('e.g. tote bag, laptop backpack, school bag...')
                    ->helperText('Can be used instead of product_cat during testing.')
                    ->maxLength(500)
                    ->live(debounce: 400),
                Forms\Components\Placeholder::make('loai_san_pham_preview')
                    ->dehydrated(false)
                    ->label('Value sent to {{loai_san_pham}} / {{LOAI_SAN_PHAM}}')
                    ->content(function (Get $get) use ($options): HtmlString {
                        $text = $options->buildCompositeValue(
                            (int) $get(PromptLoaiSanPhamVariable::SITE_FIELD),
                            (int) $get(PromptLoaiSanPhamVariable::CATEGORY_FIELD),
                            trim((string) $get(PromptLoaiSanPhamVariable::CUSTOM_FIELD)),
                        );

                        return new HtmlString(
                            $text !== ''
                                ? '<span class="text-sm font-medium text-gray-950 dark:text-white">'.e($text).'</span>'
                                : '<span class="text-sm text-gray-500">—</span>',
                        );
                    })
                    ->helperText('Automatically composed from category + custom during test.'),
            ])
            ->columnSpanFull();
    }

    /**
     * @param  array{name: string, label: string, description: ?string}|null  $definition
     */
    protected function makeInputSupplementField(?array $definition = null): Forms\Components\Textarea
    {
        $helper = filled($definition['description'] ?? null)
            ? (string) $definition['description']
            : 'In Workflow Builder, {{input}} receives previous-step output. When testing here, paste or enter simulated content.';

        return Forms\Components\Textarea::make('input')
            ->label((string) ($definition['label'] ?? 'Input from connected edge (SEO Flow)'))
            ->rows(6)
            ->columnSpanFull()
            ->helperText($helper);
    }

    /**
     * @param  array{name: string, label: string, description: ?string}  $definition
     */
    protected function makeVariableField(array $definition): Forms\Components\Textarea
    {
        $field = Forms\Components\Textarea::make((string) $definition['name'])
            ->label((string) $definition['label'])
            ->rows(2)
            ->columnSpanFull();

        if (filled($definition['description'] ?? null)) {
            $field->helperText((string) $definition['description']);
        }

        return $field;
    }

    protected function getPrompt(): SeoPrompt
    {
        /** @var SeoPrompt $record */
        $record = $this->getRecord();

        return $record;
    }
}
