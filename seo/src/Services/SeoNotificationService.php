<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy Filament DB notifications for project ownership events.
 * Operational incidents (prompt/generation/runner/WP/site-sync/publishing) MUST use
 * {@see \Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService}.
 */
final class SeoNotificationService
{
    public function notifyProjectOwner(SeoProject $project): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $owner = User::query()->find((int) $project->user_id);
        if (! $owner instanceof User || $owner->seo_role !== User::SEO_ROLE_CONTENT_MANAGER) {
            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.notify_new_project_title'))
            ->body((string) $project->name)
            ->icon('heroicon-o-folder-plus')
            ->actions([
                Action::make('open')
                    ->label(__('seo-content-ai::filament.projects.notify_open_project'))
                    ->url(SeoConnectionContext::panelUrl('content-projects/'.$project->getKey().'/edit'))
                    ->button(),
            ])
            ->sendToDatabase($owner);
    }

    public function notifyProjectOwnerTasksAdded(SeoProject $project, int $addedCount): void
    {
        if ($addedCount <= 0 || ! Schema::hasTable('notifications')) {
            return;
        }

        $owner = User::query()->find((int) $project->user_id);
        if (! $owner instanceof User || $owner->seo_role !== User::SEO_ROLE_CONTENT_MANAGER) {
            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.notify_tasks_added_title'))
            ->body(__('seo-content-ai::filament.projects.notify_tasks_added_body', [
                'project' => (string) $project->name,
                'count' => $addedCount,
            ]))
            ->icon('heroicon-o-document-plus')
            ->actions([
                Action::make('open')
                    ->label(__('seo-content-ai::filament.projects.notify_open_project'))
                    ->url(SeoConnectionContext::panelUrl('content-projects/'.$project->getKey().'/edit'))
                    ->button(),
            ])
            ->sendToDatabase($owner);
    }

    public function notifyPlannersProjectApproved(SeoProject $project, SeoArticle $article): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        foreach ($this->plannersForProject($project) as $planner) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.planner_notified_staff_done'))
                ->body(__('seo-content-ai::filament.article_list.planner_notified_staff_done_body', [
                    'project' => (string) $project->name,
                    'title' => (string) $article->title,
                ]))
                ->icon('heroicon-o-check-badge')
                ->success()
                ->actions([
                    Action::make('open')
                        ->label('Mở bài viết')
                        ->url(SeoConnectionContext::panelUrl('articles/'.$article->getKey().'/edit'))
                        ->button(),
                ])
                ->sendToDatabase($planner);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function plannersForProject(SeoProject $project): Collection
    {
        $projectOwner = User::query()->find((int) $project->user_id);
        if (! $projectOwner instanceof User) {
            return collect();
        }

        $accountOwnerId = $projectOwner->isStaff()
            ? (int) $projectOwner->parent_id
            : (int) $projectOwner->id;

        return User::query()
            ->where('status', User::STATUS_NORMAL)
            ->where('seo_role', User::SEO_ROLE_PLANNER)
            ->where(function ($query) use ($accountOwnerId): void {
                $query->whereKey($accountOwnerId)
                    ->orWhere('parent_id', $accountOwnerId);
            })
            ->get();
    }
}
