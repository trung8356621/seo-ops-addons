<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Console\Command;

/**
 * Repair executions failed sau WordPress success nhưng hậu xử lý outcome bị hiểu nhầm.
 * Chỉ repair khi có bằng chứng remote post + mapping.
 */
final class AutomationRepairWordpressExecutionsCommand extends Command
{
    protected $signature = 'automation:repair-wordpress-executions
        {--execution= : Specific automation_executions.id}
        {--dry-run : List candidates without updating}';

    protected $description = 'Repair false WORDPRESS_SYNC_FAILED when WP post already mapped and evidence proves post-WP failure.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $executionId = (int) ($this->option('execution') ?? 0);

        $query = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed->value)
            ->where(function ($q): void {
                $q->where('action_code', 'wordpress.article.sync')
                    ->orWhereHas('rule', static fn ($r) => $r->where('code', 'sync-article-to-wordpress'));
            })
            ->whereIn('error_code', [
                'WORDPRESS_SYNC_FAILED',
                'WORDPRESS_SYNC_FORBIDDEN_ROLE',
            ])
            ->orderByDesc('id');

        if ($executionId > 0) {
            $query->whereKey($executionId);
        }

        $candidates = $query->limit(100)->get();
        if ($candidates->isEmpty()) {
            $this->info('No candidate executions.');

            return self::SUCCESS;
        }

        $repaired = 0;
        foreach ($candidates as $execution) {
            $evidence = $this->evidence($execution);
            if ($evidence === null) {
                $this->line("skip #{$execution->id}: insufficient evidence");
                continue;
            }

            $this->line(sprintf(
                '%s #%s article=%s wp_post_id=%s stage=%s',
                $dryRun ? 'DRY' : 'REPAIR',
                $execution->id,
                $evidence['article_id'],
                $evidence['wp_post_id'],
                $evidence['failed_stage'],
            ));

            if ($dryRun) {
                continue;
            }

            $execution->forceFill([
                'status' => AutomationExecutionStatus::Completed->value,
                'error_code' => null,
                'error_message' => null,
                'context' => array_merge($execution->context ?? [], [
                    'repaired_at' => now()->toIso8601String(),
                    'repair_reason' => 'false_wordpress_sync_failed_with_remote_mapping',
                    'repair_evidence' => $evidence,
                ]),
            ])->save();

            $execution->actionExecutions()
                ->where('status', 'failed')
                ->where('action_code', 'wordpress.article.sync')
                ->update([
                    'status' => 'completed',
                    'error_code' => null,
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            $repaired++;
        }

        $this->info($dryRun ? 'Dry-run complete.' : "Repaired {$repaired} execution(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{article_id: int, wp_post_id: int, failed_stage: string}|null
     */
    private function evidence(AutomationExecution $execution): ?array
    {
        $action = $execution->actionExecutions()
            ->where('action_code', 'wordpress.article.sync')
            ->orderByDesc('id')
            ->first();

        $input = is_array($action?->input_snapshot) ? $action->input_snapshot : [];
        $output = is_array($action?->output_snapshot) ? $action->output_snapshot : [];
        $articleId = (int) ($input['article_id'] ?? $output['article_id'] ?? 0);
        if ($articleId <= 0) {
            return null;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            // Không có remote mapping — đây là API/permission fail thật, không repair.
            return null;
        }

        $failedStage = (string) ($output['failed_stage'] ?? '');
        $message = (string) ($action?->error_message ?? $execution->error_message ?? '');

        // Chỉ repair khi lỗi rõ là hậu xử lý / permission gate sau khi đã có mapping,
        // hoặc message thuộc outcome/permission không phải HTTP.
        $isPermissionGate = str_contains($message, 'Quản lý nội dung')
            || ($execution->error_code === 'WORDPRESS_SYNC_FORBIDDEN_ROLE')
            || $failedStage === 'permission_gate';

        $isOutcomeNoise = str_contains(strtolower($message), 'automation_rule_not_found')
            || str_contains(strtolower($message), 'skipped_no_rule');

        if (! $isPermissionGate && ! $isOutcomeNoise) {
            // Có mapping nhưng lỗi HTTP/mapping khác — không auto-complete.
            // Permission gate với wp_post_id sẵn (sync cũ) cũng không chứng minh lần này success.
            // Chỉ repair permission-gate khi side-effect ledger có completed attempt.
            return null;
        }

        if ($isPermissionGate) {
            // Permission gate chặn TRƯỚC HTTP — wp_post_id có thể từ lần sync trước.
            // Không repair các execution này (tránh đánh completed sai).
            return null;
        }

        return [
            'article_id' => $articleId,
            'wp_post_id' => $wpPostId,
            'failed_stage' => $failedStage !== '' ? $failedStage : 'unknown',
        ];
    }
}
