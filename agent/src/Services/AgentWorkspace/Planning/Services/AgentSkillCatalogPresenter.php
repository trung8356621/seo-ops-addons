<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillAvailabilityService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

/**
 * Presentation-safe skill catalog for AI planning prompts.
 * Source: AgentSkillRegistry + availability — never hard-coded skill lists.
 */
final class AgentSkillCatalogPresenter
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentSkillAvailabilityService $availability,
        private readonly int $maxSkills = 12,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function present(AgentWorkspaceContext $context, string $message = ''): array
    {
        $candidates = $this->skills->all(includeHidden: false);
        $scored = [];

        foreach ($candidates as $skill) {
            if ($skill->isHidden || $skill->isComingSoon) {
                continue;
            }
            $avail = $this->availability->resolve($skill, $context->toAvailabilityContext());
            if ($avail->status === 'hidden' || $avail->status === 'permission_denied') {
                continue;
            }

            $score = $this->relevanceScore($skill, $message, $context);
            $scored[] = ['score' => $score, 'row' => $this->toRow($skill, $avail->usable, $avail->status)];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, max(1, $this->maxSkills));

        return array_map(static fn (array $row): array => $row['row'], $top);
    }

    /**
     * @return list<string>
     */
    public function keys(AgentWorkspaceContext $context, string $message = ''): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) ($row['key'] ?? ''),
            $this->present($context, $message),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(AgentSkillDefinition $skill, bool $available, string $status): array
    {
        $required = [];
        $optional = [];
        foreach ($skill->formSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (($field['required'] ?? false) === true) {
                $required[] = $key;
            } else {
                $optional[] = $key;
            }
        }

        $mode = str_contains($skill->capability, ':write')
            || str_contains(implode(',', $skill->requiredScopes), ':write')
            || in_array($skill->confirmationPolicy, ['preview', 'confirm', 'destructive'], true)
            ? 'write'
            : 'read';

        return [
            'key' => $skill->key,
            'command' => $skill->slashCommand,
            'name' => $skill->name,
            'description' => $skill->description,
            'mode' => $mode,
            'available' => $available,
            'availability_status' => $status,
            'required_inputs' => $required,
            'optional_inputs' => $optional,
            'confirmation' => $skill->confirmationPolicy,
            'context_requirements' => $skill->availabilityPolicy['requires_context'] ?? [],
        ];
    }

    private function relevanceScore(AgentSkillDefinition $skill, string $message, AgentWorkspaceContext $context): float
    {
        $score = $skill->isFeatured ? 2.0 : 0.0;
        $score += max(0, 50 - $skill->sortOrder) / 50;

        $needle = mb_strtolower(trim($message));
        if ($needle !== '') {
            $hay = mb_strtolower(implode(' ', [
                $skill->key,
                $skill->name,
                $skill->description,
                $skill->slashCommand,
                implode(' ', $skill->examplePrompts),
            ]));
            foreach (preg_split('/\s+/u', $needle) ?: [] as $token) {
                if (mb_strlen($token) < 3) {
                    continue;
                }
                if (str_contains($hay, $token)) {
                    $score += 1.5;
                }
            }
        }

        $requires = $skill->availabilityPolicy['requires_context'] ?? [];
        if (is_array($requires)) {
            foreach ($requires as $req) {
                if ($req === 'project_ref' && $context->projectRef) {
                    $score += 0.5;
                }
                if ($req === 'workspace_ref' && $context->workspaceRef) {
                    $score += 0.5;
                }
            }
        }

        return $score;
    }
}
