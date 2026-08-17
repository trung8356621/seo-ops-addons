<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;

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
            'ok' => true,
            'label' => 'Text endpoint',
            'detail' => ! empty($text['enabled'])
                ? 'Configured (generation not run)'
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
}
