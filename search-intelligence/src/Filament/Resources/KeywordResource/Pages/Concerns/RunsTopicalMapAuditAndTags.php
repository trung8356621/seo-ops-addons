<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use App\Models\Site;
use Filament\Notifications\Notification;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\TopicalMapAuditHistoryLinker;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditStatusService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Manual site-level AI Audit & Tags — confirmation required, never auto after Recluster.
 */
trait RunsTopicalMapAuditAndTags
{
    public bool $confirmAiAudit = false;

    public bool $aiAuditRunning = false;

    /** @var array<string, mixed>|null */
    public ?array $aiAuditSnapshot = null;

    public function canRunAiAuditAndTags(): bool
    {
        $siteId = $this->resolveKeywordWorkspaceSiteId();
        if ($siteId === null || $siteId <= 0) {
            return false;
        }
        if (! SeoAccessControl::canMutateInSeoPanel() || ! SeoAccessControl::canAccessSite($siteId)) {
            return false;
        }
        if (method_exists($this, 'isTopicMutationLocked') && $this->isTopicMutationLocked()) {
            return false;
        }
        if ($this->aiAuditRunning) {
            return false;
        }

        $snapshot = $this->aiAuditStatusSnapshot();

        return (bool) ($snapshot['can_run'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function aiAuditStatusSnapshot(): array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0) {
            return [
                'status' => TopicalMapAuditStatusService::STATUS_NEVER_RUN,
                'can_run' => false,
                'site_id' => 0,
                'site_domain' => '',
                'topic_count' => 0,
                'assigned_keywords' => 0,
                'unassigned_keywords' => 0,
                'existing_tags' => 0,
                'topics_with_tags' => 0,
                'untagged_topics' => 0,
                'source_updated_at' => null,
                'last_ai_run_at' => null,
                'last_prompt_result_id' => null,
            ];
        }

        if (is_array($this->aiAuditSnapshot) && (int) ($this->aiAuditSnapshot['site_id'] ?? 0) === $siteId) {
            return $this->aiAuditSnapshot;
        }

        $domain = '';
        $site = Site::query()->find($siteId);
        if ($site instanceof Site) {
            $domain = (string) ($site->domain ?? '');
        }

        $this->aiAuditSnapshot = app(TopicalMapAuditStatusService::class)->snapshot($siteId, $domain);

        return $this->aiAuditSnapshot;
    }

    public function refreshAiAuditSnapshot(): void
    {
        $this->aiAuditSnapshot = null;
        $this->aiAuditStatusSnapshot();
    }

    public function aiAuditButtonLabel(): string
    {
        $status = (string) ($this->aiAuditStatusSnapshot()['status'] ?? TopicalMapAuditStatusService::STATUS_NEVER_RUN);

        return match ($status) {
            TopicalMapAuditStatusService::STATUS_CURRENT => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_current'),
            TopicalMapAuditStatusService::STATUS_STALE => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_stale'),
            default => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_action'),
        };
    }

    public function beginConfirmAiAudit(): void
    {
        if (! $this->canRunAiAuditAndTags()) {
            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.ai_audit_tags_disabled'))
                ->warning()
                ->send();

            return;
        }
        $this->refreshAiAuditSnapshot();
        $this->confirmAiAudit = true;
    }

    public function cancelConfirmAiAudit(): void
    {
        $this->confirmAiAudit = false;
    }

    public function confirmRunAiAuditAndTags(): void
    {
        $this->confirmAiAudit = false;
        if (! $this->canRunAiAuditAndTags()) {
            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.ai_audit_tags_disabled'))
                ->warning()
                ->send();

            return;
        }

        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        $this->aiAuditRunning = true;

        try {
            $actorId = auth()->id();
            $actor = is_numeric($actorId) ? (int) $actorId : null;
            $result = app(TopicalMapAuditService::class)->audit($siteId, $actor);

            try {
                app(TopicalMapAuditHistoryLinker::class)->linkFromAuditResult($siteId, $actor, $result);
            } catch (Throwable $linkError) {
                report($linkError);
            }

            $this->refreshAiAuditSnapshot();

            if (! ($result['ok'] ?? false)) {
                Notification::make()
                    ->title((string) __('seo-content-ai::filament.keyword.topical_map_audit_failed'))
                    ->body((string) ($result['message'] ?? ''))
                    ->danger()
                    ->send();

                return;
            }

            $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
            $tagApply = is_array($result['tag_apply'] ?? null) ? $result['tag_apply'] : [];
            $findings = is_array($payload['findings'] ?? null) ? count($payload['findings']) : 0;
            $opps = is_array($payload['opportunities'] ?? null) ? count($payload['opportunities']) : 0;

            $body = __('seo-content-ai::filament.keyword.ai_audit_tags_summary', [
                'findings' => $findings,
                'opportunities' => $opps,
                'tags' => (int) ($tagApply['tag_count'] ?? 0),
                'tagged' => (int) ($tagApply['topic_tagged_count'] ?? 0),
                'topics' => (int) ($this->aiAuditStatusSnapshot()['topic_count'] ?? 0),
                'ai_assignments' => (int) ($tagApply['ai_assignment_count'] ?? 0),
                'untagged' => (int) ($tagApply['untagged_topics'] ?? 0),
            ]);

            if (! ($tagApply['ok'] ?? true)) {
                Notification::make()
                    ->title((string) __('seo-content-ai::filament.keyword.ai_audit_tags_apply_failed'))
                    ->body((string) ($result['message'] ?? $tagApply['error'] ?? ''))
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.ai_audit_tags_complete'))
                ->body((string) $body)
                ->success()
                ->send();

            if (method_exists($this, 'refreshClusterSummaryCounters')) {
                $this->refreshClusterSummaryCounters();
            }
        } catch (Throwable $e) {
            Notification::make()
                ->title((string) __('seo-content-ai::filament.keyword.topical_map_audit_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->aiAuditRunning = false;
        }
    }
}
