<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMcpExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;
use RuntimeException;

trait ExcludesTopicsFromMcp
{
    public function canMutateTopicMcpExclusion(): bool
    {
        return $this->hasTopicMutationPermission() && ! $this->isTopicMutationLocked();
    }

    /**
     * @return array{ok: bool, topic_id?: int, label?: string, error?: string}
     */
    public function excludeTopicFromMcp(int $topicId): array
    {
        return $this->mutateTopicMcpExclusion($topicId, exclude: true);
    }

    /**
     * @return array{ok: bool, topic_id?: int, label?: string, error?: string}
     */
    public function restoreTopicMcp(int $topicId): array
    {
        return $this->mutateTopicMcpExclusion($topicId, exclude: false);
    }

    /**
     * @return array{ok: bool, topic_id?: int, label?: string, error?: string}
     */
    private function mutateTopicMcpExclusion(int $topicId, bool $exclude): array
    {
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        if ($siteId <= 0 || $topicId <= 0) {
            return ['ok' => false, 'error' => 'invalid_args'];
        }
        if (! $this->canMutateTopicMcpExclusion()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_mcp_exclusion_denied'))
                ->danger()
                ->send();

            return ['ok' => false, 'error' => 'denied'];
        }
        if (TopicReclusterUiState::isMutationLocked($siteId)) {
            return ['ok' => false, 'error' => 'recluster_locked'];
        }

        try {
            $service = app(TopicMcpExclusionService::class);
            $result = $exclude
                ? $service->exclude($siteId, $topicId)
                : $service->restore($siteId, $topicId);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            Notification::make()
                ->title(match ($error) {
                    'topic_not_found' => __('seo-content-ai::filament.keyword.topic_mcp_exclusion_not_found'),
                    default => __('seo-content-ai::filament.keyword.topic_mcp_exclusion_failed'),
                })
                ->danger()
                ->send();

            return ['ok' => false, 'error' => $error];
        }

        $label = trim((string) ($result['name'] ?? ''));
        if ($label === '') {
            $label = (string) $topicId;
        }

        if ($exclude) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_mcp_exclude_success_title'))
                ->body(__('seo-content-ai::filament.keyword.topic_mcp_exclude_success_body', ['label' => $label]))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_mcp_restore_success_title'))
                ->body(__('seo-content-ai::filament.keyword.topic_mcp_restore_success_body', ['label' => $label]))
                ->success()
                ->send();
        }

        if (method_exists($this, 'refreshClusterSummaryCounters')) {
            $this->refreshClusterSummaryCounters();
        }

        return [
            'ok' => true,
            'topic_id' => $topicId,
            'label' => $label,
        ];
    }
}
