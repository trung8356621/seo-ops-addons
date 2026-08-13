<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Read-only suggestions for CLI argument autocomplete (UX layer).
 */
final class AgentCliArgumentSuggestService
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function suggestProjects(AgentWorkspaceContext $context, string $query = ''): array
    {
        $siteId = (int) ($context->siteId ?? 0);
        if ($siteId <= 0) {
            return [];
        }

        $options = ArticleResource::contentProjectOptions($siteId);
        $needle = strtolower(trim($query));
        $out = [];

        foreach ($options as $id => $label) {
            $value = (string) $id;
            $labelStr = '#'.$value.' · '.(string) $label;
            if ($needle !== ''
                && ! str_contains(strtolower($value), $needle)
                && ! str_contains(strtolower($labelStr), $needle)) {
                continue;
            }
            $out[] = [
                'value' => $value,
                'label' => $labelStr,
            ];
        }

        return array_slice($out, 0, 20);
    }

    /**
     * Eligible Content Project assignees from canonical staff availability rules.
     *
     * @return list<array{value: string, label: string, id?: int, email?: string, name?: string, available?: bool}>
     */
    public function suggestMembers(AgentWorkspaceContext $context, string $query = '', bool $availableOnly = false): array
    {
        unset($context);

        $service = app(ContentProjectStaffAvailabilityService::class);
        $needle = strtolower(trim($query));

        if ($availableOnly) {
            $month = ContentProjectMonthContext::normalize(now()->format('Y-m-d'));
            $users = $service->unassignedStaffQuery($month, $needle !== '' ? $query : null)
                ->limit(50)
                ->get();
        } else {
            $queryBuilder = $service->baseAssignableStaffQuery();
            if ($needle !== '') {
                $like = '%'.$needle.'%';
                $queryBuilder->where(function ($builder) use ($like, $needle): void {
                    $builder
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                    if (ctype_digit($needle)) {
                        $builder->orWhere('id', (int) $needle);
                    }
                });
            }
            $users = $queryBuilder->limit(50)->get();
        }

        $out = [];
        foreach ($users as $user) {
            $id = (int) $user->id;
            $email = (string) $user->email;
            $name = (string) ($user->display_name ?? $user->name ?? $email);
            $label = '#'.$id.' · '.$email.' · '.$name;
            $row = [
                'value' => (string) $id,
                'label' => $label,
                'id' => $id,
                'email' => $email,
                'name' => $name,
            ];
            if ($availableOnly) {
                $row['available'] = true;
            }
            $out[] = $row;
        }

        return array_slice($out, 0, 20);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function suggest(string $type, AgentWorkspaceContext $context, string $query = ''): array
    {
        return match ($type) {
            'project' => $this->suggestProjects($context, $query),
            'member' => $this->suggestMembers($context, $query),
            default => [],
        };
    }
}
