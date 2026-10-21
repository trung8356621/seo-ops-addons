<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

use Omnichannel\Addons\Seo\Services\SettingsTransfer\ConfigurationSyncAuthorization;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackagePath;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;

/**
 * Compare and upstream sync. Not called from prompt, article, workflow, publishing, scoring, or seeding execution.
 */
final class ConfigurationSyncCoordinator
{
    public function __construct(
        private readonly InstallationMachineCredentialProvider $credentials,
        private readonly ConfigSyncHttpTransport $transport,
        private readonly LiveConfigurationComparer $comparer,
        private readonly OutboundConfigurationGuard $guard,
        private readonly ConfigurationSyncStateStore $state,
        private readonly SeoSettingsBundleService $bundles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compare(int $userId, ?string $goldenRoot = null): array
    {
        $root = $goldenRoot ?? GoldenPackagePath::absolute();
        $golden = $this->readGolden($root);
        $live = $this->bundles->exportForConfigurationSync(
            $userId,
            ConfigurationSyncAuthorization::forReadExport(),
        );
        $result = $this->comparer->compare($golden, $live);
        $state = $this->state->read();
        $state['last_compare'] = [
            'outcome' => $result['outcome'],
            'reason' => $result['reason'],
            'baseline_hash' => $result['baseline_hash'],
            'current_hash' => $result['current_hash'],
            'compared_at' => gmdate('c'),
        ];
        $this->state->write($state);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(int $userId, ?string $goldenRoot = null): array
    {
        $comparison = $this->compare($userId, $goldenRoot);
        if ($comparison['outcome'] === 'comparison_unresolved') {
            return [
                'status' => 'comparison_unresolved',
                'uploaded' => false,
                'comparison' => $comparison,
            ];
        }

        $credential = $this->credentials->current();
        if ($credential === null) {
            return [
                'status' => 'blocked_on_h1',
                'uploaded' => false,
                'live_integration' => 'BLOCKED_ON_H1',
                'comparison' => $comparison,
            ];
        }

        if ($comparison['outcome'] === 'in_sync') {
            return $this->reportInSync($credential->installationId(), $comparison, $goldenRoot);
        }

        return $this->uploadChanges($credential->installationId(), $comparison, $goldenRoot);
    }

    /**
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function reportInSync(string $installationId, array $comparison, ?string $goldenRoot): array
    {
        $manifest = $this->readManifest($goldenRoot);
        try {
            $ack = $this->transport->reportStatus([
                'protocol' => ConfigProtocol::ID,
                'installation_id' => $installationId,
                'service' => ConfigProtocol::SERVICE_KEY,
                'client' => $this->clientVersions(),
                'golden_baseline' => [
                    'package_id' => ConfigProtocol::PACKAGE_ID,
                    'package_version' => (string) ($manifest['package_version'] ?? ''),
                    'canonical_hash' => (string) $comparison['baseline_hash'],
                ],
                'status' => 'in_sync',
                'current_canonical_hash' => (string) $comparison['current_hash'],
            ]);
        } catch (ConfigurationSyncException $e) {
            return [
                'status' => 'deferred',
                'uploaded' => false,
                'error_code' => $e->errorCode,
                'comparison' => $comparison,
            ];
        }

        return [
            'status' => 'in_sync',
            'uploaded' => false,
            'ack' => $ack,
            'comparison' => $comparison,
        ];
    }

    /**
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function uploadChanges(string $installationId, array $comparison, ?string $goldenRoot): array
    {
        $manifest = $this->readManifest($goldenRoot);
        try {
            $prepared = $this->state->exclusive(function (array &$state) use ($installationId, $comparison, $manifest): array {
            $pending = is_array($state['pending'] ?? null) ? $state['pending'] : null;
            if (is_array($pending) && is_string($pending['body'] ?? null) && is_string($pending['idempotency_key'] ?? null)) {
                $nextAt = strtotime((string) ($pending['next_attempt_at'] ?? ''));
                if ($nextAt !== false && $nextAt > time()) {
                    return ['deferred' => true, 'body' => (string) $pending['body'], 'idempotency_key' => (string) $pending['idempotency_key']];
                }

                return ['deferred' => false, 'body' => (string) $pending['body'], 'idempotency_key' => (string) $pending['idempotency_key']];
            }

            $idempotency = 'idem_sync_'.bin2hex(random_bytes(8));
            $payload = [
                'protocol' => ConfigProtocol::ID,
                'request_id' => 'req_cfg_'.substr(hash('sha256', $idempotency), 0, 12),
                'installation_id' => $installationId,
                'service' => ConfigProtocol::SERVICE_KEY,
                'idempotency_key' => $idempotency,
                'client' => $this->clientVersions(),
                'golden_baseline' => [
                    'package_id' => ConfigProtocol::PACKAGE_ID,
                    'package_version' => (string) ($manifest['package_version'] ?? ''),
                    'canonical_hash' => (string) $comparison['baseline_hash'],
                ],
                'current_canonical_hash' => (string) $comparison['current_hash'],
                'diff' => is_array($comparison['diff'] ?? null) ? $comparison['diff'] : [],
            ];
            if ($payload['diff']['settings'] === []) {
                $payload['diff']['settings'] = new \stdClass();
            }
            $this->guard->assertUploadable($this->guardPayload($payload));
            $body = ConfigSyncCrypto::encodePayload($payload);
            $state['pending'] = [
                'idempotency_key' => $idempotency,
                'body' => $body,
                'canonical_hash' => (string) $comparison['current_hash'],
                'attempts' => 0,
                'next_attempt_at' => gmdate('c'),
            ];

            return ['deferred' => false, 'body' => $body, 'idempotency_key' => $idempotency];
            });
        } catch (ConfigurationSyncException $e) {
            return [
                'status' => 'rejected',
                'uploaded' => false,
                'error_code' => $e->errorCode,
                'http_status' => $e->httpStatus,
                'comparison' => $comparison,
            ];
        }

        if (($prepared['deferred'] ?? false) === true) {
            return [
                'status' => 'deferred',
                'uploaded' => false,
                'idempotency_key' => $prepared['idempotency_key'],
                'comparison' => $comparison,
            ];
        }

        try {
            $ack = $this->transport->upload((string) $prepared['body']);
        } catch (ConfigurationSyncException $e) {
            $this->recordFailure($e);

            return [
                'status' => $this->failureStatus($e),
                'uploaded' => false,
                'error_code' => $e->errorCode,
                'http_status' => $e->httpStatus,
                'idempotency_key' => $prepared['idempotency_key'],
                'comparison' => $comparison,
            ];
        }

        $receiptStatus = (string) ($ack['status'] ?? '');
        if (! in_array($receiptStatus, ConfigProtocol::ACCEPTED_RECEIPT_STATUSES, true)) {
            return [
                'status' => 'ack_rejected',
                'uploaded' => false,
                'ack' => $ack,
                'comparison' => $comparison,
            ];
        }

        $this->state->exclusive(function (array &$state) use ($ack, $prepared): bool {
            $state['last_ack'] = [
                'receipt_id' => (string) ($ack['receipt_id'] ?? ''),
                'status' => (string) ($ack['status'] ?? ''),
                'idempotency_key' => (string) $prepared['idempotency_key'],
                'acked_at' => gmdate('c'),
            ];
            $state['pending'] = null;

            return true;
        });

        return [
            'status' => 'uploaded',
            'uploaded' => true,
            'ack' => $ack,
            'idempotency_key' => $prepared['idempotency_key'],
            'body' => $prepared['body'],
            'comparison' => $comparison,
        ];
    }

    private function recordFailure(ConfigurationSyncException $e): void
    {
        $terminal = in_array($e->errorCode, ['idempotency_conflict', 'payload_too_large', 'credential_detected', 'invalid_payload', 'missing_component_identity'], true)
            || in_array($e->httpStatus, [409, 413, 422], true);
        $this->state->exclusive(function (array &$state) use ($e, $terminal): bool {
            $pending = is_array($state['pending'] ?? null) ? $state['pending'] : null;
            if (! is_array($pending)) {
                return false;
            }
            if ($terminal) {
                $state['pending'] = null;
                $state['last_ack'] = [
                    'status' => 'rejected',
                    'error_code' => $e->errorCode,
                    'http_status' => $e->httpStatus,
                ];

                return true;
            }
            $attempts = (int) ($pending['attempts'] ?? 0) + 1;
            $pending['attempts'] = $attempts;
            $delay = min(60, 2 ** min(6, $attempts));
            $pending['next_attempt_at'] = gmdate('c', time() + $delay);
            $pending['last_error'] = $e->errorCode;
            $state['pending'] = $pending;

            return true;
        });
    }

    private function failureStatus(ConfigurationSyncException $e): string
    {
        return match ($e->httpStatus) {
            401 => 'unauthorized',
            403 => 'forbidden',
            409 => 'conflict',
            413 => 'payload_too_large',
            422 => 'rejected',
            default => 'deferred',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function guardPayload(array $payload): array
    {
        $copy = $payload;
        if ($copy['diff']['settings'] instanceof \stdClass) {
            $copy['diff']['settings'] = [];
        }

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    private function readGolden(string $root): array
    {
        $path = rtrim(str_replace('\\', '/', $root), '/').'/payload/full-configuration.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($raw)) {
            throw new ConfigurationSyncException('golden_missing', 'Local Golden baseline is missing at the fixed path.');
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new ConfigurationSyncException('golden_missing', 'Local Golden baseline is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(?string $goldenRoot): array
    {
        $root = $goldenRoot ?? GoldenPackagePath::absolute();
        $path = rtrim(str_replace('\\', '/', $root), '/').'/manifest.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
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
