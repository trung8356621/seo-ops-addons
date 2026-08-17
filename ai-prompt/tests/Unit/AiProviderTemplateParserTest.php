<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderOutboundUrlPolicy;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderSecureHttpClient;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateParser;
use PHPUnit\Framework\TestCase;

final class AiProviderTemplateParserTest extends TestCase
{
    public function test_valid_template_strips_documentation_keys(): void
    {
        $json = json_encode([
            'schema_version' => '1.0',
            '_ai_instruction' => 'ignore me',
            '_guide' => ['purpose' => 'docs'],
            'provider' => [
                'key' => 'example-ai',
                'name' => 'Example AI',
                'protocol' => 'openai_compatible',
            ],
            'connection' => [
                'base_url' => 'https://example.com/v1',
                'auth' => ['type' => 'bearer'],
            ],
            'endpoints' => [
                'models' => [
                    'enabled' => true,
                    'method' => 'GET',
                    'path' => '/models',
                    'response' => ['items_path' => 'data', 'id_path' => 'id', 'name_path' => 'id'],
                ],
                'text' => ['enabled' => true, 'method' => 'POST', 'path' => '/chat/completions'],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = (new AiProviderTemplateParser())->parse($json);
        $stored = $parsed->toStorageArray();
        $this->assertArrayNotHasKey('_ai_instruction', $stored);
        $this->assertSame('example-ai', $parsed->providerKey);
        $this->assertSame('https://example.com/v1', $parsed->baseUrl);
        $this->assertSame('GET /models', $parsed->preview()['model_discovery']);
    }

    public function test_downloadable_template_is_valid_json_and_has_ai_instruction(): void
    {
        $raw = (new AiProviderTemplateCatalog())->downloadableDocument();
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('_ai_instruction', $data);
        $this->assertSame('1.0', $data['schema_version']);
        $this->assertSame('ai_provider_template', $data['package_type']);
        $this->assertStringContainsString('Never insert API keys', (string) $data['_ai_instruction']);
    }

    public function test_invalid_json_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse('{not json');
    }

    public function test_unsupported_schema_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $this->expectExceptionMessage('Unsupported Provider Template version');
        (new AiProviderTemplateParser())->parse($this->minimal(['schema_version' => '9.9']));
    }

    public function test_unknown_protocol_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $this->expectExceptionMessage('unsupported protocol');
        (new AiProviderTemplateParser())->parse($this->minimal([
            'provider' => ['key' => 'x-ai', 'name' => 'X', 'protocol' => 'abc_exec'],
        ]));
    }

    public function test_missing_provider_name_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse($this->minimal([
            'provider' => ['key' => 'x-ai', 'name' => '', 'protocol' => 'openai_compatible'],
        ]));
    }

    public function test_dangerous_eval_field_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $this->expectExceptionMessage('dangerous field');
        (new AiProviderTemplateParser())->parse($this->minimal(['eval' => 'system(1)']));
    }

    public function test_php_class_field_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse($this->minimal(['handler_class' => 'App\\Evil']));
    }

    public function test_duplicate_base_url_keys_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $raw = '{"schema_version":"1.0","provider":{"key":"x-ai","name":"X","protocol":"openai_compatible"},"connection":{"base_url":"https://example.com","auth":{"type":"bearer"},"base_url":"https://evil.example"},"endpoints":{"models":{"enabled":false},"text":{"enabled":false},"image":{"enabled":false},"video":{"enabled":false}}}';
        (new AiProviderTemplateParser())->parse($raw);
    }

    public function test_secret_api_key_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $this->expectExceptionMessage('API credentials must not be stored');
        (new AiProviderTemplateParser())->parse($this->minimal(['api_key' => 'sk-live-not-a-real-secret-value']));
    }

    public function test_bearer_authorization_value_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse($this->minimal([
            'connection' => [
                'base_url' => 'https://example.com/v1',
                'auth' => ['type' => 'bearer'],
                'headers' => ['authorization' => 'Bearer sk-live-not-a-real-secret-value'],
            ],
        ]));
    }

    public function test_crlf_header_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse($this->minimal([
            'connection' => [
                'base_url' => 'https://example.com/v1',
                'auth' => ['type' => 'bearer'],
                'headers' => ["x-api-key\r\nHost" => 'evil'],
            ],
        ]));
    }

    public function test_host_header_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse($this->minimal([
            'connection' => [
                'base_url' => 'https://example.com/v1',
                'auth' => ['type' => 'bearer'],
                'headers' => ['host' => 'evil.internal'],
            ],
        ]));
    }

    public function test_content_length_header_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse($this->minimal([
            'connection' => [
                'base_url' => 'https://example.com/v1',
                'auth' => ['type' => 'bearer'],
                'headers' => ['content-length' => '999999'],
            ],
        ]));
    }

    public function test_cookie_header_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse($this->minimal([
            'connection' => [
                'base_url' => 'https://example.com/v1',
                'auth' => ['type' => 'bearer'],
                'headers' => ['cookie' => 'sid=1'],
            ],
        ]));
    }

    public function test_delete_method_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse($this->minimal([
            'endpoints' => [
                'models' => ['enabled' => true, 'method' => 'DELETE', 'path' => '/models'],
                'text' => ['enabled' => false],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ]));
    }

    public function test_oversized_file_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $this->expectExceptionMessage('maximum size');
        (new AiProviderTemplateParser())->parse(str_repeat('a', 70000));
    }

    public function test_excessive_nesting_rejected(): void
    {
        $nested = ['schema_version' => '1.0', 'provider' => ['key' => 'x-ai', 'name' => 'X', 'protocol' => 'openai_compatible']];
        $cursor = &$nested;
        for ($i = 0; $i < 20; $i++) {
            $cursor['child'] = [];
            $cursor = &$cursor['child'];
        }
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderTemplateParser())->parse(json_encode($nested));
    }

    public function test_huge_array_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        $data = json_decode($this->minimal(), true);
        $data['connection']['headers'] = array_fill(0, 50, 'x');
        (new AiProviderTemplateParser())->parse(json_encode($data));
    }

    public function test_ssrf_ip_literals_rejected(): void
    {
        $policy = new AiProviderOutboundUrlPolicy();
        foreach ([
            'https://127.0.0.1',
            'https://localhost',
            'https://10.0.0.1',
            'https://192.168.1.1',
            'https://169.254.169.254',
            'https://[::1]',
            'file:///etc/passwd',
            'ftp://example.com',
            'https://user:pass@example.com',
        ] as $url) {
            try {
                $policy->assertSafeUrl($url);
                $this->fail('Expected rejection for '.$url);
            } catch (AiProviderTemplateException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_relative_path_rejects_absolute_url_and_traversal(): void
    {
        $policy = new AiProviderOutboundUrlPolicy();
        $this->expectException(AiProviderTemplateException::class);
        $policy->assertRelativePath('https://evil.example/models');
    }

    public function test_path_traversal_rejected(): void
    {
        $this->expectException(AiProviderTemplateException::class);
        (new AiProviderOutboundUrlPolicy())->assertRelativePath('../admin');
    }

    public function test_http_client_disables_redirects(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(AiProviderSecureHttpClient::class))->getFileName());
        $this->assertStringContainsString("'allow_redirects' => false", $src);
        $this->assertStringContainsString('assertSafeUrl', $src);
        $this->assertStringContainsString('redact', $src);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function minimal(array $overrides = []): string
    {
        $base = [
            'schema_version' => '1.0',
            'provider' => [
                'key' => 'example-ai',
                'name' => 'Example AI',
                'protocol' => 'openai_compatible',
            ],
            'connection' => [
                'base_url' => 'https://example.com/v1',
                'auth' => ['type' => 'bearer'],
            ],
            'endpoints' => [
                'models' => ['enabled' => false],
                'text' => ['enabled' => false],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ];

        return json_encode(array_replace_recursive($base, $overrides), JSON_THROW_ON_ERROR);
    }
}
