<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;

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
     * @return list<array{value: string, label: string, id?: int, email?: string, name?: string}>
     */
    public function suggestMembers(AgentWorkspaceContext $context, string $query = '', bool $availableOnly = false): array
    {
        unset($context, $availableOnly);

        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
        $needle = strtolower(trim($query));

        $users = User::query()
            ->where('parent_id', $ownerId)
            ->where('role', User::ROLE_STAFF)
            ->orderBy('id')
            ->limit(50)
            ->get();

        $out = [];
        foreach ($users as $user) {
            $id = (int) $user->id;
            $email = (string) $user->email;
            $name = (string) ($user->display_name ?? $user->name ?? $email);
            $label = '#'.$id.' · '.$email.' · '.$name;
            if ($needle !== ''
                && ! str_contains((string) $id, $needle)
                && ! str_contains(strtolower($email), $needle)
                && ! str_contains(strtolower($name), $needle)) {
                continue;
            }
            $out[] = [
                'value' => (string) $id,
                'label' => $label,
                'id' => $id,
                'email' => $email,
                'name' => $name,
            ];
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
