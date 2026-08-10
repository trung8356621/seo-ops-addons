<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\BuiltinSkillCatalog;
use RuntimeException;
use Throwable;

/**
 * Presentation registry for Agent Skills.
 * Does not replace CanonicalCapabilityRegistry.
 */
final class AgentSkillRegistry
{
    /** @var list<AgentSkillDefinition>|null */
    private ?array $skills = null;

    /** @var array<string, AgentSkillDefinition>|null */
    private ?array $byKey = null;

    /** @var array<string, string>|null canonical slash or alias => skill_key */
    private ?array $commandIndex = null;

    /**
     * @param  list<array<string, mixed>>|null  $definitions
     */
    public function __construct(
        private readonly ?array $definitions = null,
        private readonly ?AgentPackRegistry $packs = null,
    ) {}

    /**
     * Drop cached skill map (after pack lifecycle changes).
     */
    public function invalidate(): void
    {
        $this->skills = null;
        $this->byKey = null;
        $this->commandIndex = null;
    }
    /**
     * @return list<AgentSkillDefinition>
     */
    public function all(bool $includeHidden = false): array
    {
        $skills = $this->boot();
        if ($includeHidden) {
            return $skills;
        }

        return array_values(array_filter(
            $skills,
            static fn (AgentSkillDefinition $skill): bool => ! $skill->isHidden,
        ));
    }

    public function get(string $key): ?AgentSkillDefinition
    {
        $this->boot();

        return $this->byKey[$key] ?? null;
    }

    public function resolveSlashCommand(string $raw): ?AgentSkillDefinition
    {
        $command = $this->normalizeCommand($raw);
        if ($command === null) {
            return null;
        }

        $this->boot();
        $key = $this->commandIndex[$command] ?? null;
        if ($key === null) {
            return null;
        }

        return $this->byKey[$key] ?? null;
    }

    /**
     * @return list<AgentSkillDefinition>
     */
    public function search(string $query, bool $includeHidden = false): array
    {
        $needle = mb_strtolower(trim($query));
        $skills = $this->all($includeHidden);
        if ($needle === '' || $needle === '/') {
            return $skills;
        }

        $needle = ltrim($needle, '/');

        return array_values(array_filter(
            $skills,
            static function (AgentSkillDefinition $skill) use ($needle): bool {
                $haystacks = [
                    ltrim($skill->slashCommand, '/'),
                    $skill->name,
                    $skill->description,
                    $skill->key,
                    $skill->category,
                    ...array_map(static fn (string $a): string => ltrim($a, '/'), $skill->aliases),
                ];

                foreach ($haystacks as $hay) {
                    if (mb_strpos(mb_strtolower($hay), $needle) !== false) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @return list<AgentSkillDefinition>
     */
    public function byCategory(string $category, bool $includeHidden = false): array
    {
        return array_values(array_filter(
            $this->all($includeHidden),
            static fn (AgentSkillDefinition $skill): bool => $skill->category === $category,
        ));
    }

    /**
     * @return list<AgentSkillDefinition>
     */
    public function featured(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (AgentSkillDefinition $skill): bool => $skill->isFeatured,
        ));
    }

    /**
     * @return list<AgentSkillDefinition>
     */
    private function boot(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }

        $raw = $this->definitions ?? BuiltinSkillCatalog::definitions();
        if ($this->definitions === null && $this->packs !== null) {
            try {
                $raw = array_merge($raw, $this->packs->enabledSkillDefinitions());
            } catch (Throwable) {
                // Invalid pack must not break Agent Workspace.
            }
        }
        /** @var array<string, AgentSkillDefinition> $byKey */
        $byKey = [];
        /** @var array<string, string> $commandIndex */
        $commandIndex = [];
        /** @var list<AgentSkillDefinition> $skills */
        $skills = [];

        foreach ($raw as $row) {
            try {
                $skill = AgentSkillDefinition::fromArray($row);
                if ($skill->key === '' || $skill->slashCommand === '') {
                    if ($this->definitions !== null) {
                        throw new RuntimeException('agent.skill_invalid: missing key or slash_command');
                    }

                    continue;
                }

                if (isset($byKey[$skill->key])) {
                    if ($this->definitions !== null) {
                        throw new RuntimeException('agent.skill_conflict: '.$skill->key);
                    }
                    // No last-registration-wins for packs — skip conflicting pack skill.
                    continue;
                }

                $canonical = $this->normalizeCommand($skill->slashCommand);
                if ($canonical === null) {
                    if ($this->definitions !== null) {
                        throw new RuntimeException('agent.slash_command_conflict: invalid '.$skill->slashCommand);
                    }

                    continue;
                }

                if (isset($commandIndex[$canonical])) {
                    if ($this->definitions !== null) {
                        throw new RuntimeException('agent.skill_command_conflict: '.$canonical);
                    }

                    continue;
                }

                $aliasOk = true;
                $pendingAliases = [];
                foreach ($skill->aliases as $alias) {
                    $normalizedAlias = $this->normalizeCommand($alias);
                    if ($normalizedAlias === null) {
                        if ($this->definitions !== null) {
                            throw new RuntimeException('agent.slash_command_conflict: invalid alias '.$alias);
                        }
                        $aliasOk = false;
                        break;
                    }
                    if (isset($commandIndex[$normalizedAlias])) {
                        if ($this->definitions !== null) {
                            throw new RuntimeException('agent.skill_command_conflict: '.$normalizedAlias);
                        }
                        $aliasOk = false;
                        break;
                    }
                    $pendingAliases[] = $normalizedAlias;
                }
                if (! $aliasOk) {
                    continue;
                }

                $commandIndex[$canonical] = $skill->key;
                foreach ($pendingAliases as $normalizedAlias) {
                    $commandIndex[$normalizedAlias] = $skill->key;
                }

                $byKey[$skill->key] = $skill;
                $skills[] = $skill;
            } catch (RuntimeException $e) {
                if ($this->definitions !== null) {
                    throw $e;
                }
                // isolate
                continue;
            }
        }

        usort(
            $skills,
            static fn (AgentSkillDefinition $a, AgentSkillDefinition $b): int => $a->sortOrder <=> $b->sortOrder
                ?: strcmp($a->key, $b->key),
        );

        $this->skills = $skills;
        $this->byKey = $byKey;
        $this->commandIndex = $commandIndex;

        return $skills;
    }

    private function normalizeCommand(string $raw): ?string
    {
        $command = mb_strtolower(trim($raw));
        if ($command === '') {
            return null;
        }
        if (! str_starts_with($command, '/')) {
            $command = '/'.$command;
        }
        if (! preg_match('/^\/[a-z0-9]+(?:-[a-z0-9]+)*$/', $command)) {
            return null;
        }

        return $command;
    }
}
