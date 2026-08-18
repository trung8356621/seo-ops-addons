<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Support\AiProviderProtocol;

final class OpenAiCompatibleProtocolAdapter
{
    public function __construct(
        private readonly ProviderConnectionResolver $resolver = new ProviderConnectionResolver(),
        private readonly AiProviderSecureHttpClient $http = new AiProviderSecureHttpClient(),
        private readonly AiProviderDotPath $paths = new AiProviderDotPath(),
    ) {}

    public function templateForConnection(ApiConnection $connection): NormalizedAiProviderTemplate
    {
        return $this->resolver->resolve($connection)->effectiveTemplate();
    }

    /**
     * @return list<array{id: string, display_name: string, metadata: array<string, mixed>}>
     */
    public function listModels(ApiConnection $connection): array
    {
        $template = $this->templateForConnection($connection);
        $response = $this->http->request($template, 'models', (string) $connection->api_key);
        if (! $response->successful()) {
            throw new PromptRunException(
                'Model discovery failed ('.$response->status().'): '.AiProviderSecureHttpClient::redact($response->body()),
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw AiProviderTemplateException::rejected('Model discovery failed: response is not JSON.');
        }

        $mapping = $template->endpoints['models']['response'] ?? [];
        $itemsPath = (string) ($mapping['items_path'] ?? 'data');
        $idPath = (string) ($mapping['id_path'] ?? 'id');
        $namePath = (string) ($mapping['name_path'] ?? 'id');
        $items = $this->paths->get($json, $itemsPath);
        if (! is_array($items)) {
            throw AiProviderTemplateException::rejected('Model discovery failed: expected model list at "'.$itemsPath.'".');
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            try {
                $id = trim((string) $this->paths->get($item, $idPath));
            } catch (AiProviderTemplateException) {
                continue;
            }
            $id = str_replace('models/', '', $id);
            if ($id === '') {
                continue;
            }
            $name = $id;
            try {
                $name = trim((string) $this->paths->get($item, $namePath)) ?: $id;
            } catch (AiProviderTemplateException) {
            }
            $out[] = [
                'id' => $id,
                'display_name' => $name,
                'metadata' => $this->safeMetadata($item),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function generate(ApiConnection $connection, string $prompt, string $model, array $options = []): array
    {
        $template = $this->templateForConnection($connection);
        if (empty($template->endpoints['text']['enabled'])) {
            throw new PromptRunException('Text endpoint is not configured.');
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_output']) && is_numeric($options['max_output'])) {
            $payload['max_tokens'] = (int) $options['max_output'];
        }

        $response = $this->http->request(
            $template,
            'text',
            (string) $connection->api_key,
            jsonBody: $payload,
            timeoutSeconds: 180,
        );
        if (! $response->successful()) {
            throw new PromptRunException(
                'Provider API error ('.$response->status().'): '.AiProviderSecureHttpClient::redact($response->body()),
                $response->status(),
            );
        }

        $json = $response->json();
        $text = (string) data_get($json, 'choices.0.message.content', '');
        if (trim($text) === '') {
            throw new PromptRunException('Provider returned empty content.');
        }
        $usage = data_get($json, 'usage');
        $usageBag = is_array($usage) ? $usage : [];
        $resolved = trim((string) ($json['model'] ?? ''));
        if ($resolved !== '') {
            $usageBag['requested_model'] = $model;
            $usageBag['resolved_model'] = $resolved;
        }

        return [$text, $usageBag !== [] ? $usageBag : null];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function safeMetadata(array $item): array
    {
        $keep = [];
        foreach (['architecture', 'context_length', 'pricing', 'supported_parameters', 'canonical_slug'] as $key) {
            if (isset($item[$key]) && ! is_object($item[$key])) {
                $keep[$key] = $item[$key];
            }
        }

        return $keep;
    }

    public function supportsProvider(string $provider): bool
    {
        return in_array($provider, [
            AiProviderProtocol::OpenaiCompatible->value,
            AiProviderProtocol::Openrouter->value,
            'deepseek',
            'openrouter',
        ], true);
    }
}
