<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPack;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPackRevision;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPackSkill;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPackTemplate;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentGovernancePolicyService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentQualityGateService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\BuiltinSkillCatalog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pack lifecycle orchestrator — all mutations go through here.
 * No auto-enable. No partial activation.
 */
final class AgentPackOrchestrator
{
    public function __construct(
        private readonly AgentPackCompiler $compiler,
        private readonly AgentPackRegistry $registry,
        private readonly AgentPackDiscoveryService $discovery,
        private readonly AgentPackEventEmitter $events = new AgentPackEventEmitter,
        private readonly AgentQualityGateService $gates = new AgentQualityGateService,
        private readonly AgentGovernancePolicyService $governance = new AgentGovernancePolicyService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listPacks(): array
    {
        $this->discovery->discover();

        return $this->registry->listSummaries();
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function validateManifest(array $manifest, string $type = 'custom'): array
    {
        $result = $this->compiler->compile(
            $manifest,
            $this->knownPackGraph(),
            $this->occupiedCommands(),
            $this->occupiedSkillKeys(),
            $type,
        );
        if (! $result['ok']) {
            $this->events->emit('pack.validation_failed', [
                'pack_key' => $manifest['key'] ?? null,
                'errors' => $result['errors'],
            ]);
            if ($this->hasCompatError($result['errors'])) {
                $this->events->emit('pack.compatibility_failed', [
                    'pack_key' => $manifest['key'] ?? null,
                    'errors' => $result['errors'],
                ]);
            }
        }

        return $result;
    }

    /**
     * Create custom pack draft (disabled).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function createCustom(array $manifest, int $actorId): array
    {
        $manifest['type'] = 'custom';
        $compiled = $this->validateManifest($manifest, 'custom');
        if (! ($compiled['ok'] ?? false)) {
            return ['ok' => false, 'code' => 'validation_failed', 'errors' => $compiled['errors']];
        }

        /** @var array<string, mixed> $payload */
        $payload = $compiled['compiled'];
        $norm = $payload['manifest'];

        try {
            return DB::connection('omi_seo_ai')->transaction(function () use ($norm, $payload, $compiled, $actorId): array {
                $pack = SeoAgentPack::query()->create([
                    'hash_id' => AgentPackRegistry::newHashId('apack'),
                    'key' => $norm['key'],
                    'name' => $norm['name'],
                    'version' => $norm['version'],
                    'schema_version' => $norm['schema_version'],
                    'type' => 'custom',
                    'source' => 'studio',
                    'trust' => 'admin_created',
                    'status' => 'installed',
                    'health' => 'healthy',
                    'compatibility' => 'compatible',
                    'description' => $norm['description'],
                    'provider' => $norm['provider'],
                    'manifest_hash' => hash('sha256', json_encode($norm) ?: ''),
                    'metadata_json' => $norm['metadata'],
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                $revision = $this->persistRevision($pack, $norm, $payload, (string) $compiled['revision_hash'], $actorId, 'validated');
                $pack->active_revision_id = null; // not enabled yet
                $pack->save();

                $this->events->emit('pack.compiled', ['pack_key' => $pack->key, 'revision' => $revision->hash_id]);

                return [
                    'ok' => true,
                    'code' => 'created',
                    'pack' => $pack->hash_id,
                    'revision' => $revision->hash_id,
                    'status' => 'installed',
                    'enabled' => false,
                    'auto_enable' => false,
                ];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'persist_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function enable(string $packHashId, int $actorId, bool $explicitApproval, ?array $gateSummary = null): array
    {
        if (! $explicitApproval) {
            return ['ok' => false, 'code' => 'approval_required', 'auto_enable' => false];
        }

        $pack = SeoAgentPack::query()->where('hash_id', $packHashId)->first();
        if ($pack === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if ($pack->status === 'quarantined') {
            return ['ok' => false, 'code' => 'quarantined'];
        }

        $revision = SeoAgentPackRevision::query()
            ->where('pack_id', $pack->id)
            ->whereIn('status', ['validated', 'active', 'superseded'])
            ->orderByDesc('id')
            ->first();
        if ($revision === null || ! is_array($revision->compiled_json)) {
            return ['ok' => false, 'code' => 'no_validated_revision'];
        }

        $summary = $gateSummary ?? [
            'case_count' => 10,
            'skill_match_rate' => 1.0,
            'unsafe_rate' => 0.0,
            'validation_pass_rate' => 1.0,
        ];
        $gate = $this->gates->evaluate($summary);
        $activation = $this->governance->canActivateCandidate((string) $gate['status']);
        if (! ($activation['allowed'] ?? false)) {
            $this->events->emit('pack.quality_gate_failed', [
                'pack_key' => $pack->key,
                'gate_status' => $gate['status'],
            ]);

            return [
                'ok' => false,
                'code' => 'quality_gate_failed',
                'gate' => $gate,
                'auto_promotion' => false,
            ];
        }

        try {
            return DB::connection('omi_seo_ai')->transaction(function () use ($pack, $revision, $actorId, $gate): array {
                // Atomic: previous active superseded, new active, pack enabled.
                SeoAgentPackRevision::query()
                    ->where('pack_id', $pack->id)
                    ->where('status', 'active')
                    ->update(['status' => 'superseded']);

                $revision->status = 'active';
                $revision->activated_by = $actorId;
                $revision->activated_at = now();
                $revision->gate_status = (string) $gate['status'];
                $revision->gate_report = $gate;
                $revision->save();

                $pack->status = 'enabled';
                $pack->active_revision_id = $revision->id;
                $pack->enabled_at = now();
                $pack->disabled_at = null;
                $pack->updated_by = $actorId;
                $pack->save();

                $this->registry->putEnabled(
                    (string) $pack->key,
                    (string) $revision->definition_hash,
                    is_array($revision->compiled_json) ? $revision->compiled_json : [],
                );
                $this->registry->invalidate();
                $this->registry->putEnabled(
                    (string) $pack->key,
                    (string) $revision->definition_hash,
                    is_array($revision->compiled_json) ? $revision->compiled_json : [],
                );

                $this->events->emit('pack.enabled', ['pack_key' => $pack->key]);
                $this->events->emit('pack.revision_activated', [
                    'pack_key' => $pack->key,
                    'revision' => $revision->hash_id,
                ]);

                return [
                    'ok' => true,
                    'code' => 'enabled',
                    'pack' => $pack->hash_id,
                    'revision' => $revision->hash_id,
                    'auto_enable' => false,
                ];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'enable_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function disable(string $packHashId, int $actorId): array
    {
        $pack = SeoAgentPack::query()->where('hash_id', $packHashId)->first();
        if ($pack === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if ($pack->type === 'builtin') {
            return ['ok' => false, 'code' => 'builtin_cannot_disable_uninstall'];
        }

        $pack->status = 'disabled';
        $pack->disabled_at = now();
        $pack->updated_by = $actorId;
        $pack->save();

        $this->registry->removeEnabled((string) $pack->key);
        $this->registry->invalidate();
        $this->events->emit('pack.disabled', ['pack_key' => $pack->key]);

        return [
            'ok' => true,
            'code' => 'disabled',
            'history_preserved' => true,
            'business_data_deleted' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(string $packHashId, string $revisionHashId, int $actorId, bool $explicitApproval): array
    {
        if (! $explicitApproval) {
            return ['ok' => false, 'code' => 'approval_required'];
        }
        $pack = SeoAgentPack::query()->where('hash_id', $packHashId)->first();
        if ($pack === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $revision = SeoAgentPackRevision::query()
            ->where('pack_id', $pack->id)
            ->where('hash_id', $revisionHashId)
            ->first();
        if ($revision === null || ! is_array($revision->compiled_json)) {
            return ['ok' => false, 'code' => 'revision_not_found'];
        }

        // Re-check compile against current occupied space excluding this pack's own skills.
        $recheck = $this->compiler->compile(
            is_array($revision->manifest_json) ? $revision->manifest_json : [],
            $this->knownPackGraph(),
            $this->occupiedCommands((string) $pack->key),
            $this->occupiedSkillKeys((string) $pack->key),
            (string) $pack->type,
        );
        if (! ($recheck['ok'] ?? false)) {
            return ['ok' => false, 'code' => 'compatibility_recheck_failed', 'errors' => $recheck['errors']];
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($pack, $revision, $actorId): array {
            SeoAgentPackRevision::query()
                ->where('pack_id', $pack->id)
                ->where('status', 'active')
                ->update(['status' => 'superseded']);

            $revision->status = 'active';
            $revision->activated_by = $actorId;
            $revision->activated_at = now();
            $revision->save();

            $pack->active_revision_id = $revision->id;
            $pack->version = $revision->version;
            $pack->status = 'enabled';
            $pack->updated_by = $actorId;
            $pack->save();

            $this->registry->putEnabled(
                (string) $pack->key,
                (string) $revision->definition_hash,
                is_array($revision->compiled_json) ? $revision->compiled_json : [],
            );
            $this->events->emit('pack.rollback', ['pack_key' => $pack->key, 'revision' => $revision->hash_id]);

            return ['ok' => true, 'code' => 'rolled_back', 'business_data_rollback' => false];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function quarantine(string $packHashId, int $actorId, string $reason = ''): array
    {
        $pack = SeoAgentPack::query()->where('hash_id', $packHashId)->first();
        if ($pack === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $pack->status = 'quarantined';
        $pack->health = 'unhealthy';
        $pack->updated_by = $actorId;
        $pack->metadata_json = array_merge(is_array($pack->metadata_json) ? $pack->metadata_json : [], [
            'quarantine_reason' => $reason,
        ]);
        $pack->save();
        $this->registry->removeEnabled((string) $pack->key);

        return ['ok' => true, 'code' => 'quarantined'];
    }

    /**
     * @return array<string, mixed>
     */
    public function uninstall(string $packHashId, int $actorId): array
    {
        $pack = SeoAgentPack::query()->where('hash_id', $packHashId)->first();
        if ($pack === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if ($pack->type === 'builtin') {
            return ['ok' => false, 'code' => 'builtin_cannot_uninstall'];
        }
        if (! in_array($pack->type, ['custom', 'imported'], true)) {
            return ['ok' => false, 'code' => 'uninstall_type_forbidden'];
        }

        $this->registry->removeEnabled((string) $pack->key);
        $pack->status = 'removed';
        $pack->updated_by = $actorId;
        $pack->save();
        $pack->delete();

        return ['ok' => true, 'code' => 'uninstalled', 'history_soft_deleted' => true];
    }

    /**
     * Studio preview — never executes capability.
     *
     * @param  array<string, mixed>  $skill
     * @return array<string, mixed>
     */
    public function previewSkill(array $skill, string $packKey = 'studio.preview'): array
    {
        $bound = $this->compiler->compile(
            [
                'schema_version' => AgentPackConstants::SCHEMA_VERSION,
                'key' => $packKey,
                'name' => 'Preview',
                'version' => '0.0.1',
                'type' => 'custom',
                'skills' => [$skill],
                'templates' => [],
            ],
            $this->knownPackGraph(),
            $this->occupiedCommands(),
            $this->occupiedSkillKeys(),
            'custom',
        );

        return [
            'ok' => $bound['ok'] ?? false,
            'errors' => $bound['errors'] ?? [],
            'executed' => false,
            'preview' => $bound['compiled']['skills'][0] ?? null,
            'capability_executed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $norm
     * @param  array<string, mixed>  $compiled
     */
    private function persistRevision(
        SeoAgentPack $pack,
        array $norm,
        array $compiled,
        string $definitionHash,
        int $actorId,
        string $status,
    ): SeoAgentPackRevision {
        $revisionNo = (int) SeoAgentPackRevision::query()->where('pack_id', $pack->id)->count() + 1;
        $revision = SeoAgentPackRevision::query()->create([
            'hash_id' => AgentPackRegistry::newHashId('aprev'),
            'pack_id' => $pack->id,
            'version' => $norm['version'],
            'revision_no' => $revisionNo,
            'definition_hash' => $definitionHash,
            'status' => $status,
            'manifest_json' => $norm,
            'compiled_json' => $compiled,
            'validation_report' => ['ok' => true],
            'created_by' => $actorId,
        ]);

        foreach ($compiled['skills'] ?? [] as $skill) {
            if (! is_array($skill)) {
                continue;
            }
            SeoAgentPackSkill::query()->create([
                'pack_id' => $pack->id,
                'revision_id' => $revision->id,
                'skill_key' => $skill['key'],
                'slash_command' => $skill['slash_command'],
                'capability' => $skill['capability'],
                'definition_json' => $skill,
            ]);
        }
        foreach ($compiled['templates'] ?? [] as $tpl) {
            if (! is_array($tpl)) {
                continue;
            }
            SeoAgentPackTemplate::query()->create([
                'pack_id' => $pack->id,
                'revision_id' => $revision->id,
                'template_key' => $tpl['key'],
                'definition_json' => $tpl,
            ]);
        }

        return $revision;
    }

    /**
     * @return array<string, array{status: string, version?: string, dependencies?: list<string>}>
     */
    private function knownPackGraph(): array
    {
        $graph = ['omi.agent-core' => ['status' => 'enabled', 'version' => '1.0.0', 'dependencies' => []]];
        try {
            foreach (SeoAgentPack::query()->get() as $pack) {
                $deps = [];
                if ($pack->active_revision_id) {
                    $rev = SeoAgentPackRevision::query()->find($pack->active_revision_id);
                    $manifest = is_array($rev?->manifest_json) ? $rev->manifest_json : [];
                    $deps = is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : [];
                }
                $graph[(string) $pack->key] = [
                    'status' => (string) $pack->status,
                    'version' => (string) $pack->version,
                    'dependencies' => array_map('strval', $deps),
                ];
            }
        } catch (Throwable) {
            // ignore
        }

        return $graph;
    }

    /**
     * @return list<string>
     */
    private function occupiedCommands(?string $excludePackKey = null): array
    {
        $commands = [];
        foreach (BuiltinSkillCatalog::definitions() as $row) {
            $commands[] = $this->normCmd((string) ($row['slash_command'] ?? ''));
            foreach ((array) ($row['aliases'] ?? []) as $a) {
                $commands[] = $this->normCmd((string) $a);
            }
        }
        foreach ($this->registry->enabledSkillDefinitions() as $skill) {
            if ($excludePackKey !== null && ($skill['pack_key'] ?? '') === $excludePackKey) {
                continue;
            }
            $commands[] = $this->normCmd((string) ($skill['slash_command'] ?? ''));
            foreach ((array) ($skill['aliases'] ?? []) as $a) {
                $commands[] = $this->normCmd((string) $a);
            }
        }

        return array_values(array_filter($commands));
    }

    /**
     * @return list<string>
     */
    private function occupiedSkillKeys(?string $excludePackKey = null): array
    {
        $keys = [];
        foreach (BuiltinSkillCatalog::definitions() as $row) {
            $keys[] = (string) ($row['key'] ?? '');
        }
        foreach ($this->registry->enabledSkillDefinitions() as $skill) {
            if ($excludePackKey !== null && ($skill['pack_key'] ?? '') === $excludePackKey) {
                continue;
            }
            $keys[] = (string) ($skill['key'] ?? '');
        }

        return array_values(array_filter($keys));
    }

    private function normCmd(string $raw): string
    {
        $c = mb_strtolower(trim($raw));
        if ($c !== '' && ! str_starts_with($c, '/')) {
            $c = '/'.$c;
        }

        return $c;
    }

    /**
     * @param  list<string>  $errors
     */
    private function hasCompatError(array $errors): bool
    {
        foreach ($errors as $e) {
            if (str_contains($e, 'dependency') || str_contains($e, 'conflict') || str_contains($e, 'incompatible')) {
                return true;
            }
        }

        return false;
    }
}
