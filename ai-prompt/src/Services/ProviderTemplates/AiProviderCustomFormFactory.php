<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Illuminate\Support\Facades\Schema;

/**
 * Builds schema 1.0 OpenAI-compatible provider templates from the Settings UI
 * (no JSON import required).
 */
final class AiProviderCustomFormFactory
{
    public function __construct(
        private readonly AiProviderTemplateParser $parser = new AiProviderTemplateParser(),
        private readonly AiProviderTemplateCatalog $catalog = new AiProviderTemplateCatalog(),
    ) {}

    /**
     * @param  array<string, mixed>  $form
     */
    public function buildFromForm(array $form): NormalizedAiProviderTemplate
    {
        $name = trim((string) ($form['provider_name'] ?? $form['name'] ?? ''));
        $key = strtolower(trim((string) ($form['provider_key'] ?? '')));
        if ($key === '' && $name !== '') {
            $key = $this->slugify($name);
        }
        $shortCode = trim((string) ($form['short_code'] ?? ''));
        if ($shortCode === '' && $name !== '') {
            $shortCode = AiConnectionShortCode::generate($name);
        }
        $baseUrl = rtrim(trim((string) ($form['base_url'] ?? '')), '/');
        $authType = strtolower(trim((string) ($form['auth_type'] ?? 'bearer')));
        if ($authType === '') {
            $authType = 'bearer';
        }
        $modelsPath = $this->normalizePath((string) ($form['models_path'] ?? '/models'));
        $textPath = $this->normalizePath((string) ($form['text_path'] ?? '/chat/completions'));
        $discovery = filter_var($form['model_discovery'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $config = [
            'package_type' => 'ai_provider_template',
            'schema_version' => AiProviderTemplateLimits::SCHEMA_VERSION,
            'provider' => [
                'key' => $key,
                'name' => $name,
                'short_code' => $shortCode,
                'protocol' => 'openai_compatible',
            ],
            'connection' => [
                'base_url' => $baseUrl,
                'auth' => ['type' => $authType],
                'headers' => [
                    'accept' => 'application/json',
                ],
                'allow_base_url_override' => true,
            ],
            'endpoints' => [
                'models' => [
                    'enabled' => $discovery,
                    'method' => 'GET',
                    'path' => $modelsPath,
                    'response' => [
                        'items_path' => 'data',
                        'id_path' => 'id',
                        'name_path' => 'id',
                    ],
                ],
                'text' => [
                    'enabled' => true,
                    'method' => 'POST',
                    'path' => $textPath,
                ],
                'image' => ['enabled' => false],
                'video' => ['enabled' => false],
            ],
        ];

        return $this->parser->parse(json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function formDefaultsFromTemplate(NormalizedAiProviderTemplate $template): array
    {
        $models = $template->endpoints['models'] ?? [];
        $text = $template->endpoints['text'] ?? [];

        return [
            'provider_name' => $template->providerName,
            'provider_key' => $template->providerKey,
            'short_code' => $template->shortCode ?? AiConnectionShortCode::generate($template->providerName),
            'base_url' => $template->baseUrl,
            'auth_type' => $template->authType->value,
            'models_path' => (string) ($models['path'] ?? '/models'),
            'text_path' => (string) ($text['path'] ?? '/chat/completions'),
            'model_discovery' => ! empty($models['enabled']),
        ];
    }

    public function assertProviderKeyAvailable(int $userId, string $providerKey, ?string $currentKey = null): void
    {
        $key = strtolower(trim($providerKey));
        if ($key === '') {
            throw AiProviderTemplateException::rejected('provider.key is required.');
        }
        if ($currentKey !== null && strtolower(trim($currentKey)) === $key) {
            return;
        }
        if (ApiConnectionProviders::isReservedProviderKey($key)) {
            throw AiProviderTemplateException::rejected('Provider key "'.$key.'" is reserved.');
        }
        if (isset($this->catalog->builtins()[$key])) {
            throw AiProviderTemplateException::rejected('Provider key "'.$key.'" collides with a built-in provider.');
        }
        if (ApiConnectionProviders::flatOptions() !== [] && array_key_exists($key, ApiConnectionProviders::flatOptions())) {
            throw AiProviderTemplateException::rejected('Provider key "'.$key.'" is already used by a built-in provider.');
        }

        try {
            $model = new AiProviderTemplate();
            $connectionName = $model->getConnectionName();
            if (Schema::connection($connectionName)->hasTable($model->getTable())) {
                $exists = AiProviderTemplate::query()
                    ->where('user_id', $userId)
                    ->where('provider_key', $key)
                    ->exists();
                if ($exists) {
                    throw AiProviderTemplateException::rejected('Provider key "'.$key.'" already exists.');
                }
            }
        } catch (AiProviderTemplateException $exception) {
            throw $exception;
        } catch (\Throwable) {
        }
    }

    public function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');
        if ($slug === '' || ! preg_match('/^[a-z]/', $slug)) {
            $slug = 'p-'.$slug;
        }
        $slug = substr($slug, 0, AiProviderTemplateLimits::MAX_PROVIDER_KEY);
        $slug = rtrim($slug, '-_');
        if ($slug === '' || $slug === 'p') {
            $slug = 'custom-ai';
        }

        return $slug;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $path;
    }
}
