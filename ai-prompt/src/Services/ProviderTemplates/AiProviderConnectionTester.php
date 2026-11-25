<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;

final class AiProviderConnectionTester
{
    public function __construct(
        private readonly OpenAiCompatibleProtocolAdapter $adapter = new OpenAiCompatibleProtocolAdapter(),
        private readonly AiProviderOutboundUrlPolicy $urls = new AiProviderOutboundUrlPolicy(),
    ) {}

    /**
     * @return array<string, array{ok: bool, label: string, detail: string}>
     */
    public function test(ApiConnection $connection): array
    {
        $template = $this->adapter->templateForConnection($connection);
        $stages = [];

        try {
            $this->urls->assertSafeUrl($template->baseUrl);
            $stages['base_url'] = ['ok' => true, 'label' => 'Base URL', 'detail' => $template->baseUrl];
        } catch (AiProviderTemplateException $exception) {
            $stages['base_url'] = ['ok' => false, 'label' => 'Base URL', 'detail' => $exception->getMessage()];

            return $stages;
        }

        $hasKey = filled($connection->api_key);
        $stages['authentication'] = [
            'ok' => $hasKey,
            'label' => 'Authentication',
            'detail' => $hasKey ? 'API key present' : 'API key missing',
        ];

        $models = $template->endpoints['models'] ?? [];
        if (empty($models['enabled'])) {
            $stages['model_discovery'] = ['ok' => true, 'label' => 'Model discovery', 'detail' => 'Not configured'];
        } else {
            try {
                $list = $this->adapter->listModels($connection);
                $stages['model_discovery'] = [
                    'ok' => true,
                    'label' => 'Model discovery',
                    'detail' => count($list).' models',
                ];
            } catch (\Throwable $exception) {
                $stages['model_discovery'] = [
                    'ok' => false,
                    'label' => 'Model discovery',
                    'detail' => AiProviderSecureHttpClient::redact($exception->getMessage()),
                ];
            }
        }

        $text = $template->endpoints['text'] ?? [];
        $stages['text'] = [
            'ok' => ! empty($text['enabled']),
            'label' => 'Text endpoint',
            'detail' => ! empty($text['enabled'])
                ? 'Configured only — inference not verified (use Test AI Generation)'
                : 'Not configured',
        ];
        $image = $template->endpoints['image'] ?? [];
        $stages['image'] = [
            'ok' => true,
            'label' => 'Image endpoint',
            'detail' => ! empty($image['enabled']) ? 'Configured' : 'Not configured',
        ];
        $video = $template->endpoints['video'] ?? [];
        $stages['video'] = [
            'ok' => true,
            'label' => 'Video endpoint',
            'detail' => ! empty($video['enabled']) ? 'Configured' : 'Not configured',
        ];

        return $stages;
    }

    /**
     * Opt-in smoke inference. Never called from save/open-page paths.
     *
     * @return array{ok: bool, http_status: int|null, detail: string, preview: string}
     */
    public function testGeneration(ApiConnection $connection, string $model, string $prompt = 'Reply with exactly: ok'): array
    {
        $model = trim($model);
        $prompt = trim($prompt) !== '' ? trim($prompt) : 'Reply with exactly: ok';
        if ($model === '') {
            return [
                'ok' => false,
                'http_status' => null,
                'detail' => 'Model is required for Test AI Generation.',
                'preview' => '',
            ];
        }
        if (! filled($connection->api_key)) {
            return [
                'ok' => false,
                'http_status' => null,
                'detail' => 'API key missing.',
                'preview' => '',
            ];
        }

        try {
            $template = $this->adapter->templateForConnection($connection);
            $this->urls->assertSafeUrl($template->baseUrl);
            [$text, $usage] = $this->adapter->generate($connection, $prompt, $model, [
                'max_output' => 32,
                'temperature' => 0,
            ]);
            unset($usage);
            $preview = mb_substr(trim($text), 0, 240);

            return [
                'ok' => true,
                'http_status' => 200,
                'detail' => 'Inference succeeded.',
                'preview' => $preview,
            ];
        } catch (PromptRunException $exception) {
            $http = null;
            if (isset($exception->context['http_status']) && is_numeric($exception->context['http_status'])) {
                $http = (int) $exception->context['http_status'];
            } elseif ($exception->getCode() > 0) {
                $http = (int) $exception->getCode();
            }

            return [
                'ok' => false,
                'http_status' => $http,
                'detail' => AiProviderSecureHttpClient::redact($exception->getMessage()),
                'preview' => '',
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'http_status' => null,
                'detail' => AiProviderSecureHttpClient::redact($exception->getMessage()),
                'preview' => '',
            ];
        }
    }
}
