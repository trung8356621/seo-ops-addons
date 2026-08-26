<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueHandoffEligibility;

/**
 * Presentation-only action visibility for Project Items menu.
 * Does not change CommandBus eligibility — only hides UI noise.
 *
 * Module boundary: Content Project owns generation/review only. Schedule,
 * Unschedule, Publish Now, Retry publish, Skip, Cancel belong to the
 * Publishing Queue module and are hidden here — `send_to_publishing_queue`
 * is the only handoff action offered from this presenter.
 *
 * @phpstan-type ActionFlags array{
 *     open_article: bool,
 *     generate: bool,
 *     run_again: bool,
 *     create_or_rerun: bool,
 *     create_or_rerun_label: string,
 *     run_generation_bulk: bool,
 *     confirm_recreate_missing_article: bool,
 *     stop_generation: bool,
 *     resume_generation: bool,
 *     select_existing_article: bool,
 *     regen_outline: bool,
 *     regen_article: bool,
 *     restart_with_keyword: bool,
 *     regen_image: bool,
 *     retry_failed_step: bool,
 *     debug_rerun_from_start: bool,
 *     acknowledge_error: bool,
 *     prefer_acknowledge_error: bool,
 *     skip_generation: bool,
 *     allow_generation: bool,
 *     improve_note: bool,
 *     start_review: bool,
 *     approve: bool,
 *     schedule: bool,
 *     unschedule: bool,
 *     publish_now: bool,
 *     retry_publish: bool,
 *     skip: bool,
 *     cancel: bool,
 *     send_to_publishing_queue: bool,
 *     archive_item: bool,
 *     view_details: bool,
 *     debug_to_approved: bool,
 *     debug_to_scheduled: bool,
 *     debug_to_published: bool,
 *     has_content: bool,
 *     has_review: bool,
 *     has_publishing: bool,
 *     has_lifecycle: bool,
 *     has_debug: bool
 * }
 */
final class ContentProjectItemActionsPresenter
{
    /**
     * @param  array<string, mixed>  $row
     * @return ActionFlags
     */
    public static function forRow(array $row): array
    {
        $lifecycle = strtolower((string) ($row['lifecycle'] ?? ''));
        $queue = strtolower((string) ($row['queue_status'] ?? 'none'));
        $genKey = strtolower((string) (($row['generation_badge']['key'] ?? '')));
        $canGenerate = (bool) ($row['can_generate'] ?? false);
        $isGeneratePendingRunnable = (bool) ($row['is_generate_pending_runnable'] ?? false);
        $canRegen = (bool) ($row['can_regen'] ?? false);
        $isImprove = (bool) ($row['is_improve'] ?? false);
        $hasArticle = ! empty($row['article_edit_url']);
        $isStaleGeneration = (bool) ($row['is_generation_stale'] ?? false);
        $isGenuineRunning = (bool) ($row['is_genuinely_running'] ?? false);
        $hasResumableCheckpoint = (bool) ($row['has_resumable_checkpoint'] ?? false);
        $generationStatus = strtolower((string) ($row['generation_status'] ?? ''));
        $generationBlocked = ! empty($row['generation_blocked']);
        $recoveryAction = strtolower(trim((string) ($row['generation_recovery_action'] ?? '')));

        $hasGenerated = in_array($genKey, ['success', 'generated'], true)
            || in_array($lifecycle, ['review', 'approved', 'waiting_publish', 'published'], true);

        $openArticle = $hasArticle;
        $generate = $canGenerate
            && $isGeneratePendingRunnable
            && $genKey === 'pending'
            && $generationStatus === 'pending'
            && ! $generationBlocked;
        $runAgain = (! $isGenuineRunning)
            && ! $generationBlocked
            && (
                $isStaleGeneration
                || $genKey === 'failed'
                || $lifecycle === 'failed'
                || $generationStatus === 'failed'
                || ($canGenerate && $generationStatus === 'failed')
            );
        // Prefer one clear CTA: Generate for never-started; Run again for failed/stale.
        if ($runAgain) {
            $generate = false;
        }

        // ONE smart generation action — covers ready / failed / stale / rewrite+article pending.
        // Do NOT require can_generate alone: rewrite with article often has can_regen but not Generate.
        $availableActions = $row['available_actions'] ?? [];
        if (! is_array($availableActions)) {
            $availableActions = [];
        }
        $canRerunAction = $canRegen
            || in_array('rerun', $availableActions, true)
            || in_array(\Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction::Rerun->value, $availableActions, true);

        $createOrRerun = (! $isGenuineRunning)
            && ! $generationBlocked
            && $lifecycle !== 'archived'
            && (
                $generate
                || $runAgain
                || $canGenerate
                || $canRerunAction
                || $isStaleGeneration
                || $genKey === 'failed'
                || $genKey === 'pending'
                || $lifecycle === 'failed'
                || $generationStatus === 'failed'
                || $generationStatus === 'pending'
            );
        $createOrRerunLabel = (
            $runAgain
            || $isStaleGeneration
            || $canRerunAction
            || $hasArticle
            || $genKey === 'failed'
            || $lifecycle === 'failed'
            || $generationStatus === 'failed'
            || ($canGenerate && ! $isGeneratePendingRunnable)
            || ($genKey === 'pending' && $hasArticle)
        ) ? 'rerun' : 'create';
        // Collapse legacy flags into the smart action (menu renders create_or_rerun only).
        $generate = false;
        $runAgain = false;

        $stopGeneration = $isGenuineRunning && ! $isStaleGeneration;
        $resumeGeneration = $hasResumableCheckpoint && ! $isGenuineRunning && ! $isStaleGeneration && ! $generationBlocked;

        // Capability SoT — when present, overrides status-only heuristics (one recovery CTA).
        $selectExistingArticle = false;
        if ($recoveryAction !== '') {
            if ($recoveryAction === 'active') {
                $createOrRerun = false;
                $resumeGeneration = false;
                $selectExistingArticle = false;
                $stopGeneration = true;
            } elseif ($recoveryAction === 'resume') {
                $resumeGeneration = true;
                $createOrRerun = false;
                $selectExistingArticle = false;
            } elseif ($recoveryAction === 'rerun') {
                $resumeGeneration = false;
                $selectExistingArticle = false;
                $createOrRerun = ! $generationBlocked && $lifecycle !== 'archived';
                $createOrRerunLabel = 'rerun';
            } elseif ($recoveryAction === 'generate') {
                $resumeGeneration = false;
                $selectExistingArticle = false;
                $createOrRerun = ! $generationBlocked && $lifecycle !== 'archived';
                $createOrRerunLabel = 'create';
            } elseif ($recoveryAction === 'select_existing_article') {
                $resumeGeneration = false;
                $createOrRerun = false;
                $selectExistingArticle = ! $generationBlocked && $lifecycle !== 'archived' && ! $isGenuineRunning;
            } elseif ($recoveryAction === 'none') {
                $resumeGeneration = false;
                $createOrRerun = false;
                $selectExistingArticle = false;
            }
        } elseif ($resumeGeneration) {
            // Legacy: failed+resumable prefers Resume over smart rerun.
            $createOrRerun = false;
        }

        $unlinked = ! $hasArticle;
        $rowType = strtolower(trim((string) ($row['type'] ?? $row['type_label'] ?? '')));
        $requiresExistingType = in_array($rowType, ['rewrite', 'improve'], true)
            || $recoveryAction === 'select_existing_article';
        if (
            $unlinked
            && ! $isGenuineRunning
            && ! $generationBlocked
            && $lifecycle !== 'archived'
            && ! $requiresExistingType
            && ! in_array($recoveryAction, ['active', 'resume'], true)
        ) {
            $selectExistingArticle = true;
            $createOrRerun = true;
            if ($createOrRerunLabel !== 'rerun') {
                $createOrRerunLabel = 'create';
            }
        }

        $regenOutline = $canRegen && ! $generationBlocked;
        $regenArticle = $canRegen && ! $generationBlocked;
        $restartWithKeyword = $canRegen && ! $generationBlocked && ! $isGenuineRunning;
        $regenImage = $canRegen && $hasArticle && ! $generationBlocked;
        $retryFailed = false;
        $debugRerunFromStart = false;
        $improveNote = $isImprove;
        $message = trim((string) ($row['message'] ?? ''));
        $acknowledgeError = $hasArticle
            && (! $isGenuineRunning)
            && (
                $genKey === 'failed'
                || $generationStatus === 'failed'
                || $message !== ''
            );
        // Content already usable (published/review path) — prefer soft-clear over AI resume CTA.
        $preferAcknowledgeError = $acknowledgeError
            && $hasArticle
            && in_array($lifecycle, ['published', 'approved', 'review', 'waiting_publish'], true);

        $skipGeneration = ! $generationBlocked
            && ! $isGenuineRunning
            && (
                $genKey === 'failed'
                || $lifecycle === 'failed'
                || $generationStatus === 'failed'
                || $message !== ''
                || ($canGenerate && $generationStatus === 'pending')
            );
        $allowGeneration = $generationBlocked && ! $isGenuineRunning;

        $startReview = $hasGenerated && in_array($lifecycle, ['draft', 'generating'], true);
        $approve = $lifecycle === 'review';

        // Publishing Queue module ownership — Schedule/Unschedule/Publish Now/Retry/
        // Skip/Cancel are no longer offered from the Content Project ops presenter.
        // The only handoff action here is send_to_publishing_queue.
        $schedule = false;
        $unschedule = false;
        $publishNow = false;
        $retryPublish = false;
        $skip = false;
        $cancel = false;
        $sendToPublishingQueue = array_key_exists('can_send_to_publishing_queue', $row)
            ? (bool) $row['can_send_to_publishing_queue']
            : PublishingQueueHandoffEligibility::canSend($row);

        $archiveItem = $lifecycle !== 'archived'
            && $genKey !== 'running'
            && ! $isGenuineRunning
            && $queue !== 'processing';

        $debugEnabled = false;
        try {
            $debugEnabled = \Omnichannel\Addons\Seo\Support\SeoAccessControl::canDebugContentProjectLifecycle();
        } catch (\Throwable) {
            $debugEnabled = false;
        }

        $lifecycleBucket = match ($lifecycle) {
            'waiting_publish' => 'scheduled',
            default => $lifecycle,
        };
        $debugToApproved = $debugEnabled && in_array($lifecycleBucket, ['scheduled', 'published'], true);
        $debugToScheduled = $debugEnabled && in_array($lifecycleBucket, ['approved', 'published'], true);
        $debugToPublished = $debugEnabled && in_array($lifecycleBucket, ['approved', 'scheduled'], true);

        // «improve» is manual-only: never expose generation / regeneration / skip-generation CTAs.
        if ($isImprove) {
            $createOrRerun = false;
            $createOrRerunLabel = '';
            $stopGeneration = false;
            $resumeGeneration = false;
            $selectExistingArticle = false;
            $regenOutline = false;
            $regenArticle = false;
            $restartWithKeyword = false;
            $regenImage = false;
            $skipGeneration = false;
            $allowGeneration = false;
            $startReview = false;
            $approve = false;
        }

        $hasContent = $openArticle || $createOrRerun || $stopGeneration || $resumeGeneration
            || $selectExistingArticle
            || $regenOutline || $regenArticle || $restartWithKeyword || $regenImage || $improveNote
            || $acknowledgeError || $skipGeneration || $allowGeneration;
        $hasReview = $startReview || $approve;
        $hasPublishing = $sendToPublishingQueue;
        $skipSeoAudit = (bool) ($row['can_skip_seo_audit'] ?? false);
        $hasLifecycle = $archiveItem || $skipSeoAudit;
        $hasDebug = $debugToApproved || $debugToScheduled || $debugToPublished;

        $confirmRecreateMissingArticle = $createOrRerun
            && ! empty($row['stale_missing_article']);

        $flags = [
            'open_article' => $openArticle,
            'generate' => false,
            'run_again' => false,
            'create_or_rerun' => $createOrRerun,
            'create_or_rerun_label' => $createOrRerunLabel,
            'run_generation_bulk' => $createOrRerun && $createOrRerunLabel === 'create',
            'confirm_recreate_missing_article' => $confirmRecreateMissingArticle,
            'stop_generation' => $stopGeneration,
            'resume_generation' => $resumeGeneration,
            'select_existing_article' => $selectExistingArticle,
            'regen_outline' => $regenOutline,
            'regen_article' => $regenArticle,
            'restart_with_keyword' => $restartWithKeyword,
            'regen_image' => $regenImage,
            'retry_failed_step' => false,
            'debug_rerun_from_start' => false,
            'acknowledge_error' => $acknowledgeError,
            'prefer_acknowledge_error' => $preferAcknowledgeError,
            'skip_generation' => $skipGeneration,
            'allow_generation' => $allowGeneration,
            'improve_note' => $improveNote,
            'start_review' => $startReview,
            'approve' => $approve,
            'schedule' => false,
            'unschedule' => false,
            'publish_now' => false,
            'retry_publish' => false,
            'skip' => false,
            'cancel' => false,
            'send_to_publishing_queue' => $sendToPublishingQueue,
            'send_to_publishing_queue_warn_cm' => PublishingQueueHandoffEligibility::needsContentManagerWarning($row),
            'archive_item' => $archiveItem,
            'remove_from_draft' => $archiveItem && (($row['project_is_draft'] ?? false) === true),
            'skip_seo_audit' => (bool) ($row['can_skip_seo_audit'] ?? false),
            'check_index' => trim((string) ($row['check_index_url'] ?? '')) !== '',
            'view_details' => true,
            'debug_to_approved' => $debugToApproved,
            'debug_to_scheduled' => $debugToScheduled,
            'debug_to_published' => $debugToPublished,
            'has_content' => $hasContent,
            'has_review' => $hasReview,
            'has_publishing' => $hasPublishing,
            'has_lifecycle' => $hasLifecycle,
            'has_debug' => $hasDebug,
        ];

        return self::applyWorkflowCapabilityGate($flags);
    }

    /**
     * Content Manager: open/edit only. Planner-equivalent keeps full workflow actions.
     *
     * @param  ActionFlags  $flags
     * @return ActionFlags
     */
    private static function applyWorkflowCapabilityGate(array $flags): array
    {
        // Pure PHPUnit / unbound auth container — keep state-derived flags.
        // HTTP/Livewire still fail-closed via SeoAccessControl on commands.
        if (! self::hasAuthenticatedSession()) {
            return $flags;
        }

        if (\Omnichannel\Addons\Seo\Support\SeoAccessControl::canManageContentProjectWorkflow()) {
            return $flags;
        }

        $flags['generate'] = false;
        $flags['run_again'] = false;
        $flags['create_or_rerun'] = false;
        $flags['run_generation_bulk'] = false;
        $flags['confirm_recreate_missing_article'] = false;
        $flags['stop_generation'] = false;
        $flags['resume_generation'] = false;
        $flags['select_existing_article'] = false;
        $flags['regen_outline'] = false;
        $flags['regen_article'] = false;
        $flags['restart_with_keyword'] = false;
        $flags['regen_image'] = false;
        $flags['retry_failed_step'] = false;
        $flags['debug_rerun_from_start'] = false;
        $flags['skip_generation'] = false;
        $flags['allow_generation'] = false;
        $flags['start_review'] = false;
        $flags['approve'] = false;
        $flags['schedule'] = false;
        $flags['unschedule'] = false;
        $flags['publish_now'] = false;
        $flags['retry_publish'] = false;
        $flags['skip'] = false;
        $flags['cancel'] = false;
        $flags['send_to_publishing_queue'] = false;
        $flags['archive_item'] = false;
        $flags['debug_to_approved'] = false;
        $flags['debug_to_scheduled'] = false;
        $flags['debug_to_published'] = false;
        $flags['has_review'] = false;
        $flags['has_publishing'] = false;
        $flags['has_lifecycle'] = false;
        $flags['has_debug'] = false;
        $flags['has_content'] = $flags['open_article'] || $flags['acknowledge_error'];

        return $flags;
    }

    private static function hasAuthenticatedSession(): bool
    {
        try {
            return auth()->check();
        } catch (\Throwable) {
            return false;
        }
    }
}
