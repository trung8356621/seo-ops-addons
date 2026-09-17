<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns;

use Filament\Notifications\Notification;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDissolveService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;

trait DissolvesTopics
{
    public function canDissolveTopic(): bool
    {
        return $this->hasTopicMutationPermission() && ! $this->isTopicMutationLocked();
    }

    /**
     * @return array{ok: bool, topic_id?: int, label?: string, affected_count?: int, error?: string}
     */
    public function dissolveTopic(int $topicId): array
    {
        $siteId = (int) $this->resolveKeywordWorkspaceSiteId();
        if ($siteId <= 0 || $topicId <= 0) {
            return ['ok' => false, 'error' => 'invalid_args'];
        }
        if (! $this->canDissolveTopic()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.topic_dissolve_failed'))
                ->danger()
                ->send();

            return ['ok' => false, 'error' => 'denied'];
        }
        if (TopicReclusterUiState::isMutationLocked($siteId)) {
            return ['ok' => false, 'error' => 'recluster_locked'];
        }

        $result = app(TopicDissolveService::class)->dissolve($siteId, $topicId);
        if (! $result['ok']) {
            $title = match ($result['error']) {
                'topic_locked' => __('seo-content-ai::filament.keyword.topic_dissolve_blocked_locked'),
                default => __('seo-content-ai::filament.keyword.topic_dissolve_failed'),
            };
            Notification::make()->title($title)->danger()->send();

            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'failed')];
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_dissolve_success_title', ['label' => '#'.$topicId]))
            ->body(__('seo-content-ai::filament.keyword.topic_dissolve_success_body', [
                'count' => (int) $result['deleted_memberships'],
            ]))
            ->success()
            ->send();

        if (method_exists($this, 'refreshClusterSummaryCounters')) {
            $this->refreshClusterSummaryCounters();
        }

        return [
            'ok' => true,
            'topic_id' => $topicId,
            'affected_count' => (int) $result['deleted_memberships'],
        ];
    }
}
