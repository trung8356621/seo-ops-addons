<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigHttpResult;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigHttpSender;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigProtocol;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigSyncCrypto;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigSyncHttpTransport;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncException;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncStateStore;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\GoldenPackageInstaller;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredential;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredentialProvider;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\LiveConfigurationComparer;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\OutboundConfigurationGuard;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\ConfigurationCanonicalizer;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackageIntegrityVerifier;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackagePath;
use Tests\TestCase;

final class ConfigurationSyncProtocolTest extends TestCase
{
    private string $secret;

    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = ConfigSyncCrypto::base64UrlEncode('machine-secret-secret!!');
        $this->statePath = sys_get_temp_dir().'/seo-g2-state-'.uniqid('', true).'.json';
        config([
            'configuration-sync.require_https' => false,
            'configuration-sync.base_url' => 'http://ops.test',
            'configuration-sync.client_version' => '12.50.0',
            'configuration-sync.addons_version' => '0.1.0',
            'configuration-sync.state_path' => $this->statePath,
            'configuration-sync.schedule_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        @unlink($this->statePath.'.lock');
        parent::tearDown();
    }

    public function test_verified_package_download_promotes_only_after_checksum_and_canonical_hash(): void
    {
        $bundle = $this->bundleFromGolden();
        $target = $this->tempGolden();
        $installed = (new GoldenPackageInstaller())->stageAndPromote($bundle, $target);

        $verified = (new GoldenPackageIntegrityVerifier())->verify($target);
        $this->assertSame(ConfigProtocol::PACKAGE_ID, $installed['package_id']);
        $this->assertContains('payload/full-configuration.json', $verified['verified_files']);
        $this->assertSame($bundle['canonical_hash'], $installed['canonical_hash']);
        $this->deleteTree($target);
        $this->deleteTree($target.'.__previous');
    }

    public function test_tampered_package_is_rejected_and_target_is_unchanged(): void
    {
        $bundle = $this->bundleFromGolden();
        $bundle['files']['payload/prompts.json'] .= ' ';
        $target = $this->tempGolden();
        file_put_contents($target.'/KEEP', 'known-good');

        try {
            (new GoldenPackageInstaller())->stageAndPromote($bundle, $target);
            $this->fail('Tampered package was accepted.');
        } catch (ConfigurationSyncException $e) {
            $this->assertContains($e->errorCode, ['package_rejected', 'canonical_hash_mismatch', 'invalid_manifest']);
        }
        $this->assertSame('known-good', (string) file_get_contents($target.'/KEEP'));
        $this->deleteTree($target);
    }

    public function test_invalid_manifest_is_rejected(): void
    {
        $bundle = $this->bundleFromGolden();
        $manifest = json_decode($bundle['files']['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        $manifest['package_id'] = 'not-golden';
        $bundle['files']['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $bundle['package_id'] = 'not-golden';

        $this->expectException(ConfigurationSyncException::class);
        $this->expectExceptionMessage('identity');
        (new GoldenPackageInstaller())->stageAndPromote($bundle, $this->tempGolden());
    }

    public function test_path_traversal_is_rejected_before_write(): void
    {
        $bundle = $this->bundleFromGolden();
        $bundle['files']['../evil.php'] = '<?php echo 1;';
        $parent = sys_get_temp_dir().'/seo-g2-traversal-'.uniqid('', true);
        mkdir($parent, 0775, true);

        try {
            (new GoldenPackageInstaller())->stageAndPromote($bundle, $parent.'/golden-configuration');
            $this->fail('Traversal path was accepted.');
        } catch (ConfigurationSyncException $e) {
            $this->assertSame('path_traversal', $e->errorCode);
        }
        $this->assertFileDoesNotExist($parent.'/evil.php');
        $this->deleteTree($parent);
    }

    public function test_read_only_golden_directory_is_reported_without_a_different_path(): void
    {
        $bundle = $this->bundleFromGolden();
        $target = $this->tempGolden();
        file_put_contents($target.'/KEEP', 'known-good');
        $installer = new GoldenPackageInstaller(writableProbe: static fn (string $dir): bool => false);

        try {
            $installer->stageAndPromote($bundle, $target);
            $this->fail('Read-only Golden directory was overwritten.');
        } catch (ConfigurationSyncException $e) {
            $this->assertSame('golden_directory_read_only', $e->errorCode);
            $this->assertStringContainsString('resources/golden-configuration', $e->getMessage());
        }
        $this->assertSame('known-good', (string) file_get_contents($target.'/KEEP'));
        $this->deleteTree($target);
    }

    public function test_request_signature_uses_config_protocol_and_empty_body_digest(): void
    {
        $sender = new RecordingSender(function (string $method, string $url, array $headers, string $body): ConfigHttpResult {
            return $this->signedResponse(200, [
                'protocol' => ConfigProtocol::ID,
                'status' => 'published',
                'service' => 'seo',
                'package' => ['canonical_hash' => str_repeat('a', 64)],
            ], $headers);
        });
        $transport = $this->transport($sender);
        $transport->current();

        $call = $sender->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertSame('http://ops.test/api/v1/installations/ins_01/services/seo/config/current', $call['url']);
        $this->assertSame('', $call['body']);
        $this->assertSame(ConfigProtocol::ID, $call['headers']['X-Ops-Protocol']);
        $this->assertSame(ConfigProtocol::EMPTY_BODY_SHA256, $call['headers']['X-Ops-Content-SHA256']);
        $expected = ConfigSyncCrypto::sign(ConfigSyncCrypto::requestSigningString(
            'GET',
            '/api/v1/installations/ins_01/services/seo/config/current',
            $call['headers']['X-Ops-Timestamp'],
            $call['headers']['X-Ops-Nonce'],
            $call['headers']['X-Ops-Content-SHA256'],
        ), $this->secret);
        $this->assertTrue(ConfigSyncCrypto::signaturesMatch($expected, $call['headers']['X-Ops-Signature']));
        $raw = (string) file_get_contents($this->statePath);
        $this->assertStringNotContainsString($this->secret, $raw);
        $this->assertStringNotContainsString('machine-secret-secret!!', $raw);
    }

    public function test_missing_h1_credential_fails_before_http(): void
    {
        $sender = new RecordingSender(fn (): ConfigHttpResult => throw new \RuntimeException('http was called'));
        $transport = new ConfigSyncHttpTransport(new NullCredentialProvider(), $sender, new ConfigurationSyncStateStore($this->statePath));

        try {
            $transport->current();
            $this->fail('Missing credential did not fail.');
        } catch (ConfigurationSyncException $e) {
            $this->assertSame('blocked_on_h1', $e->errorCode);
        }
        $this->assertSame([], $sender->calls);
    }

    public function test_invalid_server_signature_is_rejected(): void
    {
        $sender = new RecordingSender(function (string $method, string $url, array $headers, string $body): ConfigHttpResult {
            $response = $this->signedResponse(200, ['protocol' => ConfigProtocol::ID, 'service' => 'seo'], $headers);
            $headersOut = $response->headers;
            $headersOut['X-Ops-Signature'] = 'v1=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

            return new ConfigHttpResult(200, $response->body, $headersOut);
        });

        $this->expectException(ConfigurationSyncException::class);
        $this->expectExceptionMessage('signature');
        $this->transport($sender)->current();
    }

    public function test_cross_installation_response_is_rejected(): void
    {
        $sender = new RecordingSender(function (string $method, string $url, array $headers, string $body): ConfigHttpResult {
            return $this->signedResponse(200, [
                'protocol' => ConfigProtocol::ID,
                'installation_id' => 'ins_other',
                'service' => 'seo',
            ], $headers);
        });

        try {
            $this->transport($sender)->current();
            $this->fail('Cross-installation response was accepted.');
        } catch (ConfigurationSyncException $e) {
            $this->assertSame('installation_mismatch', $e->errorCode);
        }
    }

    public function test_server_error_statuses_stay_distinct(): void
    {
        $cases = [
            401 => 'invalid_signature',
            403 => 'unlicensed_service',
            409 => 'idempotency_conflict',
            413 => 'payload_too_large',
            422 => 'credential_detected',
        ];
        foreach ($cases as $status => $code) {
            $sender = new RecordingSender(function (string $method, string $url, array $headers) use ($status, $code): ConfigHttpResult {
                if ($status === 401) {
                    return new ConfigHttpResult($status, json_encode(['code' => $code], JSON_THROW_ON_ERROR), []);
                }

                return $this->signedResponse($status, ['code' => $code, 'protocol' => ConfigProtocol::ID], $headers);
            });
            try {
                $this->transport($sender)->upload('{"ok":true}');
                $this->fail('Status '.$status.' was accepted.');
            } catch (ConfigurationSyncException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertSame($status, $e->httpStatus);
            }
        }
    }

    public function test_json_order_and_local_ids_are_not_semantic_and_ai_skeleton_is_classified(): void
    {
        $comparer = new LiveConfigurationComparer();
        $base = [
            'meta' => ['exported_at' => '2020-01-01T00:00:00Z'],
            'prompts' => ['schema_version' => '1.0', 'prompts' => [[
                'portable_uuid' => '3addbdae-d042-46a2-875a-a2bf3965931f',
                'name' => 'Prompt',
                'content' => 'Hello',
                'hook' => 'article.content.generate',
            ]]],
            'tasks' => [[
                'portable_uuid' => '3a68f26a-371a-4d11-83da-407501a658cc',
                'name' => 'Flow',
                'flow_data' => ['nodes' => [], 'edges' => [['id' => 'e1', 'source' => 'a', 'target' => 'b']]],
            ]],
            'settings' => ['ai' => ['preferred_provider' => 'openrouter', 'connections' => [[
                'connection_ref' => ['provider_key' => 'openrouter', 'connection_key' => 'new-ops'],
                'name' => 'new-ops',
                'status' => 'active',
                'credential' => ['configured' => true, 'exported' => false],
            ]]]],
        ];
        $reordered = [
            'settings' => $base['settings'],
            'tasks' => $base['tasks'],
            'prompts' => $base['prompts'],
            'meta' => ['exported_at' => '2099-01-01T00:00:00Z'],
        ];
        $same = $comparer->compare($base, $reordered);
        $this->assertSame('in_sync', $same['outcome']);

        $withIds = $base;
        $withIds['prompts']['prompts'][0]['id'] = 99;
        $withIds['prompts']['prompts'][0]['user_id'] = 4;
        $withIds['prompts']['prompts'][0]['created_at'] = '2026-01-01T00:00:00Z';
        $this->assertSame('in_sync', $comparer->compare($base, $withIds)['outcome']);

        $skeleton = $base;
        $skeleton['settings']['ai']['connections'][0]['credential']['configured'] = false;
        $skeleton['settings']['ai']['connections'][0]['status'] = 'inactive';
        $noise = $comparer->compare($base, $skeleton);
        $this->assertSame('in_sync', $noise['outcome']);
        $this->assertSame('non_semantic_envelope', $noise['reason']);

        $edited = $base;
        $edited['prompts']['prompts'][0]['content'] = 'Hellp';
        $promptDiff = $comparer->compare($base, $edited);
        $this->assertSame('customized', $promptDiff['outcome']);
        $this->assertSame('modified', $promptDiff['diff']['prompts'][0]['change_type']);

        $edge = $base;
        $edge['tasks'][0]['flow_data']['edges'][] = ['id' => 'e2', 'source' => 'b', 'target' => 'c'];
        $edgeDiff = $comparer->compare($base, $edge);
        $this->assertSame('modified', $edgeDiff['diff']['workflows'][0]['change_type']);

        $deleted = $base;
        $deleted['prompts']['prompts'] = [];
        $deleted['tasks'] = [];
        $deletion = $comparer->compare($base, $deleted);
        $this->assertSame('deleted', $deletion['diff']['prompts'][0]['change_type']);
        $this->assertSame('deleted', $deletion['diff']['workflows'][0]['change_type']);

        $unknown = $base;
        $unknown['settings']['ai']['connections'][0]['name'] = 'renamed-connection';
        $this->assertSame('comparison_unresolved', $comparer->compare($base, $unknown)['outcome']);
    }

    public function test_outbound_guard_rejects_secrets_and_schedule_stays_off(): void
    {
        $guard = new OutboundConfigurationGuard();
        $payload = [
            'protocol' => ConfigProtocol::ID,
            'request_id' => 'req_cfg_1',
            'installation_id' => 'ins_01',
            'service' => 'seo',
            'idempotency_key' => 'idem_sync_1',
            'client' => ['version' => '12.50.0', 'addons_version' => '0.1.0'],
            'golden_baseline' => ['package_id' => ConfigProtocol::PACKAGE_ID, 'package_version' => '1.0.0', 'canonical_hash' => str_repeat('a', 64)],
            'current_canonical_hash' => str_repeat('b', 64),
            'diff' => [
                'settings' => ['general' => ['default_content_language' => 'en']],
                'prompts' => [[
                    'portable_uuid' => '3addbdae-d042-46a2-875a-a2bf3965931f',
                    'name' => 'Prompt',
                    'hook_key' => 'article.content.generate',
                    'change_type' => 'modified',
                    'content_fingerprint' => str_repeat('c', 64),
                    'changes' => ['content' => ['old' => 'a', 'new' => 'b']],
                ]],
                'workflows' => [],
            ],
        ];
        $guard->assertUploadable($payload);

        $payload['diff']['prompts'][0]['changes']['content']['new'] = 'sk-abcdefghijklmnopqrstuvwxyz';
        try {
            $guard->assertUploadable($payload);
            $this->fail('Secret pattern was uploadable.');
        } catch (ConfigurationSyncException $e) {
            $this->assertSame('credential_detected', $e->errorCode);
        }

        $this->assertFalse((bool) config('configuration-sync.schedule_enabled'));
        $schedule = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('configuration-sync.schedule_enabled', $schedule);
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/SettingsTransfer/SeoSettingsBundleService.php');
        $this->assertStringNotContainsString('ConfigSyncHttpTransport', $runtime);
    }

    public function test_local_golden_canonical_hash_is_stable(): void
    {
        $bundle = $this->bundleFromGolden();
        $this->assertSame(64, strlen((string) $bundle['canonical_hash']));
        $again = (new ConfigurationCanonicalizer())->fingerprint(json_decode(
            (string) file_get_contents(GoldenPackagePath::entrypoint(base_path())),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
        $this->assertSame($bundle['canonical_hash'], $again);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    private function signedResponse(int $status, array $body, array $headers): ConfigHttpResult
    {
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($raw);
        $digest = ConfigSyncCrypto::contentSha256($raw);
        $timestamp = ConfigSyncCrypto::timestamp();
        $nonce = ConfigSyncCrypto::nonce();
        $signature = ConfigSyncCrypto::sign(ConfigSyncCrypto::responseSigningString(
            $status,
            (string) $headers['X-Ops-Nonce'],
            $timestamp,
            $nonce,
            $digest,
        ), $this->secret);

        return new ConfigHttpResult($status, $raw, [
            'X-Ops-Protocol' => ConfigProtocol::ID,
            'X-Ops-Credential-Id' => 'cred_01',
            'X-Ops-Timestamp' => $timestamp,
            'X-Ops-Nonce' => $nonce,
            'X-Ops-Content-SHA256' => $digest,
            'X-Ops-Signature' => $signature,
        ]);
    }

    private function transport(ConfigHttpSender $sender): ConfigSyncHttpTransport
    {
        return new ConfigSyncHttpTransport(
            new FixedCredentialProvider(new InstallationMachineCredential('cred_01', 'ins_01', $this->secret)),
            $sender,
            new ConfigurationSyncStateStore($this->statePath),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function bundleFromGolden(): array
    {
        $root = GoldenPackagePath::absolute(base_path());
        $files = [];
        foreach (ConfigProtocol::ALLOWED_FILES as $relative) {
            $files[$relative] = (string) file_get_contents($root.'/'.$relative);
        }
        $manifest = json_decode($files['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($files['payload/full-configuration.json'], true, 512, JSON_THROW_ON_ERROR);

        return [
            'protocol' => ConfigProtocol::ID,
            'package_id' => $manifest['package_id'],
            'package_version' => $manifest['package_version'],
            'canonical_hash' => (new ConfigurationCanonicalizer())->fingerprint($payload),
            'manifest' => $manifest,
            'files' => $files,
        ];
    }

    private function tempGolden(): string
    {
        $path = sys_get_temp_dir().'/seo-g2-golden-'.uniqid('', true);
        mkdir($path, 0775, true);

        return $path;
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

final class FixedCredentialProvider implements InstallationMachineCredentialProvider
{
    public function __construct(private readonly InstallationMachineCredential $credential) {}

    public function current(): ?InstallationMachineCredential
    {
        return $this->credential;
    }
}

final class NullCredentialProvider implements InstallationMachineCredentialProvider
{
    public function current(): ?InstallationMachineCredential
    {
        return null;
    }
}

final class RecordingSender implements ConfigHttpSender
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $calls = [];

    public int $syncFailures = 0;

    /**
     * @param  callable(string, string, array<string, string>, string): ConfigHttpResult  $handler
     */
    public function __construct(private $handler) {}

    public function send(string $method, string $url, array $headers, string $body): ConfigHttpResult
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        return ($this->handler)($method, $url, $headers, $body);
    }
}
