<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Media\Support\ImagenProviderErrorClassifier;
use Tests\TestCase;

final class ImagenProviderErrorClassifierTest extends TestCase
{
    public function test_fail_to_execute_anonymous_is_transient_and_retryable(): void
    {
        $raw = "Image generation failed:\nmodel public: imagen-4.0-generate-001\nerror: fail to execute model\ninternal endpoint: /vertex/vertex-imagen-jpe-v1-8\ntarget=anonymous server";

        $presented = ImagenProviderErrorClassifier::present($raw);

        $this->assertSame(ImagenProviderErrorClassifier::PROVIDER_TRANSIENT, $presented['classification']);
        $this->assertTrue($presented['retryable']);
        $this->assertStringContainsString('Imagen', $presented['user_message']);
        $this->assertStringContainsString('vertex-imagen-jpe-v1-8', $presented['technical_details']);
        $this->assertStringNotContainsString('alert', mb_strtolower($presented['user_message']));
    }

    public function test_authentication_not_retryable(): void
    {
        $presented = ImagenProviderErrorClassifier::present('API key not valid. Please pass a valid API key.', 401);

        $this->assertSame(ImagenProviderErrorClassifier::AUTHENTICATION_ERROR, $presented['classification']);
        $this->assertFalse($presented['retryable']);
    }

    public function test_invalid_model_not_retryable(): void
    {
        $presented = ImagenProviderErrorClassifier::present('models/imagen-9.0-generate-001 is not found', 404);

        $this->assertSame(ImagenProviderErrorClassifier::INVALID_MODEL, $presented['classification']);
        $this->assertFalse($presented['retryable']);
    }

    public function test_rate_limit_retryable(): void
    {
        $presented = ImagenProviderErrorClassifier::present('Resource exhausted', 429);

        $this->assertSame(ImagenProviderErrorClassifier::PROVIDER_RATE_LIMIT, $presented['classification']);
        $this->assertTrue($presented['retryable']);
    }

    public function test_redact_api_key_from_url(): void
    {
        $redacted = ImagenProviderErrorClassifier::redactSecrets(
            'https://generativelanguage.googleapis.com/v1beta/models/x:predict?key=SECRET123&alt=json',
        );

        $this->assertStringContainsString('key=[REDACTED]', $redacted);
        $this->assertStringNotContainsString('SECRET123', $redacted);
    }

    public function test_codebase_does_not_hardcode_vertex_imagen_internal_id(): void
    {
        $path = ProjectRoot::addonsPath().'/media/src'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'GeminiMediaGenerationService.php';
        $source = (string) file_get_contents($path);

        $this->assertStringNotContainsString('vertex-imagen-jpe-v1-8', $source);
        $this->assertStringContainsString('generativelanguage.googleapis.com', $source);
        $this->assertStringContainsString(':predict', $source);
        $this->assertStringContainsString('api_key_query', $source);
    }
}
