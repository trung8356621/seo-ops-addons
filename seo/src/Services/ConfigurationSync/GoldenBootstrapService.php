<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\ConfigurationSyncAuthorization;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackagePath;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SystemBootstrapAuthorization;

/**
 * Golden package lifecycle:
 * - download/stage requires Installation credential only (no SEO entitlement).
 * - apply into DB requires SEO entitlement and reuses import/apply logic.
 */
final class GoldenBootstrapService
{
    public function __construct(
        private readonly InstallationMachineCredentialProvider $credentials,
        private readonly SeoEntitlementProbe $entitlement,
        private readonly ConfigSyncHttpTransport $transport,
        private readonly GoldenPackageInstaller $installer,
        private readonly BlankInstallationDetector $blank,
        private readonly ConfigurationSyncStateStore $state,
        private readonly SeoSettingsBundleService $bundles,
        private readonly ?LiveConfigurationComparer $comparer = null,
    ) {}

    /**
     * Download + apply convenience (backward compatible).
     *
     * @return array<string, mixed>
     */
    public function bootstrap(int $userId, ?string $targetRoot = null, bool $overwriteExisting = false): array
    {
        $download = $this->downloadPackage($targetRoot);
        if (! in_array((string) ($download['status'] ?? ''), ['downloaded', 'already_downloaded'], true)) {
            return array_merge($download, ['imported' => false]);
        }

        return $this->applyPackage($userId, $targetRoot, $overwriteExisting);
    }

    /**
     * Stage Golden package under resources/golden-configuration (or $targetRoot).
     * Does not write Prompt/Workflow/SEO settings into the database.
     *
     * @return array<string, mixed>
     */
    public function downloadPackage(?string $targetRoot = null): array
    {
        $credential = $this->credentials->current();
        if ($credential === null) {
            return $this->blocked('blocked_on_h1');
        }

        $root = $this->resolveRoot($targetRoot);

        try {
            $current = $this->transport->current();
        } catch (ConfigurationSyncException $e) {
            return [
                'status' => 'download_failed',
                'downloaded' => false,
                'imported' => false,
                'error_code' => $e->errorCode,
            ];
        }

        $publishedHash = strtolower(trim((string) ($current['package']['canonical_hash'] ?? '')));
        $publishedVersion = (string) ($current['package']['package_version'] ?? '');
        if ($publishedHash === '') {
            return [
                'status' => 'download_failed',
                'downloaded' => false,
                'imported' => false,
                'error_code' => 'canonical_hash_mismatch',
            ];
        }

        $local = $this->inspectLocalPackage($root);
        if (
            is_array($local)
            && hash_equals($publishedHash, (string) $local['canonical_hash'])
            && ($publishedVersion === '' || $publishedVersion === (string) $local['package_version'])
        ) {
            $this->recordPackageStaged($local['package_version'], $local['canonical_hash'], reused: true);

            return [
                'status' => 'already_downloaded',
                'downloaded' => true,
                'imported' => false,
                'reused' => true,
                'package_version' => $local['package_version'],
                'canonical_hash' => $local['canonical_hash'],
                'apply_status' => 'pending_apply',
            ];
        }

        try {
            $bundle = $this->transport->package();
        } catch (ConfigurationSyncException $e) {
            return [
                'status' => 'download_failed',
                'downloaded' => false,
                'imported' => false,
                'error_code' => $e->errorCode,
            ];
        }

        $bundleHash = strtolower(trim((string) ($bundle['canonical_hash'] ?? '')));
        if ($bundleHash === '' || ! hash_equals($publishedHash, $bundleHash)) {
            return [
                'status' => 'download_failed',
                'downloaded' => false,
                'imported' => false,
                'error_code' => 'canonical_hash_mismatch',
            ];
        }

        try {
            $installed = $this->installer->stageAndPromote($bundle, $root);
        } catch (ConfigurationSyncException $e) {
            return [
                'status' => 'download_failed',
                'downloaded' => false,
                'imported' => false,
                'error_code' => $e->errorCode,
            ];
        }

        $this->recordPackageStaged(
            (string) $installed['package_version'],
            (string) $installed['canonical_hash'],
            reused: false,
        );

        return [
            'status' => 'downloaded',
            'downloaded' => true,
            'imported' => false,
            'reused' => false,
            'package_version' => $installed['package_version'],
            'canonical_hash' => $installed['canonical_hash'],
            'apply_status' => 'pending_apply',
        ];
    }

    /**
     * Apply a staged local Golden package into the database.
     * Requires SEO entitlement. Does not mark Applied when apply fails.
     *
     * @return array<string, mixed>
     */
    public function applyPackage(int $userId, ?string $targetRoot = null, bool $overwriteExisting = false): array
    {
        $credential = $this->credentials->current();
        if ($credential === null) {
            return $this->blocked('blocked_on_h1');
        }
        if (! $this->entitlement->seoServiceEnabled()) {
            return array_merge($this->blocked('unlicensed_service'), [
                'apply_status' => 'pending_apply',
                'package' => $this->state->read()['package'] ?? null,
            ]);
        }

        $assessment = $this->blank->assess($userId);
        if ($assessment['blank'] !== true && ! $overwriteExisting) {
            return [
                'status' => 'existing_client',
                'imported' => false,
                'signals' => $assessment['signals'],
            ];
        }

        $root = $this->resolveRoot($targetRoot);
        $ensure = $this->ensureLocalPackageReady($root);
        if (($ensure['ok'] ?? false) !== true) {
            return array_merge($ensure, ['imported' => false]);
        }

        $entrypoint = $root.'/payload/full-configuration.json';
        $raw = file_get_contents($entrypoint);
        if (! is_string($raw) || trim($raw) === '') {
            return ['status' => 'import_failed', 'imported' => false, 'error_code' => 'missing_entrypoint'];
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return ['status' => 'import_failed', 'imported' => false, 'error_code' => 'invalid_entrypoint'];
        }

        $packageMeta = is_array($ensure['package'] ?? null) ? $ensure['package'] : [];

        try {
            $this->bindPackageHooks($payload);
            $plan = $this->bundles->parseAndPlan($raw, $userId, 'merge');
            $this->bundles->applyForSystemBootstrap(
                $plan,
                $userId,
                SystemBootstrapAuthorization::forGoldenConfigurationBootstrap(),
            );
        } catch (\Throwable $e) {
            $this->recordApplyFailure('import_failed', $packageMeta, $e->getMessage());

            return [
                'status' => 'import_failed',
                'imported' => false,
                'error_code' => 'import_failed',
                'detail' => $e->getMessage(),
                'package_retained' => true,
            ];
        }

        $expected = $this->expectedInventory($payload);
        $actual = [
            'prompts' => SeoPrompt::query()->where('user_id', $userId)->count(),
            'workflows' => SeoTask::query()->where('user_id', $userId)->count(),
            'bindings' => count(app(SeoCreateArticleSettingsService::class)->getPromptHookBindings()),
        ];
        $live = $this->bundles->exportForConfigurationSync(
            $userId,
            ConfigurationSyncAuthorization::forReadExport(),
        );
        $verification = ($this->comparer ?? app(LiveConfigurationComparer::class))->compare($payload, $live);
        if (($verification['outcome'] ?? null) !== 'in_sync') {
            $state = $this->state->read();
            $package = is_array($state['package'] ?? null) ? $state['package'] : [];
            $state['bootstrap'] = [
                'status' => 'validation_failed',
                'expected' => $expected,
                'actual' => $actual,
                'verification' => [
                    'outcome' => $verification['outcome'] ?? 'comparison_unresolved',
                    'reason' => $verification['reason'] ?? 'unknown',
                ],
                'package_version' => $packageMeta['package_version'] ?? null,
            ];
            // Keep staged package; do not mark applied.
            $state['package'] = array_merge($package, [
                'status' => 'pending_apply',
                'package_version' => $packageMeta['package_version'] ?? null,
                'canonical_hash' => $packageMeta['canonical_hash'] ?? null,
            ]);
            $this->state->write($state);

            return [
                'status' => 'validation_failed',
                'imported' => true,
                'expected' => $expected,
                'actual' => $actual,
                'verification' => $verification,
                'package_retained' => true,
            ];
        }

        $report = [
            'status' => 'bootstrapped',
            'imported' => true,
            'package_version' => $packageMeta['package_version'] ?? null,
            'canonical_hash' => $packageMeta['canonical_hash'] ?? null,
            'inventory' => $actual,
            'server_status' => 'not_reported',
        ];
        try {
            $this->transport->reportStatus([
                'protocol' => ConfigProtocol::ID,
                'installation_id' => $credential->installationId(),
                'service' => ConfigProtocol::SERVICE_KEY,
                'client' => $this->clientVersions(),
                'golden_baseline' => [
                    'package_id' => ConfigProtocol::PACKAGE_ID,
                    'package_version' => $packageMeta['package_version'] ?? '',
                    'canonical_hash' => $packageMeta['canonical_hash'] ?? '',
                ],
                'status' => 'in_sync',
                'current_canonical_hash' => $packageMeta['canonical_hash'] ?? '',
            ]);
            $report['server_status'] = 'acknowledged';
        } catch (ConfigurationSyncException $e) {
            $report['server_status'] = 'deferred';
            $report['server_error'] = $e->errorCode;
        }

        $state = $this->state->read();
        $state['bootstrap'] = [
            'status' => 'completed',
            'package_version' => $packageMeta['package_version'] ?? null,
            'canonical_hash' => $packageMeta['canonical_hash'] ?? null,
            'completed_at' => gmdate('c'),
            'server_status' => $report['server_status'],
        ];
        $state['package'] = [
            'status' => 'applied',
            'package_version' => $packageMeta['package_version'] ?? null,
            'canonical_hash' => $packageMeta['canonical_hash'] ?? null,
            'applied_at' => gmdate('c'),
            'downloaded_at' => is_array($state['package'] ?? null)
                ? ($state['package']['downloaded_at'] ?? null)
                : null,
        ];
        $this->state->write($state);

        return $report;
    }

    /**
     * @return array{ok: bool, status?: string, error_code?: string, package?: array<string, mixed>}
     */
    private function ensureLocalPackageReady(string $root): array
    {
        $local = $this->inspectLocalPackage($root);
        $needsDownload = ! is_array($local);

        if (is_array($local)) {
            try {
                $current = $this->transport->current();
                $publishedHash = strtolower(trim((string) ($current['package']['canonical_hash'] ?? '')));
                if ($publishedHash !== '' && ! hash_equals($publishedHash, (string) $local['canonical_hash'])) {
                    $needsDownload = true;
                }
            } catch (ConfigurationSyncException) {
                // Offline: reuse previously verified local package.
            }
        }

        if ($needsDownload) {
            $download = $this->downloadPackage($root);
            if (! in_array((string) ($download['status'] ?? ''), ['downloaded', 'already_downloaded'], true)) {
                return [
                    'ok' => false,
                    'status' => (string) ($download['status'] ?? 'download_failed'),
                    'error_code' => (string) ($download['error_code'] ?? 'download_failed'),
                ];
            }
            $local = $this->inspectLocalPackage($root);
        }

        if (! is_array($local)) {
            return [
                'ok' => false,
                'status' => 'download_failed',
                'error_code' => 'missing_entrypoint',
            ];
        }

        return ['ok' => true, 'package' => $local];
    }

    /**
     * @return array{package_version: string, canonical_hash: string}|null
     */
    public function inspectLocalPackage(?string $targetRoot = null): ?array
    {
        $root = $this->resolveRoot($targetRoot);
        $manifestPath = $root.'/manifest.json';
        $entrypoint = $root.'/payload/full-configuration.json';
        if (! is_file($manifestPath) || ! is_file($entrypoint)) {
            return null;
        }

        $manifestRaw = file_get_contents($manifestPath);
        if (! is_string($manifestRaw) || trim($manifestRaw) === '') {
            return null;
        }
        $manifest = json_decode($manifestRaw, true);
        if (! is_array($manifest)) {
            return null;
        }

        $version = (string) ($manifest['package_version'] ?? '');
        $statePackage = $this->state->read()['package'] ?? null;
        $hash = '';
        if (is_array($statePackage)
            && (string) ($statePackage['package_version'] ?? '') === $version
        ) {
            $hash = strtolower(trim((string) ($statePackage['canonical_hash'] ?? '')));
        }
        if ($hash === '') {
            $hash = strtolower(trim((string) ($manifest['canonical_hash'] ?? '')));
        }
        if ($version === '' || $hash === '') {
            return null;
        }

        return [
            'package_version' => $version,
            'canonical_hash' => $hash,
        ];
    }

    private function resolveRoot(?string $targetRoot): string
    {
        $root = $targetRoot ?? GoldenPackagePath::absolute();
        $logical = str_replace('\\', '/', $root);
        if ($targetRoot === null && ! str_ends_with($logical, GoldenPackagePath::RELATIVE)) {
            throw new ConfigurationSyncException(
                'golden_path_rejected',
                'Golden baseline must stay at resources/golden-configuration.',
            );
        }

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    private function recordPackageStaged(string $version, string $hash, bool $reused): void
    {
        $state = $this->state->read();
        $previous = is_array($state['package'] ?? null) ? $state['package'] : [];
        $state['package'] = [
            'status' => 'pending_apply',
            'package_version' => $version,
            'canonical_hash' => strtolower($hash),
            'downloaded_at' => $reused && isset($previous['downloaded_at'])
                ? $previous['downloaded_at']
                : gmdate('c'),
            'reused' => $reused,
        ];
        // Never mark bootstrap completed solely because download succeeded.
        $bootstrap = is_array($state['bootstrap'] ?? null) ? $state['bootstrap'] : [];
        if (($bootstrap['status'] ?? '') !== 'completed') {
            $state['bootstrap'] = array_merge($bootstrap, [
                'status' => 'pending_apply',
                'package_version' => $version,
                'canonical_hash' => strtolower($hash),
            ]);
        }
        $this->state->write($state);
    }

    /**
     * @param  array<string, mixed>  $packageMeta
     */
    private function recordApplyFailure(string $status, array $packageMeta, string $detail): void
    {
        $state = $this->state->read();
        $state['bootstrap'] = [
            'status' => $status,
            'package_version' => $packageMeta['package_version'] ?? null,
            'canonical_hash' => $packageMeta['canonical_hash'] ?? null,
            'detail' => $detail,
            'failed_at' => gmdate('c'),
        ];
        $package = is_array($state['package'] ?? null) ? $state['package'] : [];
        $state['package'] = array_merge($package, [
            'status' => 'pending_apply',
            'package_version' => $packageMeta['package_version'] ?? ($package['package_version'] ?? null),
            'canonical_hash' => $packageMeta['canonical_hash'] ?? ($package['canonical_hash'] ?? null),
        ]);
        $this->state->write($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function blocked(string $code): array
    {
        return [
            'status' => $code,
            'imported' => false,
            'downloaded' => false,
            'live_integration' => 'BLOCKED_ON_H1',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bindPackageHooks(array $payload): void
    {
        $prompts = $payload['prompts']['prompts'] ?? $payload['prompts'] ?? [];
        $hooks = [];
        foreach (is_array($prompts) ? $prompts : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hook = trim((string) ($row['hook'] ?? $row['hook_key'] ?? ''));
            if ($hook !== '') {
                $hooks[] = $hook;
            }
        }
        app()->instance(PromptPackService::class, new PromptPackService(knownHookKeys: array_values(array_unique($hooks))));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{prompts: int, workflows: int, bindings: int}
     */
    private function expectedInventory(array $payload): array
    {
        $prompts = $payload['prompts']['prompts'] ?? [];
        $tasks = $payload['tasks'] ?? [];
        $bindings = $payload['settings']['workflows']['prompt_bindings'] ?? [];

        return [
            'prompts' => is_array($prompts) ? count($prompts) : 0,
            'workflows' => is_array($tasks) ? count($tasks) : 0,
            'bindings' => is_array($bindings) ? count($bindings) : 0,
        ];
    }

    /**
     * @return array{version: string, addons_version: string}
     */
    private function clientVersions(): array
    {
        return [
            'version' => (string) config('configuration-sync.client_version', '12.50.0'),
            'addons_version' => (string) config('configuration-sync.addons_version', '0.1.0'),
        ];
    }
}
