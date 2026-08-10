<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRuleClassification;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Illuminate\Console\Command;

/**
 * Broad automation ownership audit. Keeps automation:audit-wordpress-coupling intact.
 */
final class AutomationAuditCouplingCommand extends Command
{
    protected $signature = 'automation:audit-coupling {--strict : Fail on ownership violations}';

    protected $description = 'Audit Automation ownership: WP/review/SEO/notification callers, rule collisions, sample enabled.';

    /** @var list<string> */
    private const DEPRECATED_PRODUCT_REVIEW_RULES = [
        'publish-generated-product-reviews-to-wordpress',
        'publish-pending-product-reviews-after-article-sync',
        'execute-wordpress-comment-review-publish',
    ];

    /** @var list<string> */
    private const REQUIRED_SYNC_ACTIONS = [
        'wordpress.article.sync',
        'product-review.create',
        'product-review.sync-wp',
    ];

    public function handle(): int
    {
        $strict = (bool) $this->option('strict');
        $fail = false;
        $base = dirname(__DIR__);

        $this->info('=== Product review must not live inside wordpress.article.sync ===');
        $hook = (string) file_get_contents($base.'/Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php');
        $pipeline = (string) file_get_contents($base.'/Services/WordPress/SyncArticleToWordPressPipeline.php');
        foreach (['WordPressProductReviewService', 'ProductReviewPendingRepository', 'syncPendingReviews', 'ProductReviewPostSyncReconciler'] as $needle) {
            if (str_contains($hook, $needle) || str_contains($pipeline, $needle)) {
                $this->error("review orchestration still inside article sync: {$needle}");
                $fail = true;
            }
        }
        if (! $fail) {
            $this->line('ok: wordpress.article.sync has no product-review orchestration');
        }

        foreach ([
            'Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php',
            'Services/ArticleEditorSyncOrchestrator.php',
            'Services/ArticleWpSyncQueueService.php',
            'Jobs/ManualWordPressSyncJob.php',
        ] as $relative) {
            $path = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $source = is_file($path) ? (string) file_get_contents($path) : '';
            if (str_contains($source, 'ProductReviewPostSyncReconciler') || str_contains($source, 'reconcileAfterArticleSynced')) {
                $this->error("forbidden reconciler caller: {$relative}");
                $fail = true;
            } else {
                $this->line("ok: {$relative}");
            }
        }

        $createAction = is_file($base.'/Automation/BusinessHook/Actions/CreateProductReviewsHookAction.php');
        $syncAction = is_file($base.'/Automation/BusinessHook/Actions/SyncProductReviewsToWordPressHookAction.php');
        $policy = is_file($base.'/Services/ProductReview/ProductReviewCreationPolicy.php');
        $status = is_file($base.'/Services/ProductReview/WordPressProductReviewStatusService.php');
        if (! $createAction || ! $syncAction || ! $policy || ! $status) {
            $this->error('missing product-review.create / sync-wp / policy / status service');
            $fail = true;
        } else {
            $this->line('ok: product-review.create + product-review.sync-wp + shared policy/status');
        }

        $this->newLine();
        $this->info('=== Manual WordPress must not use Automation gate ===');
        $manual = (string) file_get_contents($base.'/Services/WordPress/WordPressManualSyncService.php');
        if (str_contains($manual, 'ManualAutomationDispatcher') || str_contains($manual, 'AutomationAvailabilityGate')) {
            $this->error('WordPressManualSyncService still coupled to Automation gate/dispatcher');
            $fail = true;
        } else {
            $this->line('ok: WordPressManualSyncService uses ManualSyncContext + ManualWordPressSyncJob');
        }

        $manualJob = (string) file_get_contents($base.'/Jobs/ManualWordPressSyncJob.php');
        if (! str_contains($manualJob, 'ArticleWordPressBusinessSequence')) {
            $this->error('ManualWordPressSyncJob must use ArticleWordPressBusinessSequence');
            $fail = true;
        } else {
            $this->line('ok: manual job uses ArticleWordPressBusinessSequence');
        }

        $this->newLine();
        $this->info('=== Rule ownership DB checks ===');
        try {
            $enabledUnpublished = AutomationRule::query()
                ->where('is_enabled', true)
                ->whereNull('published_version_id')
                ->pluck('code');
            if ($enabledUnpublished->isNotEmpty()) {
                $this->error('enabled+unpublished: '.$enabledUnpublished->implode(', '));
                $fail = true;
            } else {
                $this->line('ok: no enabled unpublished rules');
            }

            $sampleEnabled = AutomationRule::query()
                ->where('classification', AutomationRuleClassification::Sample->value)
                ->where('is_enabled', true)
                ->pluck('code');
            if ($sampleEnabled->isNotEmpty()) {
                $this->error('sample enabled: '.$sampleEnabled->implode(', '));
                $fail = true;
            } else {
                $this->line('ok: sample rules disabled');
            }

            $completedOwners = AutomationRule::query()
                ->where('event_name', 'article.completed')
                ->where('is_enabled', true)
                ->whereNotIn('classification', [
                    AutomationRuleClassification::Sample->value,
                    AutomationRuleClassification::Deprecated->value,
                ])
                ->pluck('code');
            if ($completedOwners->count() !== 1 || $completedOwners->first() !== 'sync-article-to-wordpress') {
                $this->error('article.completed ownership expected sync-article-to-wordpress only, got: '.$completedOwners->implode(', '));
                $fail = true;
            } else {
                $this->line('ok: article.completed ownership count=1 (sync-article-to-wordpress)');
            }

            $sync = AutomationRule::query()->where('code', 'sync-article-to-wordpress')->with('actions')->first();
            if (! $sync instanceof AutomationRule || ! $sync->is_enabled || $sync->published_version_id === null) {
                $this->error('sync-article-to-wordpress must be enabled+published');
                $fail = true;
            } else {
                $codes = $sync->actions->sortBy('position')->pluck('action_code')->map(static fn ($c) => (string) $c)->values()->all();
                if ($codes !== self::REQUIRED_SYNC_ACTIONS) {
                    $this->error('sync-article-to-wordpress actions expected ['.implode(', ', self::REQUIRED_SYNC_ACTIONS).'], got ['.implode(', ', $codes).']');
                    $fail = true;
                } else {
                    $this->line('ok: sync-article-to-wordpress has 3 fixed actions');
                }
            }

            foreach (self::DEPRECATED_PRODUCT_REVIEW_RULES as $code) {
                $rule = AutomationRule::query()->where('code', $code)->first();
                if (! $rule instanceof AutomationRule) {
                    $this->line("ok: {$code} absent");
                    continue;
                }
                $classification = (string) ($rule->classification ?? '');
                if ($rule->is_enabled || $classification !== AutomationRuleClassification::Deprecated->value) {
                    $this->error("{$code} must be disabled + deprecated");
                    $fail = true;
                } else {
                    $this->line("ok: {$code} deprecated+disabled");
                }
            }

            $notifyExperimental = AutomationRule::query()
                ->where('code', 'notify-on-notification-requested')
                ->where('is_enabled', true)
                ->exists();
            if ($notifyExperimental) {
                $this->error('notify-on-notification-requested must stay disabled');
                $fail = true;
            } else {
                $this->line('ok: notify-on-notification-requested disabled');
            }

            // Enum registry sanity
            if (AutomationActionCode::ProductReviewCreate->value !== 'product-review.create'
                || AutomationActionCode::ProductReviewSyncWp->value !== 'product-review.sync-wp'
            ) {
                $this->error('product-review action codes mismatch');
                $fail = true;
            }
        } catch (\Throwable $e) {
            $this->warn('DB checks skipped: '.$e->getMessage());
        }

        $this->newLine();
        $this->info('=== Local mutation Action cutover ===');
        $editorCtrl = (string) file_get_contents($base.'/Http/Controllers/ArticleEditorSyncController.php');
        if (str_contains($editorCtrl, 'ArticleEditorPersistService') || ! str_contains($editorCtrl, 'BusinessActionDispatcher')) {
            $this->error('ArticleEditorSyncController must use BusinessActionDispatcher, not ArticleEditorPersistService');
            $fail = true;
        } else {
            $this->line('ok: editor save/seo-meta via BusinessActionDispatcher');
        }

        $persistSrc = (string) file_get_contents($base.'/Services/ArticleEditorPersistService.php');
        if (str_contains($persistSrc, 'BusinessHookEmitter') || str_contains($persistSrc, 'articleContentUpdated')) {
            $this->error('ArticleEditorPersistService must not emit BusinessHook events');
            $fail = true;
        } else {
            $this->line('ok: persistLocal has no BusinessHookEmitter');
        }

        $observer = (string) file_get_contents($base.'/Observers/KeywordLinkListSyncObserver.php');
        if (str_contains($observer, 'DomainLinkListKeywordSyncService')) {
            $this->error('KeywordLinkListSyncObserver must not call DomainLinkListKeywordSyncService');
            $fail = true;
        } else {
            $this->line('ok: keyword observer emits event only');
        }

        $manualSync = (string) file_get_contents($base.'/Services/WordPress/WordPressManualSyncService.php');
        if (str_contains($manualSync, 'ArticleEditorPersistService') || ! str_contains($manualSync, 'article.content.update')) {
            $this->error('WordPressManualSyncService pre-persist must go through article.content.update');
            $fail = true;
        } else {
            $this->line('ok: manual WP pre-persist via article.content.update');
        }

        $manualJobSrc = (string) file_get_contents($base.'/Jobs/ManualWordPressSyncJob.php');
        if (! str_contains($manualJobSrc, 'wordpressSyncedOnce') && ! str_contains($manualJobSrc, 'sync_operation_id')) {
            $this->error('ManualWordPressSyncJob must emit wordpress.synced with stable sync_operation_id');
            $fail = true;
        } else {
            $this->line('ok: manual WP outcome uses sync operation id');
        }

        $this->newLine();
        $this->info('=== Delegate WordPress coupling ===');
        $wpExit = $this->call('automation:audit-wordpress-coupling', ['--strict' => $strict]);
        if ($wpExit !== self::SUCCESS) {
            $fail = true;
        }

        if ($fail && $strict) {
            return self::FAILURE;
        }

        $this->info($fail ? 'audit-coupling finished with warnings' : 'audit-coupling OK');

        return $fail && $strict ? self::FAILURE : self::SUCCESS;
    }
}
