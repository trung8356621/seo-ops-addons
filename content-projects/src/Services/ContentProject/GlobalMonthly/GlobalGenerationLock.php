<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\ContentProjects\Models\SeoContentGenerationLock;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunRecoverableState;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Singleton Global Generation Lock — one content-generation owner system-wide.
 *
 * Read paths: never throw; unavailable ≠ unlocked.
 * Write paths (acquire): fail-closed when table missing / DB error / busy.
 */
final class GlobalGenerationLock
{
    public const BUSY_MESSAGE = 'Đang có tiến trình Generate khác hoạt động.';

    public const UNAVAILABLE_MESSAGE = 'Chưa thể xác định trạng thái Generate.';

    public const STATE_AVAILABLE = 'available';

    public const STATE_HELD = 'held';

    public const STATE_UNAVAILABLE = 'unavailable';

    private ?bool $tableReady = null;

    private ?string $tableProbeError = null;

    /**
     * @return array{
     *     state: string,
     *     holder: ?array<string, mixed>,
     *     message: ?string,
     *     reason: ?string
     * }
     */
    public function inspect(): array
    {
        if (! $this->tableReady()) {
            return [
                'state' => self::STATE_UNAVAILABLE,
                'holder' => null,
                'message' => self::UNAVAILABLE_MESSAGE,
                'reason' => $this->tableProbeError ?? 'lock_table_missing',
            ];
        }

        try {
            $holder = $this->readHolder();
        } catch (Throwable $e) {
            return [
                'state' => self::STATE_UNAVAILABLE,
                'holder' => null,
                'message' => self::UNAVAILABLE_MESSAGE,
                'reason' => 'lock_query_failed:'.$e->getMessage(),
            ];
        }

        if ($holder !== null) {
            return [
                'state' => self::STATE_HELD,
                'holder' => $holder,
                'message' => self::BUSY_MESSAGE,
                'reason' => 'busy',
            ];
        }

        return [
            'state' => self::STATE_AVAILABLE,
            'holder' => null,
            'message' => null,
            'reason' => null,
        ];
    }

    /**
     * Fail-closed gate for Start Generate (individual or global).
     * Unavailable / held → false. Never treat unavailable as free.
     */
    public function canAttemptAcquire(): bool
    {
        return ($this->inspect()['state'] ?? null) === self::STATE_AVAILABLE;
    }

    private function tableReady(): bool
    {
        if ($this->tableReady !== null) {
            return $this->tableReady;
        }

        try {
            $this->tableReady = Schema::connection('omi_seo_ai')
                ->hasTable((new SeoContentGenerationLock)->getTable());
            $this->tableProbeError = $this->tableReady ? null : 'lock_table_missing';
        } catch (Throwable $e) {
            $this->tableReady = false;
            $this->tableProbeError = 'schema_probe_failed:'.$e->getMessage();
        }

        return $this->tableReady;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{ok: true, token: string}|array{ok: false, reason: string, holder: ?array<string, mixed>, message: string}
     */
    public function acquire(string $ownerType, int $ownerId, array $meta = []): array
    {
        $inspect = $this->inspect();
        if (($inspect['state'] ?? null) === self::STATE_UNAVAILABLE) {
            return [
                'ok' => false,
                'reason' => (string) ($inspect['reason'] ?? 'lock_unavailable'),
                'holder' => null,
                'message' => self::UNAVAILABLE_MESSAGE,
            ];
        }

        if (($inspect['state'] ?? null) === self::STATE_HELD) {
            return [
                'ok' => false,
                'reason' => 'busy',
                'holder' => $inspect['holder'] ?? null,
                'message' => self::BUSY_MESSAGE,
            ];
        }

        if (! in_array($ownerType, [
            SeoContentGenerationLock::OWNER_GLOBAL_BATCH,
            SeoContentGenerationLock::OWNER_PROJECT_RUN,
        ], true) || $ownerId <= 0) {
            return [
                'ok' => false,
                'reason' => 'invalid_owner',
                'holder' => null,
                'message' => self::UNAVAILABLE_MESSAGE,
            ];
        }

        $token = (string) Str::uuid();
        $now = now();

        try {
            $acquired = DB::connection('omi_seo_ai')->transaction(function () use ($ownerType, $ownerId, $token, $meta, $now): bool {
                /** @var SeoContentGenerationLock|null $existing */
                $existing = SeoContentGenerationLock::query()
                    ->where('slot', SeoContentGenerationLock::SLOT_GLOBAL)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof SeoContentGenerationLock) {
                    return false;
                }

                SeoContentGenerationLock::query()->create([
                    'slot' => SeoContentGenerationLock::SLOT_GLOBAL,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'owner_token' => $token,
                    'acquired_at' => $now,
                    'heartbeat_at' => $now,
                    'meta' => $meta,
                ]);

                return true;
            });
        } catch (Throwable) {
            return [
                'ok' => false,
                'reason' => 'acquire_exception',
                'holder' => null,
                'message' => self::UNAVAILABLE_MESSAGE,
            ];
        }

        if (! $acquired) {
            return [
                'ok' => false,
                'reason' => 'busy',
                'holder' => $this->currentHolder(),
                'message' => self::BUSY_MESSAGE,
            ];
        }

        return ['ok' => true, 'token' => $token];
    }

    public function release(string $ownerToken): bool
    {
        if (! $this->tableReady()) {
            return false;
        }

        $token = trim($ownerToken);
        if ($token === '') {
            return false;
        }

        try {
            return DB::connection('omi_seo_ai')->transaction(function () use ($token): bool {
                /** @var SeoContentGenerationLock|null $existing */
                $existing = SeoContentGenerationLock::query()
                    ->where('slot', SeoContentGenerationLock::SLOT_GLOBAL)
                    ->lockForUpdate()
                    ->first();

                if (! $existing instanceof SeoContentGenerationLock) {
                    return false;
                }

                if (! hash_equals((string) $existing->owner_token, $token)) {
                    return false;
                }

                $existing->delete();

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function heartbeat(string $ownerToken): bool
    {
        if (! $this->tableReady()) {
            return false;
        }

        $token = trim($ownerToken);
        if ($token === '') {
            return false;
        }

        try {
            $updated = SeoContentGenerationLock::query()
                ->where('slot', SeoContentGenerationLock::SLOT_GLOBAL)
                ->where('owner_token', $token)
                ->update(['heartbeat_at' => now()]);

            return $updated > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function assertHeldBy(string $ownerType, int $ownerId): bool
    {
        $inspect = $this->inspect();
        if (($inspect['state'] ?? null) !== self::STATE_HELD) {
            return false;
        }

        $holder = $inspect['holder'] ?? null;
        if (! is_array($holder)) {
            return false;
        }

        return ($holder['owner_type'] ?? null) === $ownerType
            && (int) ($holder['owner_id'] ?? 0) === $ownerId;
    }

    /**
     * True only when a verified holder exists. Unavailable → false (not unlocked).
     * Callers that gate Start must use canAttemptAcquire() / inspect(), not isHeld() alone.
     */
    public function isHeld(): bool
    {
        return ($this->inspect()['state'] ?? null) === self::STATE_HELD;
    }

    public function isUnavailable(): bool
    {
        return ($this->inspect()['state'] ?? null) === self::STATE_UNAVAILABLE;
    }

    /**
     * @return array{
     *     owner_type: string,
     *     owner_id: int,
     *     owner_token: string,
     *     acquired_at: ?string,
     *     heartbeat_at: ?string,
     *     meta: array<string, mixed>,
     *     label: string,
     *     progress_url: ?string
     * }|null
     */
    public function currentHolder(): ?array
    {
        $inspect = $this->inspect();
        if (($inspect['state'] ?? null) !== self::STATE_HELD) {
            return null;
        }

        /** @var array<string, mixed>|null $holder */
        $holder = $inspect['holder'] ?? null;

        return is_array($holder) ? $holder : null;
    }

    public function releaseIfOwner(string $ownerType, int $ownerId, string $ownerToken): bool
    {
        if (! $this->tableReady()) {
            return false;
        }

        try {
            return DB::connection('omi_seo_ai')->transaction(function () use ($ownerType, $ownerId, $ownerToken): bool {
                /** @var SeoContentGenerationLock|null $existing */
                $existing = SeoContentGenerationLock::query()
                    ->where('slot', SeoContentGenerationLock::SLOT_GLOBAL)
                    ->lockForUpdate()
                    ->first();

                if (! $existing instanceof SeoContentGenerationLock) {
                    return false;
                }

                if ((string) $existing->owner_type !== $ownerType
                    || (int) $existing->owner_id !== $ownerId
                    || ! hash_equals((string) $existing->owner_token, $ownerToken)
                ) {
                    return false;
                }

                $existing->delete();

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readHolder(): ?array
    {
        /** @var SeoContentGenerationLock|null $row */
        $row = SeoContentGenerationLock::query()
            ->where('slot', SeoContentGenerationLock::SLOT_GLOBAL)
            ->first();

        if (! $row instanceof SeoContentGenerationLock) {
            return null;
        }

        $meta = is_array($row->meta) ? $row->meta : [];
        $ownerType = (string) $row->owner_type;
        $ownerId = (int) $row->owner_id;
        $label = (string) ($meta['label'] ?? $this->defaultLabel($ownerType, $ownerId));
        $progressUrl = isset($meta['progress_url']) ? (string) $meta['progress_url'] : null;

        $projectId = null;
        $projectName = null;
        $runId = null;
        $realStatus = null;
        $isRecoverable = false;

        try {
            if ($ownerType === SeoContentGenerationLock::OWNER_PROJECT_RUN) {
                $runId = $ownerId;
                /** @var SeoProjectRun|null $run */
                $run = SeoProjectRun::query()->with('project')->find($ownerId);
                if ($run instanceof SeoProjectRun) {
                    $projectId = (int) $run->project_id;
                    $projectName = $run->project?->name;
                    $realStatus = (string) $run->status;
                    $isRecoverable = ContentProjectRunRecoverableState::isRecoverableRun($run);
                    if ($isRecoverable) {
                        $engine = is_array($run->settings['php_engine'] ?? null) ? $run->settings['php_engine'] : [];
                        $reason = ContentProjectRunRecoverableState::reasonFromEngine($engine);
                        $realStatus .= ' ('.($reason ?? 'recoverable').')';
                    }
                    if ($progressUrl === null && $projectId > 0) {
                        try {
                            $progressUrl = \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getUrl('progress', ['record' => $projectId]);
                        } catch (Throwable) {
                            $progressUrl = '/seo/content-projects/'.$projectId.'/progress';
                        }
                    }
                }
            } elseif ($ownerType === SeoContentGenerationLock::OWNER_GLOBAL_BATCH) {
                /** @var SeoContentGlobalBatch|null $batch */
                $batch = SeoContentGlobalBatch::query()->find($ownerId);
                if ($batch instanceof SeoContentGlobalBatch) {
                    $realStatus = (string) $batch->status;
                    $runId = (int) ($batch->current_project_run_id ?? 0) ?: null;
                    $projectId = (int) ($batch->current_project_id ?? 0) ?: null;
                    if ($projectId > 0) {
                        $projectName = SeoProject::query()->whereKey($projectId)->value('name');
                    }
                    if ($runId > 0) {
                        $childRun = SeoProjectRun::query()->find($runId);
                        if ($childRun instanceof SeoProjectRun) {
                            $childStatus = (string) $childRun->status;
                            if (ContentProjectRunRecoverableState::isRecoverableRun($childRun)) {
                                $isRecoverable = true;
                                $engine = is_array($childRun->settings['php_engine'] ?? null) ? $childRun->settings['php_engine'] : [];
                                $reason = ContentProjectRunRecoverableState::reasonFromEngine($engine);
                                $childStatus .= ' ('.($reason ?? 'recoverable').')';
                            }
                            $realStatus .= ' [Run #'.$runId.': '.$childStatus.']';
                        }
                    }
                    if ($progressUrl === null) {
                        try {
                            $monthStr = $batch->planning_month instanceof CarbonInterface
                                ? $batch->planning_month->format('Y-m')
                                : (is_string($batch->planning_month) ? substr($batch->planning_month, 0, 7) : null);
                            $params = array_filter([
                                'month' => $monthStr,
                                'site_id' => $batch->site_id,
                            ]);
                            $progressUrl = \Omnichannel\Addons\ContentProjects\Filament\Pages\GlobalMonthlyGeneratorPage::getUrl($params);
                        } catch (Throwable) {
                            $progressUrl = '/seo/global-monthly-generator';
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Read-paths never crash
        }

        $heartbeatAt = $row->heartbeat_at;
        $heartbeatHuman = null;
        if ($heartbeatAt instanceof CarbonInterface) {
            $heartbeatHuman = $heartbeatAt->diffForHumans();
        }

        return [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'owner_token' => (string) $row->owner_token,
            'acquired_at' => $row->acquired_at?->toIso8601String(),
            'heartbeat_at' => $row->heartbeat_at?->toIso8601String(),
            'heartbeat_at_human' => $heartbeatHuman,
            'meta' => $meta,
            'label' => $label,
            'owner_label' => $label,
            'project_id' => $projectId,
            'project_name' => $projectName,
            'run_id' => $runId,
            'real_status' => $realStatus ?? 'unknown',
            'is_recoverable' => $isRecoverable,
            'progress_url' => $progressUrl,
        ];
    }

    private function defaultLabel(string $ownerType, int $ownerId): string
    {
        if ($ownerType === SeoContentGenerationLock::OWNER_GLOBAL_BATCH) {
            return 'Global Batch #'.$ownerId;
        }

        if ($ownerType === SeoContentGenerationLock::OWNER_PROJECT_RUN) {
            $run = SeoProjectRun::query()->find($ownerId);
            if ($run instanceof SeoProjectRun) {
                return 'Project Run #'.$ownerId.' (project '.$run->project_id.')';
            }

            return 'Project Run #'.$ownerId;
        }

        return $ownerType.' #'.$ownerId;
    }
}
