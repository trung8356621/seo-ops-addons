<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use App\Support\RuntimeLogger;
use Closure;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ResumeProjectItemFromFailedStepHandler;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedEvidence;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTransientAiRetryPolicy;
use Throwable;

/**
 * Historical repair for Content Project items stuck on a *transient* AI failure.
 *
 * Scope guarantees:
 * - One project per invocation, dry-run unless explicitly applied.
 * - Apply goes through existing application handlers only:
 *   {@see ResumeProjectItemFromFailedStepHandler} when capability is resume,
 *   {@see RerunProjectItemsHandler} for full rerun/generate — no article mutation,
 *   no bespoke generation pipeline, no attempt-limit changes.
 * - Anything not provably transient stays untouched.
 */
final class ContentProjectAiFailureRepairService
{
    public const CORRELATION_ID = 'seo:content-project:repair-ai-failures';

    public const CLASS_RETRYABLE = 'retryable';

    public const CLASS_HARD = 'hard';

    public const CLASS_ACTIVE = 'active';

    public const CLASS_PUBLISHED = 'published';

    public const CLASS_RECOVERED = 'recovered';

    public const CLASS_ARCHIVED = 'archived';

    public const CLASS_IMPROVE = 'improve';

    public const CLASS_SKIP = 'skip';

    public const PATH_RESUME = 'resume';

    public const PATH_RERUN = 'rerun';

    /**
     * Credential / billing / contract failures — a rerun would burn budget and fail again.
     *
     * @var list<string>
     */
    private const HARD_MESSAGE_PATTERNS = [
        'model_not_found',
        'model not found',
        'unknown model',
        'hard_route_exhaustion',
        'invalid credential',
        'invalid_credential',
        'credential_invalid',
        'invalid api key',
        'invalid_api_key',
        'api key not valid',
        'missing api key',
        'unauthorized',
        'unauthenticated',
        'permission denied',
        'billing',
        'payment required',
        'insufficient balance',
        'insufficient credit',
        'insufficient_quota',
        'paid plan',
        'account restricted',
        'account_restricted',
        'account suspended',
        'invalid request',
        'invalid_request',
        'request_invalid',
        'invalid workflow',
        'workflow_invalid',
        'workflow not found',
        'output quality',
        'output_quality',
        'quality gate',
        'cancelled',
        'canceled',
    ];

    /**
     * Provider-side hiccups — safe to re-attempt later.
     *
     * @var list<string>
     */
    private const TRANSIENT_MESSAGE_PATTERNS = [
        'ai_routes_exhausted',
        'routes exhausted',
        'no eligible ai route',
        'no eligible route',
        'no ai route was attempted',
        'all candidates unavailable',
        'connection unavailable',
        'rate limit',
        'rate_limit',
        'ratelimit',
        'too many requests',
        'timeout',
        'timed out',
        'timed-out',
        'deadline exceeded',
        'bad gateway',
        'service unavailable',
        'gateway timeout',
        'temporarily unavailable',
        'temporarily overloaded',
        'temporary provider',
        'provider unavailable',
        'overloaded',
        'cooldown',
        'try again later',
        'internal server error',
        'connection reset',
        'connection refused',
        'curl error',
    ];

    /** @var (Closure(int, list<int>): ContentProjectActionResult)|null */
    private ?Closure $rerunInvoker = null;

    public function __construct(
        private readonly ?RerunProjectItemsHandler $rerunHandler = null,
        private readonly ?ResumeProjectItemFromFailedStepHandler $resumeHandler = null,
        private readonly ?ContentProjectGenerationCapabilityResolver $capability = null,
    ) {}

    /**
     * Test/automation seam: bypass the real handlers with a callable.
     *
     * @param  (Closure(int, list<int>): ContentProjectActionResult)|null  $invoker
     */
    public function usingRerunInvoker(?Closure $invoker): self
    {
        $this->rerunInvoker = $invoker;

        return $this;
    }

    /**
     * @return array{
     *     project_id: int,
     *     project_found: bool,
     *     applied: bool,
     *     scanned: int,
     *     retryable_failed: int,
     *     hard_failed_skipped: int,
     *     active_skipped: int,
     *     published_skipped: int,
     *     already_recovered: int,
     *     archived_skipped: int,
     *     queued_for_rerun: int,
     *     errors: list<string>,
     *     eligible_item_ids: list<int>,
     *     items: list<array<string, mixed>>
     * }
     */
    public function scan(int $projectId, ?int $itemId = null, bool $apply = false): array
    {
        $report = [
            'project_id' => $projectId,
            'project_found' => false,
            'applied' => $apply,
            'scanned' => 0,
            'retryable_failed' => 0,
            'hard_failed_skipped' => 0,
            'active_skipped' => 0,
            'published_skipped' => 0,
            'already_recovered' => 0,
            'archived_skipped' => 0,
            'queued_for_rerun' => 0,
            'errors' => [],
            'eligible_item_ids' => [],
            'items' => [],
        ];

        if ($projectId <= 0) {
            $report['errors'][] = 'invalid_project_id';

            return $report;
        }

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            $report['errors'][] = 'project_not_found: '.$projectId;

            return $report;
        }
        $report['project_found'] = true;

        $projectArchived = $project->archived_at !== null || $project->isArchive();

        $query = SeoProjectTask::query()->where('project_id', $projectId);
        if ($itemId !== null && $itemId > 0) {
            // Explicit item stays inspectable even when archived/cancelled.
            $query->whereKey($itemId);
        } else {
            $query->planned();
        }

        /** @var list<SeoProjectTask> $tasks */
        $tasks = $query->orderBy('id')->get()->all();
        if ($tasks === []) {
            return $report;
        }

        $taskIds = array_map(static fn (SeoProjectTask $task): int => (int) $task->getKey(), $tasks);
        $latestExecByTask = $this->latestArticleExecutions($taskIds);
        $activeDispatchRunItemIds = $this->activeDispatchRunItemIds($projectId);

        $eligible = [];
        foreach ($tasks as $task) {
            $report['scanned']++;
            $taskId = (int) $task->getKey();
            $exec = $latestExecByTask[$taskId] ?? null;
            $snapshot = $this->buildSnapshot($task, $exec, $projectArchived, $activeDispatchRunItemIds);
            $classification = self::classifyFailure($snapshot);

            $report['items'][] = [
                'task_id' => $taskId,
                'classification' => $classification,
                'task_status' => (string) ($snapshot['task_status'] ?? ''),
                'exec_status' => $snapshot['exec_status'],
                'run_item_id' => $snapshot['run_item_id'],
                'message' => self::truncate((string) ($snapshot['message'] ?? '')),
            ];

            switch ($classification) {
                case self::CLASS_RETRYABLE:
                    $report['retryable_failed']++;
                    $eligible[] = $taskId;
                    break;
                case self::CLASS_HARD:
                case self::CLASS_IMPROVE:
                    $report['hard_failed_skipped']++;
                    break;
                case self::CLASS_ACTIVE:
                    $report['active_skipped']++;
                    break;
                case self::CLASS_PUBLISHED:
                    $report['published_skipped']++;
                    break;
                case self::CLASS_RECOVERED:
                    $report['already_recovered']++;
                    break;
                case self::CLASS_ARCHIVED:
                    $report['archived_skipped']++;
                    break;
                default:
                    break;
            }
        }

        $report['eligible_item_ids'] = $eligible;

        $dispatch = $this->dispatchRepair($projectId, $eligible, $apply);
        $report['queued_for_rerun'] = $dispatch['queued_for_rerun'];
        $report['errors'] = array_merge($report['errors'], $dispatch['errors']);

        if ($apply && $eligible !== []) {
            $this->logApplied($projectId, $eligible, $report['queued_for_rerun'], $report['errors']);
        }

        return $report;
    }

    /**
     * Pure dispatch step — no DB reads, so callers can unit-test apply semantics.
     * Per-item invocation keeps error attribution readable in the report.
     *
     * @param  list<int>  $eligibleItemIds
     * @return array{queued_for_rerun: int, errors: list<string>}
     */
    public function dispatchRepair(int $projectId, array $eligibleItemIds, bool $apply): array
    {
        if (! $apply) {
            return ['queued_for_rerun' => count($eligibleItemIds), 'errors' => []];
        }

        $queued = 0;
        $errors = [];

        foreach ($eligibleItemIds as $taskId) {
            $taskId = (int) $taskId;
            try {
                $result = $this->invokeRepair($projectId, [$taskId]);
                if ($result->success) {
                    $queued++;

                    continue;
                }
                $errors[] = sprintf(
                    'item=%d repair_rejected code=%s message=%s',
                    $taskId,
                    $result->code,
                    self::truncate($result->message),
                );
            } catch (Throwable $e) {
                $errors[] = sprintf('item=%d repair_exception=%s', $taskId, self::truncate($e->getMessage()));
            }
        }

        return ['queued_for_rerun' => $queued, 'errors' => $errors];
    }

    /**
     * Single decision point for "may this historical row be re-run?".
     *
     * @param  array<string, mixed>  $snapshot  See {@see buildSnapshot()} for keys.
     * @return self::CLASS_*
     */
    public static function classifyFailure(array $snapshot): string
    {
        if (! empty($snapshot['project_archived']) || ! empty($snapshot['task_archived'])) {
            return self::CLASS_ARCHIVED;
        }

        if (! empty($snapshot['published'])) {
            return self::CLASS_PUBLISHED;
        }

        if (! empty($snapshot['active'])) {
            return self::CLASS_ACTIVE;
        }

        if (self::looksRecovered($snapshot)) {
            return self::CLASS_RECOVERED;
        }

        if (! self::looksFailed($snapshot)) {
            return self::CLASS_SKIP;
        }

        if (SeoProjectTask::normalizeType((string) ($snapshot['task_type'] ?? '')) === SeoProjectTask::TYPE_IMPROVE
            && empty($snapshot['allow_improve'])
        ) {
            return self::CLASS_IMPROVE;
        }

        // Rewrite/improve without a resolved Existing Article needs a human pick.
        if (! empty($snapshot['existing_article_unresolved'])) {
            return self::CLASS_SKIP;
        }

        return self::isHistoricalTransientAiFailure($snapshot)
            ? self::CLASS_RETRYABLE
            : self::CLASS_HARD;
    }

    /**
     * Structured routing metadata is SSOT; message heuristics only fill the legacy gap.
     *
     * @param  array<string, mixed>  $itemRow
     */
    public static function isHistoricalTransientAiFailure(array $itemRow): bool
    {
        if (self::hasHardSignal($itemRow)) {
            return false;
        }

        if (self::hasStructuredRoutingSignal($itemRow)) {
            return ContentProjectTransientAiRetryPolicy::fromFailedItemRow($itemRow) !== null;
        }

        return self::messageLooksTransient(self::haystack($itemRow));
    }

    /**
     * @param  array<string, mixed>|null  $exec
     * @param  array<int, true>  $activeDispatchRunItemIds
     * @return array<string, mixed>
     */
    private function buildSnapshot(
        SeoProjectTask $task,
        ?array $exec,
        bool $projectArchived,
        array $activeDispatchRunItemIds,
    ): array {
        $execStatus = $exec !== null
            ? ContentProjectExecutionStatus::normalize((string) ($exec['status'] ?? ''))
            : null;
        $runItemId = $exec !== null ? (int) $exec['id'] : null;

        $taskStatus = strtolower(trim((string) $task->status));
        $execActive = $execStatus !== null && ContentProjectExecutionStatus::isActive($execStatus);
        $dispatchLocked = $runItemId !== null && isset($activeDispatchRunItemIds[$runItemId]);
        // No execution row at all while the task claims to be running: unknown runtime, leave it alone.
        $taskRunningWithoutEvidence = $exec === null
            && in_array($taskStatus, [SeoProjectTask::STATUS_WRITING, SeoProjectTask::STATUS_PROCESSING], true);

        $failureRow = $exec !== null
            ? $this->failureRowFromExecution($exec)
            : $this->failureRowFromTask($task);

        $type = SeoProjectTask::normalizeType((string) $task->type);
        $existingArticleUnresolved = in_array($type, SeoProjectTask::typesRequiringExistingArticle(), true)
            && (int) ($task->article_id ?? 0) <= 0;

        return array_merge($failureRow, [
            'task_id' => (int) $task->getKey(),
            'task_status' => $taskStatus,
            'task_type' => $type,
            'task_archived' => $task->archived_at !== null,
            'project_archived' => $projectArchived,
            'published' => $this->isPublished($task),
            'active' => $execActive || $dispatchLocked || $taskRunningWithoutEvidence,
            'existing_article_unresolved' => $existingArticleUnresolved,
            'exec_status' => $execStatus,
            'exec_present' => $exec !== null,
            'run_item_id' => $runItemId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $exec
     * @return array<string, mixed>
     */
    private function failureRowFromExecution(array $exec): array
    {
        $snapshot = is_array($exec['output_snapshot'] ?? null) ? $exec['output_snapshot'] : [];

        $row = $snapshot;
        $row['message'] = (string) ($exec['message'] ?? '');
        $row['error_message'] = (string) ($exec['error_message'] ?? '');
        $row['error_detail'] = (string) ($snapshot['error_detail'] ?? $row['error_message']);
        $row['error_code'] = (string) ($snapshot['error_code'] ?? '');
        $row['steps'] = is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [];
        $row['ai_routing'] = is_array($snapshot['ai_routing'] ?? null) ? $snapshot['ai_routing'] : [];

        return $row;
    }

    /**
     * Legacy rows: task marked failed but the run item is gone. Without stored
     * evidence there is nothing to prove transience, so the row stays hard.
     *
     * @return array<string, mixed>
     */
    private function failureRowFromTask(SeoProjectTask $task): array
    {
        $attributes = $task->getAttributes();
        $message = '';
        foreach (['error_message', 'last_error', 'generation_error', 'failure_reason'] as $key) {
            $candidate = trim((string) ($attributes[$key] ?? ''));
            if ($candidate !== '') {
                $message = $candidate;
                break;
            }
        }

        return [
            'message' => $message,
            'error_message' => $message,
            'error_detail' => '',
            'error_code' => '',
            'steps' => [],
            'ai_routing' => [],
        ];
    }

    private function isPublished(SeoProjectTask $task): bool
    {
        try {
            return ContentProjectPublishedEvidence::fromTaskAndArticle($task);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>>
     */
    private function latestArticleExecutions(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $items = SeoProjectRunItem::query()
            ->whereIn('task_id', $taskIds)
            ->articleExecution()
            ->orderByDesc('id')
            ->get([
                'id', 'task_id', 'run_id', 'status', 'action', 'attempt',
                'message', 'error_message', 'output_snapshot', 'started_at', 'finished_at',
            ]);

        $map = [];
        foreach ($items as $item) {
            $tid = (int) $item->task_id;
            if ($tid <= 0 || isset($map[$tid])) {
                continue;
            }
            $map[$tid] = [
                'id' => (int) $item->id,
                'task_id' => $tid,
                'run_id' => (int) $item->run_id,
                'status' => (string) ($item->status ?? ''),
                'message' => $item->message !== null ? (string) $item->message : '',
                'error_message' => $item->error_message !== null ? (string) $item->error_message : '',
                'output_snapshot' => is_array($item->output_snapshot) ? $item->output_snapshot : [],
            ];
        }

        return $map;
    }

    /**
     * Run items currently holding the engine dispatch lock — never repair those.
     *
     * @return array<int, true>
     */
    private function activeDispatchRunItemIds(int $projectId): array
    {
        $locked = [];

        try {
            $runs = SeoProjectRun::query()
                ->where('project_id', $projectId)
                ->orderByDesc('id')
                ->limit(25)
                ->get(['id', 'settings']);
        } catch (Throwable) {
            return [];
        }

        foreach ($runs as $run) {
            $settings = is_array($run->settings ?? null) ? $run->settings : [];
            // Engine SoT is php_engine; legacy `engine` kept as fallback only.
            $engine = is_array($settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY]
                : (is_array($settings['engine'] ?? null) ? $settings['engine'] : []);
            $dispatch = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
            $runItemId = (int) ($dispatch['run_item_id'] ?? 0);
            if ($runItemId > 0) {
                $locked[$runItemId] = true;
            }
        }

        return $locked;
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function invokeRepair(int $projectId, array $itemIds): ContentProjectActionResult
    {
        // Test seam keeps a 2-arg signature — production routes by capability below.
        if ($this->rerunInvoker !== null) {
            return ($this->rerunInvoker)($projectId, $itemIds);
        }

        $path = $this->preferredRepairPath($projectId, (int) ($itemIds[0] ?? 0));
        if ($path === self::PATH_RESUME) {
            return $this->invokeResume($projectId, $itemIds);
        }

        return $this->invokeFullRerun($projectId, $itemIds);
    }

    /**
     * Map capability decision → repair handler. Public for unit coverage without DB.
     */
    public static function pathForCapabilityAction(
        string $action,
        bool $showResume = false,
        ?string $resumableFromStep = null,
    ): string {
        if ($action === ContentProjectGenerationRecoveryDecision::ACTION_RESUME) {
            return self::PATH_RESUME;
        }

        if (in_array($action, [
            ContentProjectGenerationRecoveryDecision::ACTION_RERUN,
            ContentProjectGenerationRecoveryDecision::ACTION_GENERATE,
        ], true)) {
            return self::PATH_RERUN;
        }

        if ($showResume || ($resumableFromStep !== null && $resumableFromStep !== '')) {
            return self::PATH_RESUME;
        }

        return self::PATH_RERUN;
    }

    private function preferredRepairPath(int $projectId, int $taskId): string
    {
        if ($taskId <= 0) {
            return self::PATH_RERUN;
        }

        try {
            $project = SeoProject::query()->find($projectId);
            $task = SeoProjectTask::query()->find($taskId);
            if (! $project instanceof SeoProject || ! $task instanceof SeoProjectTask) {
                return self::PATH_RERUN;
            }

            $capability = ($this->capability ?? app(ContentProjectGenerationCapabilityResolver::class))
                ->decide($project, $task, [
                    'recover_stale' => true,
                    'persist_article_repair' => true,
                ]);

            return self::pathForCapabilityAction(
                $capability->action,
                $capability->showResume(),
                $capability->resumableFromStep,
            );
        } catch (Throwable) {
            return self::PATH_RERUN;
        }
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function invokeResume(int $projectId, array $itemIds): ContentProjectActionResult
    {
        $handler = $this->resumeHandler ?? app(ResumeProjectItemFromFailedStepHandler::class);

        return $handler->handle(
            new ResumeProjectItemFromFailedStepCommand(
                projectRef: $projectId,
                itemRefs: $itemIds,
                mode: 'full',
            ),
            ActorContext::system(self::CORRELATION_ID),
        );
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function invokeFullRerun(int $projectId, array $itemIds): ContentProjectActionResult
    {
        $handler = $this->rerunHandler ?? app(RerunProjectItemsHandler::class);

        return $handler->handle(
            new RerunProjectItemsCommand(
                projectRef: $projectId,
                itemRefs: $itemIds,
                mode: 'full',
                settings: [],
            ),
            ActorContext::system(self::CORRELATION_ID),
        );
    }

    /**
     * @param  list<int>  $eligible
     * @param  list<string>  $errors
     */
    private function logApplied(int $projectId, array $eligible, int $queued, array $errors): void
    {
        try {
            RuntimeLogger::info('content_project.ai_failure_repair_applied', [
                'project_id' => $projectId,
                'eligible_item_ids' => $eligible,
                'queued_for_rerun' => $queued,
                'error_count' => count($errors),
            ]);
        } catch (Throwable) {
            // Reporting must survive a logging outage.
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function looksRecovered(array $snapshot): bool
    {
        $execStatus = $snapshot['exec_status'] ?? null;
        if (is_string($execStatus) && ContentProjectExecutionStatus::normalize($execStatus) === 'success') {
            return true;
        }

        return in_array((string) ($snapshot['task_status'] ?? ''), [
            SeoProjectTask::STATUS_COMPLETED,
            SeoProjectTask::STATUS_REVIEWING,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function looksFailed(array $snapshot): bool
    {
        $execStatus = $snapshot['exec_status'] ?? null;
        if (is_string($execStatus)
            && in_array(ContentProjectExecutionStatus::normalize($execStatus), ['failed', 'timeout'], true)
        ) {
            return true;
        }

        return (string) ($snapshot['task_status'] ?? '') === SeoProjectTask::STATUS_FAILED;
    }

    /**
     * @param  array<string, mixed>  $itemRow
     */
    private static function hasHardSignal(array $itemRow): bool
    {
        if (self::carriesHardRoutingFlag($itemRow)) {
            return true;
        }

        $steps = is_array($itemRow['steps'] ?? null) ? $itemRow['steps'] : [];
        foreach ($steps as $step) {
            if (is_array($step) && self::carriesHardRoutingFlag($step)) {
                return true;
            }
        }

        $haystack = self::haystack($itemRow);
        foreach (self::HARD_MESSAGE_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return preg_match('/\b(401|402|403)\b/', $haystack) === 1;
    }

    /**
     * Explicit `retryable=false` / hard exhaustion kind outranks any message text.
     *
     * @param  array<string, mixed>  $row
     */
    private static function carriesHardRoutingFlag(array $row): bool
    {
        $nested = is_array($row['ai_routing'] ?? null) ? $row['ai_routing'] : [];

        foreach ([$nested, $row] as $source) {
            if (array_key_exists('retryable', $source)
                && $source['retryable'] !== null
                && ! self::truthy($source['retryable'])
            ) {
                return true;
            }

            $kind = $source['exhaustion_kind'] ?? null;
            if (is_string($kind) && $kind === AiRoutesExhaustionClassifier::KIND_HARD) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $itemRow
     */
    private static function hasStructuredRoutingSignal(array $itemRow): bool
    {
        if (self::rowCarriesRoutingSignal($itemRow)) {
            return true;
        }

        $steps = is_array($itemRow['steps'] ?? null) ? $itemRow['steps'] : [];
        foreach ($steps as $step) {
            if (is_array($step) && self::rowCarriesRoutingSignal($step)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors ContentProjectTransientAiRetryPolicy's decisiveness rule so a
     * structured row is never silently downgraded to message parsing.
     *
     * @param  array<string, mixed>  $row
     */
    private static function rowCarriesRoutingSignal(array $row): bool
    {
        $nested = is_array($row['ai_routing'] ?? null) ? $row['ai_routing'] : [];
        $pick = static function (string $key) use ($nested, $row): mixed {
            if (array_key_exists($key, $nested) && $nested[$key] !== null) {
                return $nested[$key];
            }

            return $row[$key] ?? null;
        };

        if ((string) ($pick('classification') ?? '') === AiRoutesExhaustedException::CLASSIFICATION) {
            return true;
        }

        $kind = $pick('exhaustion_kind');
        if (is_string($kind) && $kind !== '') {
            return true;
        }

        return $pick('retryable') !== null && is_array($pick('routing_attempts'));
    }

    private static function messageLooksTransient(string $haystack): bool
    {
        if ($haystack === '') {
            return false;
        }

        foreach (self::TRANSIENT_MESSAGE_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        // Bare status codes only — avoids matching content lengths like "minimum 500 characters".
        return preg_match('/\b(429|502|503|504)\b/', $haystack) === 1
            || preg_match('/\b(http|status|code)\s*5\d{2}\b/', $haystack) === 1;
    }

    /**
     * @param  array<string, mixed>  $itemRow
     */
    private static function haystack(array $itemRow): string
    {
        $parts = [
            (string) ($itemRow['message'] ?? ''),
            (string) ($itemRow['error_message'] ?? ''),
            (string) ($itemRow['error_detail'] ?? ''),
            (string) ($itemRow['error_code'] ?? ''),
            (string) ($itemRow['classification'] ?? ''),
            (string) ($itemRow['exhaustion_kind'] ?? ''),
        ];

        $steps = is_array($itemRow['steps'] ?? null) ? $itemRow['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $parts[] = (string) ($step['message'] ?? '');
            $parts[] = (string) ($step['error_detail'] ?? '');
        }

        return strtolower(trim(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== ''))));
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }

    private static function truncate(string $value, int $limit = 200): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }
}
