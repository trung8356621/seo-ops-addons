<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

/**
 * Authenticated client for the four seo-ops-config/1 operations.
 */
final class ConfigSyncHttpTransport
{
    public function __construct(
        private readonly InstallationMachineCredentialProvider $credentials,
        private readonly ConfigHttpSender $sender,
        private readonly ConfigurationSyncStateStore $state,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return $this->call('GET', 'current', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function package(): array
    {
        return $this->call('GET', 'package', '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reportStatus(array $payload): array
    {
        return $this->call('POST', 'status', ConfigSyncCrypto::encodePayload($payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(string $rawBody): array
    {
        return $this->call('POST', 'sync', $rawBody);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $method, string $operation, string $rawBody): array
    {
        $credential = $this->credentials->current();
        if (! $credential instanceof InstallationMachineCredential) {
            throw ConfigurationSyncException::blockedOnH1();
        }
        if ($credential->credentialId() === '' || $credential->installationId() === '' || $credential->secretBase64Url() === '') {
            throw ConfigurationSyncException::blockedOnH1();
        }

        $base = rtrim((string) config('configuration-sync.base_url', ''), '/');
        if ($base === '') {
            throw new ConfigurationSyncException('server_unconfigured', 'OPS-SERVER configuration base URL is not configured.');
        }
        $requireHttps = (bool) config('configuration-sync.require_https', true);
        if ($requireHttps && ! str_starts_with(strtolower($base), 'https://')) {
            throw new ConfigurationSyncException('insecure_transport', 'Configuration sync requires HTTPS.');
        }

        $service = rawurlencode((string) config('configuration-sync.service_key', ConfigProtocol::SERVICE_KEY));
        $installation = rawurlencode($credential->installationId());
        $path = '/api/v1/installations/'.$installation.'/services/'.$service.'/config/'.$operation;
        $url = $base.$path;

        $timestamp = ConfigSyncCrypto::timestamp();
        $nonce = ConfigSyncCrypto::nonce();
        $digest = ConfigSyncCrypto::contentSha256($rawBody);
        if ($rawBody === '' && $digest !== ConfigProtocol::EMPTY_BODY_SHA256) {
            throw new ConfigurationSyncException('digest_mismatch', 'Empty-body content digest drifted from the protocol constant.');
        }
        $signing = ConfigSyncCrypto::requestSigningString($method, $path, $timestamp, $nonce, $digest);
        $signature = ConfigSyncCrypto::sign($signing, $credential->secretBase64Url());

        $headers = [
            'Content-Type' => 'application/json',
            'X-Ops-Protocol' => ConfigProtocol::ID,
            'X-Ops-Credential-Id' => $credential->credentialId(),
            'X-Ops-Timestamp' => $timestamp,
            'X-Ops-Nonce' => $nonce,
            'X-Ops-Content-SHA256' => $digest,
            'X-Ops-Signature' => $signature,
        ];

        try {
            $response = $this->sender->send($method, $url, $headers, $rawBody);
        } catch (ConfigurationSyncException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ConfigurationSyncException('server_offline', 'OPS-SERVER configuration endpoint is unreachable.');
        }

        return $this->verifyResponse($response, $credential, $nonce);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyResponse(
        ConfigHttpResult $response,
        InstallationMachineCredential $credential,
        string $requestNonce,
    ): array {
        $decoded = $this->decodeBody($response->body);
        $signed = $response->header('X-Ops-Signature') !== '';

        if (! $signed) {
            if ($response->status >= 200 && $response->status < 300) {
                throw new ConfigurationSyncException('invalid_signature', 'Server success response was not signed.', $response->status);
            }

            return $this->unsignedError($response->status, $decoded);
        }

        $protocol = $response->header('X-Ops-Protocol');
        $credentialId = $response->header('X-Ops-Credential-Id');
        $responseTimestamp = $response->header('X-Ops-Timestamp');
        $responseNonce = $response->header('X-Ops-Nonce');
        $contentSha = $response->header('X-Ops-Content-SHA256');
        $signature = $response->header('X-Ops-Signature');

        if ($protocol !== ConfigProtocol::ID || $credentialId === '' || $responseTimestamp === '' || $responseNonce === '' || $contentSha === '') {
            throw new ConfigurationSyncException('invalid_signature', 'Signed server response is missing protocol correlation headers.', $response->status);
        }
        if (! hash_equals($credential->credentialId(), $credentialId)) {
            throw new ConfigurationSyncException('installation_mismatch', 'Server response credential does not match this installation.', $response->status);
        }
        if (! hash_equals(ConfigSyncCrypto::contentSha256($response->body), $contentSha)) {
            throw new ConfigurationSyncException('invalid_signature', 'Server response body digest does not match.', $response->status);
        }
        if (! $this->timestampFresh($responseTimestamp)) {
            throw new ConfigurationSyncException('request_expired', 'Server response timestamp is outside the freshness window.', $response->status);
        }
        $this->state->recordResponseNonce($credentialId, $responseNonce);

        $expected = ConfigSyncCrypto::sign(
            ConfigSyncCrypto::responseSigningString($response->status, $requestNonce, $responseTimestamp, $responseNonce, $contentSha),
            $credential->secretBase64Url(),
        );
        if (! ConfigSyncCrypto::signaturesMatch($expected, $signature)) {
            throw new ConfigurationSyncException('invalid_signature', 'Server response signature was rejected.', $response->status);
        }

        $this->assertBodyCorrelation($decoded, $credential);

        if ($response->status >= 400) {
            $code = is_string($decoded['code'] ?? null) ? (string) $decoded['code'] : 'server_error';

            throw new ConfigurationSyncException($code, 'Configuration sync was rejected by OPS-SERVER.', $response->status);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function unsignedError(int $status, array $decoded): array
    {
        $code = is_string($decoded['code'] ?? null) ? (string) $decoded['code'] : 'server_error';
        throw new ConfigurationSyncException($code, 'Configuration sync was rejected by OPS-SERVER.', $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConfigurationSyncException('invalid_payload', 'Server response was not valid JSON.');
        }
        if (! is_array($decoded)) {
            throw new ConfigurationSyncException('invalid_payload', 'Server response JSON must be an object.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function assertBodyCorrelation(array $decoded, InstallationMachineCredential $credential): void
    {
        $protocol = $decoded['protocol'] ?? null;
        if (is_string($protocol) && $protocol !== ConfigProtocol::ID) {
            throw new ConfigurationSyncException('unsupported_protocol', 'Server response protocol does not match seo-ops-config/1.');
        }
        $installation = $decoded['installation_id'] ?? null;
        if (is_string($installation) && $installation !== '' && ! hash_equals($credential->installationId(), $installation)) {
            throw new ConfigurationSyncException('installation_mismatch', 'Server response belongs to a different installation.');
        }
        $service = $decoded['service'] ?? null;
        if (is_string($service) && $service !== '' && $service !== ConfigProtocol::SERVICE_KEY) {
            throw new ConfigurationSyncException('service_not_supported', 'Server response service key is not seo.');
        }
    }

    private function timestampFresh(string $timestamp): bool
    {
        $parsed = strtotime($timestamp);
        if ($parsed === false) {
            return false;
        }
        $skew = abs(time() - $parsed);

        return $skew <= 300;
    }

}
