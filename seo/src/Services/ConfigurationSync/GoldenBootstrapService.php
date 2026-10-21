<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackagePath;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SystemBootstrapAuthorization;

/**
 * Blank-client Golden import. Refuses any installation that already has configuration.
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(int $userId, ?string $targetRoot = null): array
    {
        $credential = $this->credentials->current();
        if ($credential === null) {
            return $this->blocked('blocked_on_h1');
        }
        if (! $this->entitlement->seoServiceEnabled()) {
            return $this->blocked('unlicensed_service');
        }

        $assessment = $this->blank->assess($userId);
        if ($assessment['blank'] !== true) {
            return [
                'status' => 'existing_client',
                'imported' => false,
                'signals' => $assessment['signals'],
            ];
        }

        $root = $targetRoot ?? GoldenPackagePath::absolute();
        $logical = str_replace('\\', '/', $root);
        if ($targetRoot === null && ! str_ends_with($logical, GoldenPackagePath::RELATIVE)) {
            throw new ConfigurationSyncException(
                'golden_path_rejected',
                'Golden baseline must stay at resources/golden-configuration.',
            );
        }

        try {
            $current = $this->transport->current();
            $bundle = $this->transport->package();
        } catch (ConfigurationSyncException $e) {
            return [
                'status' => 'download_failed',
                'imported' => false,
                'error_code' => $e->errorCode,
            ];
        }
        $publishedHash = (string) ($current['package']['canonical_hash'] ?? '');
        $bundleHash = (string) ($bundle['canonical_hash'] ?? '');
        if ($publishedHash === '' || ! hash_equals($publishedHash, $bundleHash)) {
            return [
                'status' => 'download_failed',
                'imported' => false,
                'error_code' => 'canonical_hash_mismatch',
            ];
        }

        try {
            $installed = $this->installer->stageAndPromote($bundle, $root);
        } catch (ConfigurationSyncException $e) {
            return [
                'status' => 'download_failed',
                'imported' => false,
                'error_code' => $e->errorCode,
            ];
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

        $this->bindPackageHooks($payload);
        $plan = $this->bundles->parseAndPlan($raw, $userId, 'merge');
        $this->bundles->applyForSystemBootstrap(
            $plan,
            $userId,
            SystemBootstrapAuthorization::forGoldenConfigurationBootstrap(),
        );

        $expected = $this->expectedInventory($payload);
        $actual = [
            'prompts' => SeoPrompt::query()->where('user_id', $userId)->count(),
            'workflows' => SeoTask::query()->where('user_id', $userId)->count(),
            'bindings' => count(app(SeoCreateArticleSettingsService::class)->getPromptHookBindings()),
        ];
        if ($actual !== $expected) {
            $this->state->write(array_merge($this->state->read(), [
                'bootstrap' => [
                    'status' => 'validation_failed',
                    'expected' => $expected,
                    'actual' => $actual,
                    'package_version' => $installed['package_version'],
                ],
            ]));

            return [
                'status' => 'validation_failed',
                'imported' => true,
                'expected' => $expected,
                'actual' => $actual,
            ];
        }

        $report = [
            'status' => 'bootstrapped',
            'imported' => true,
            'package_version' => $installed['package_version'],
            'canonical_hash' => $installed['canonical_hash'],
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
                    'package_version' => $installed['package_version'],
                    'canonical_hash' => $installed['canonical_hash'],
                ],
                'status' => 'in_sync',
                'current_canonical_hash' => $installed['canonical_hash'],
            ]);
            $report['server_status'] = 'acknowledged';
        } catch (ConfigurationSyncException $e) {
            $report['server_status'] = 'deferred';
            $report['server_error'] = $e->errorCode;
        }

        $state = $this->state->read();
        $state['bootstrap'] = [
            'status' => 'completed',
            'package_version' => $installed['package_version'],
            'canonical_hash' => $installed['canonical_hash'],
            'completed_at' => gmdate('c'),
            'server_status' => $report['server_status'],
        ];
        $this->state->write($state);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function blocked(string $code): array
    {
        return [
            'status' => $code,
            'imported' => false,
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
