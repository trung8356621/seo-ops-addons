<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages;

use Omnichannel\Addons\Seo\Exceptions\AiModelsNotReadyException;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Models\TaskTestResult;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

class TestTask extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = TaskResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.task-resource.pages.test-task';

    protected static ?string $title = 'Test workflow';

    public const INPUT_TYPE_ARTICLE = 'article';

    public const INPUT_TYPE_RAW = 'input';

    /** @var array{input_type: string, article_id: ?int, title_or_keyword: ?string, raw_input: ?string} */
    public array $testInput = [
        'input_type' => self::INPUT_TYPE_ARTICLE,
        'article_id' => null,
        'title_or_keyword' => '',
        'raw_input' => '',
    ];

    /** @var array<string, mixed>|null */
    public ?array $resolvedContext = null;

    /** @var list<array<string, mixed>> */
    public array $stepResults = [];

    public ?string $errorMessage = null;

    public bool $isRunning = false;

    public ?int $runningStepIndex = null;

    public ?int $selectedResultId = null;

    /** @var array<int, string> */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->testInput['input_type'] = $this->detectDefaultInputType();
        $this->form->fill($this->testInput);

        $latest = $this->taskTestResults->first();
        if ($latest !== null) {
            $this->selectResult((int) $latest->id);
        }
    }

    /**
     * @return Collection<int, TaskTestResult>
     */
    #[Computed]
    public function taskTestResults(): Collection
    {
        return TaskTestResult::query()
            ->where('task_id', $this->getTask()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('input_type')
                    ->label(__('seo-content-ai::filament.task.test_input_type'))
                    ->options([
                        self::INPUT_TYPE_ARTICLE => __('seo-content-ai::filament.task.test_input_type_article'),
                        self::INPUT_TYPE_RAW => __('seo-content-ai::filament.task.test_input_type_input'),
                    ])
                    ->default(self::INPUT_TYPE_ARTICLE)
                    ->live()
                    ->required(),
                Forms\Components\Select::make('article_id')
                    ->label('Article')
                    ->placeholder('Choose article from list...')
                    ->searchable()
                    ->searchPrompt('Search by title or ID...')
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchArticles($search))
                    ->getOptionLabelUsing(fn ($value): ?string => $this->articleOptionLabel(
                        is_numeric($value) ? (int) $value : null,
                    ))
                    ->live()
                    ->visible(fn (Get $get): bool => $get('input_type') === self::INPUT_TYPE_ARTICLE)
                    ->helperText('All domains under your account. When an article is selected, title/keyword is ignored.'),
                Forms\Components\TextInput::make('title_or_keyword')
                    ->label('Title or keyword')
                    ->maxLength(500)
                    ->placeholder('Enter article title or focus keyword')
                    ->disabled(fn (Get $get): bool => filled($get('article_id')))
                    ->visible(fn (Get $get): bool => $get('input_type') === self::INPUT_TYPE_ARTICLE)
                    ->helperText('Used when no article selected: find existing by title first, then keyword; create new article if no match.'),
                Forms\Components\Textarea::make('raw_input')
                    ->label(__('seo-content-ai::filament.task.test_raw_input'))
                    ->rows(6)
                    ->visible(fn (Get $get): bool => $get('input_type') === self::INPUT_TYPE_RAW)
                    ->helperText(__('seo-content-ai::filament.task.test_raw_input_hint')),
            ])
            ->statePath('testInput');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Test: '.(string) $this->getTask()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('seo-content-ai::filament.task.back_to_tasks'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(TaskResource::getUrl('index')),
            Actions\Action::make('builder')
                ->label('Open builder')
                ->icon('heroicon-o-squares-2x2')
                ->url(fn (): string => TaskResource::getUrl('builder', ['record' => $this->getRecord()])),
        ];
    }

    public function runTest(
        TaskTestInputResolver $resolver,
        TaskWorkflowTestRunner $runner,
    ): void {
        $this->isRunning = true;
        $this->errorMessage = null;
        $this->resolvedContext = null;
        $this->stepResults = [];
        $this->runningStepIndex = null;

        $state = $this->form->getState();
        $startedAt = now();

        try {
            $context = $this->resolveContext($resolver, $state);
            $this->resolvedContext = $context->toArray();
            $this->stepResults = $runner->run($this->getTask(), $context);

            $failed = collect($this->stepResults)->where('status', 'failed')->count();
            $status = $failed > 0 ? 'failed' : 'completed';

            $result = $this->persistResult($state, $status, $startedAt);
            $this->selectedResultId = (int) $result->id;
            unset($this->taskTestResults);

            $notification = Notification::make()
                ->title($failed > 0 ? 'Test completed (with errors)' : 'Test successful')
                ->body($context->summary);

            if ($failed > 0) {
                $notification->warning();
            } else {
                $notification->success();
            }

            $notification->send();
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            Notification::make()
                ->title('Unable to run test')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } catch (AiModelsNotReadyException $exception) {
            Notification::make()
                ->title('AI model sync required')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            $this->redirect($exception->overviewUrl(), navigate: true);
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            $result = $this->persistResult($state, 'failed', $startedAt);
            $this->selectedResultId = (int) $result->id;
            unset($this->taskTestResults);

            Notification::make()
                ->title('Test failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isRunning = false;
        }
    }

    public function rerunStep(int $stepIndex, TaskWorkflowTestRunner $runner): void
    {
        if ($this->resolvedContext === null || $this->stepResults === []) {
            Notification::make()
                ->title('No result yet')
                ->body('Run workflow test first or select a run from history.')
                ->warning()
                ->send();

            return;
        }

        if (! isset($this->stepResults[$stepIndex])) {
            return;
        }

        $nodeId = (string) ($this->stepResults[$stepIndex]['node_id'] ?? '');
        if ($nodeId === '') {
            return;
        }

        $this->runningStepIndex = $stepIndex;

        try {
            $context = TaskTestContext::fromArray($this->resolvedContext);
            $priorSteps = array_slice($this->stepResults, 0, $stepIndex);
            $this->stepResults[$stepIndex] = $runner->runSingleStep(
                $this->getTask(),
                $context,
                $nodeId,
                $priorSteps,
            );

            $this->syncSelectedResult();

            $step = $this->stepResults[$stepIndex];
            $status = (string) ($step['status'] ?? '');

            $notification = Notification::make()
                ->title('Re-ran step #'.($stepIndex + 1));

            if ($status === 'failed') {
                $notification->body((string) ($step['message'] ?? 'Step failed.'))->warning();
            } elseif ($status === 'skipped') {
                $notification->body((string) ($step['message'] ?? 'Step skipped.'))->info();
            } else {
                $notification->body((string) ($step['message'] ?? 'Step completed.'))->success();
            }

            $notification->send();
        } catch (AiModelsNotReadyException $exception) {
            Notification::make()
                ->title('AI model sync required')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            $this->redirect($exception->overviewUrl(), navigate: true);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Step rerun failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->runningStepIndex = null;
        }
    }

    public function selectResult(int $resultId): void
    {
        $result = TaskTestResult::query()
            ->where('task_id', $this->getTask()->id)
            ->findOrFail($resultId);

        $this->selectedResultId = $resultId;
        $this->applyResultToView($result);
        unset($this->taskTestResults);
    }

    public function deleteResult(int $resultId): void
    {
        $result = TaskTestResult::query()
            ->where('task_id', $this->getTask()->id)
            ->findOrFail($resultId);

        $wasSelected = $this->selectedResultId === $resultId;
        $result->delete();

        unset($this->taskTestResults);

        if ($wasSelected) {
            $this->clearResultView();

            $latest = $this->taskTestResults->first();
            if ($latest !== null) {
                $this->selectResult((int) $latest->id);
            }
        }

        Notification::make()
            ->title('Test run deleted')
            ->success()
            ->send();
    }

    public function resultSummary(TaskTestResult $result): string
    {
        $context = is_array($result->resolved_context) ? $result->resolved_context : [];

        if (filled($context['summary'] ?? null)) {
            $summary = (string) $context['summary'];

            return mb_strlen($summary) > 48 ? mb_substr($summary, 0, 48).'…' : $summary;
        }

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $query = trim((string) ($snapshot['title_or_keyword'] ?? ''));

        if ($query !== '') {
            return mb_strlen($query) > 48 ? mb_substr($query, 0, 48).'…' : $query;
        }

        $rawInput = trim((string) ($snapshot['raw_input'] ?? ''));
        if ($rawInput !== '') {
            return mb_strlen($rawInput) > 48 ? mb_substr($rawInput, 0, 48).'…' : $rawInput;
        }

        return '#'.$result->id;
    }

    public function resultStepLabel(TaskTestResult $result): string
    {
        $steps = is_array($result->step_results) ? $result->step_results : [];
        $total = count($steps);
        $failed = collect($steps)->where('status', 'failed')->count();

        if ($total === 0) {
            return '0 steps';
        }

        return $failed > 0
            ? sprintf('%d steps · %d errors', $total, $failed)
            : sprintf('%d steps', $total);
    }

    /**
     * @param  array<string, mixed>  $step
     */
    public function stepModelLabel(array $step): ?string
    {
        $renderModel = trim((string) ($step['render_model'] ?? ''));
        if ($renderModel !== '') {
            return $renderModel;
        }

        $rawModel = trim((string) ($step['raw_model_used'] ?? ''));
        if ($rawModel !== '') {
            return $rawModel;
        }

        $plannerModel = trim((string) ($step['planner_model'] ?? ''));
        if ($plannerModel !== '') {
            return $plannerModel;
        }

        $tools = trim((string) ($step['tools'] ?? ''));
        if (ImageToolType::fromMixed($tools)->isImagePipeline()) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    public function stepMediaUrls(array $step): array
    {
        $raw = trim((string) ($step['output'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $tools = trim((string) ($step['tools'] ?? ''));
        $isImagePipeline = $tools !== '' && ImageToolType::fromMixed($tools)->isImagePipeline();

        $urls = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '/storage/') || preg_match('#^https?://#i', $line) === 1) {
                $urls[] = $line;
            }
        }

        if ($urls === [] && ! $isImagePipeline) {
            return [];
        }

        if ($urls === [] && preg_match_all('#(?:/storage/[^\s)\]"\']+|https?://[^\s)\]"\']+)#iu', $raw, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $urls[] = rtrim((string) $match, '.,;');
            }
        }

        return array_values(array_unique($urls));
    }

    protected function clearResultView(): void
    {
        $this->selectedResultId = null;
        $this->resolvedContext = null;
        $this->stepResults = [];
        $this->errorMessage = null;
    }

    protected function applyResultToView(TaskTestResult $result): void
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        $this->testInput = [
            'input_type' => $this->normalizeInputType((string) ($snapshot['input_type'] ?? self::INPUT_TYPE_ARTICLE)),
            'article_id' => filled($snapshot['article_id'] ?? null) ? (int) $snapshot['article_id'] : null,
            'title_or_keyword' => (string) ($snapshot['title_or_keyword'] ?? ''),
            'raw_input' => (string) ($snapshot['raw_input'] ?? ''),
        ];
        $this->form->fill($this->testInput);

        $this->resolvedContext = is_array($result->resolved_context) ? $result->resolved_context : null;
        $this->stepResults = is_array($result->step_results) ? $result->step_results : [];
        $this->errorMessage = $result->status === 'failed' && $this->stepResults === []
            ? (string) ($result->error_message ?? 'Test failed.')
            : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveContext(TaskTestInputResolver $resolver, array $state): TaskTestContext
    {
        $inputType = $this->normalizeInputType((string) ($state['input_type'] ?? self::INPUT_TYPE_ARTICLE));

        if ($inputType === self::INPUT_TYPE_RAW) {
            return $resolver->resolveFromRawInput((string) ($state['raw_input'] ?? ''));
        }

        $articleId = filled($state['article_id'] ?? null) ? (int) $state['article_id'] : null;
        $query = trim((string) ($state['title_or_keyword'] ?? ''));

        if ($articleId === null && $query === '') {
            throw new \InvalidArgumentException('Choose an article or enter a title/keyword.');
        }

        return $resolver->resolve(
            $articleId,
            $query !== '' ? $query : null,
            $query !== '' ? $query : null,
            function (Builder $builder): void {
                $this->applyUserScopeToArticles($builder);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persistResult(array $state, string $status, \DateTimeInterface $startedAt): TaskTestResult
    {
        return TaskTestResult::query()->create([
            'task_id' => $this->getTask()->id,
            'user_id' => auth()->id(),
            'status' => $status,
            'input_snapshot' => [
                'input_type' => $this->normalizeInputType((string) ($state['input_type'] ?? self::INPUT_TYPE_ARTICLE)),
                'article_id' => filled($state['article_id'] ?? null) ? (int) $state['article_id'] : null,
                'title_or_keyword' => (string) ($state['title_or_keyword'] ?? ''),
                'raw_input' => (string) ($state['raw_input'] ?? ''),
            ],
            'resolved_context' => $this->resolvedContext,
            'step_results' => $this->stepResults,
            'error_message' => $this->errorMessage,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    private function syncSelectedResult(): void
    {
        if ($this->selectedResultId === null) {
            return;
        }

        $result = TaskTestResult::query()
            ->where('task_id', $this->getTask()->id)
            ->find($this->selectedResultId);

        if ($result === null) {
            return;
        }

        $failed = collect($this->stepResults)->where('status', 'failed')->count();

        $result->update([
            'status' => $failed > 0 ? 'failed' : 'completed',
            'resolved_context' => $this->resolvedContext,
            'step_results' => $this->stepResults,
            'finished_at' => now(),
        ]);

        unset($this->taskTestResults);
    }

    /**
     * @return array<int, string>
     */
    private function searchArticles(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $query = $this->articlesQuery()->with('site');

        $query->where(function (Builder $inner) use ($search): void {
            $inner->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%');

            if (ctype_digit($search)) {
                $inner->orWhere('id', (int) $search);
            }
        });

        return $query
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (SeoArticle $article): array => [
                $article->id => $this->formatArticleOptionLabel($article),
            ])
            ->all();
    }

    private function articleOptionLabel(?int $articleId): ?string
    {
        if ($articleId === null || $articleId <= 0) {
            return null;
        }

        $article = $this->articlesQuery()->with('site')->find($articleId);

        return $article instanceof SeoArticle
            ? $this->formatArticleOptionLabel($article)
            : null;
    }

    private function formatArticleOptionLabel(SeoArticle $article): string
    {
        $domain = trim((string) ($article->site?->domain ?? ''));
        $domainLabel = $domain !== '' ? $domain : '—';

        return sprintf('#%d · %s (%s)', $article->id, (string) $article->title, $domainLabel);
    }

    private function articlesQuery(): Builder
    {
        return ArticleResource::getEloquentQuery();
    }

    private function applyUserScopeToArticles(Builder $query): void
    {
        SeoAccessControl::applyAccessibleSiteScope($query);
    }

    private function getTask(): SeoTask
    {
        /** @var SeoTask $record */
        $record = $this->getRecord();

        return $record;
    }

    private function detectDefaultInputType(): string
    {
        $flow = is_array($this->getTask()->flow_data) ? $this->getTask()->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];

        if ($nodes === []) {
            return self::INPUT_TYPE_ARTICLE;
        }

        $targetNodeIds = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $targetId = (string) ($edge['targetNode'] ?? '');
            if ($targetId !== '') {
                $targetNodeIds[$targetId] = true;
            }
        }

        $hasArticleStart = false;
        $hasInputStart = false;

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeId = (string) ($node['id'] ?? '');
            if ($nodeId === '' || isset($targetNodeIds[$nodeId])) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            if ($type === 'article') {
                $hasArticleStart = true;
            }

            if ($type === 'user_input') {
                $hasInputStart = true;
            }
        }

        if ($hasInputStart && ! $hasArticleStart) {
            return self::INPUT_TYPE_RAW;
        }

        return self::INPUT_TYPE_ARTICLE;
    }

    private function normalizeInputType(string $inputType): string
    {
        return $inputType === self::INPUT_TYPE_RAW
            ? self::INPUT_TYPE_RAW
            : self::INPUT_TYPE_ARTICLE;
    }

}
