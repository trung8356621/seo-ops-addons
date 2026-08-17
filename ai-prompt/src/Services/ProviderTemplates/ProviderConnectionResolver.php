<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate;
use Omnichannel\Addons\AiPrompt\DataTransfer\ResolvedAiProviderConnection;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

final class ProviderConnectionResolver
{
    public const SOURCE_BUILTIN = 'builtin';

    public const SOURCE_IMPORTED = 'imported';

    public function __construct(
        private readonly AiProviderTemplateParser $parser = new AiProviderTemplateParser(),
        private readonly AiProviderTemplateCatalog $catalog = new AiProviderTemplateCatalog(),
        private readonly AiProviderOutboundUrlPolicy $urls = new AiProviderOutboundUrlPolicy(),
    ) {}

    public function resolve(ApiConnection $connection): ResolvedAiProviderConnection
    {
        $userId = (int) ($connection->user_id ?? 0);
        $meta = is_array($connection->metadata) ? $connection->metadata : [];

        return $this->resolveForProvider($userId, (string) $connection->provider, $meta);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function resolveForProvider(int $userId, string $providerKey, array $metadata = []): ResolvedAiProviderConnection
    {
        $providerKey = strtolower(trim($providerKey));
        if ($providerKey === '') {
            throw AiProviderTemplateException::rejected('Provider is required.');
        }

        [$template, $source] = $this->loadTemplate($userId, $providerKey);
        $requested = $this->requestedOverride($metadata, $template->baseUrl);
        $overrideApplied = false;
        $effective = $template->baseUrl;

        if ($requested !== null) {
            if (! $this->overridePermitted($template)) {
                $requested = null;
            } else {
                $this->urls->assertSafeUrl($requested);
                $effective = $requested;
                $overrideApplied = true;
            }
        }

        return new ResolvedAiProviderConnection(
            template: $template,
            effectiveBaseUrl: $effective,
            source: $source,
            overrideApplied: $overrideApplied,
        );
    }

    /**
     * Drop forged / stale Base URL submissions. Persist override only when permitted.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitizeSubmittedMetadata(int $userId, string $providerKey, array $metadata): array
    {
        $resolved = $this->resolveForProvider($userId, $providerKey, []);
        unset($metadata['base_url'], $metadata['provider_template']);
        $displayCode = \Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode::normalize(
            isset($metadata['display_code']) ? (string) $metadata['display_code'] : null,
        );
        if ($displayCode !== null) {
            $metadata['display_code'] = $displayCode;
        } else {
            unset($metadata['display_code']);
        }
        $wantsOverride = filter_var($metadata['override_base_url'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $submitted = rtrim(trim((string) ($metadata['base_url_override'] ?? '')), '/');

        if ($wantsOverride && $submitted !== '' && $this->overridePermitted($resolved->template)) {
            $this->urls->assertSafeUrl($submitted);
            $metadata['override_base_url'] = true;
            $metadata['base_url_override'] = $submitted;

            return $metadata;
        }

        unset($metadata['override_base_url'], $metadata['base_url_override']);

        return $metadata;
    }

    /**
     * @return array{protocol: string, base_url: string, models: string, text: string, source: string, schema_version: string, allow_override: bool, provider_name: string}
     */
    public function technicalDetails(int $userId, string $providerKey): array
    {
        $resolved = $this->resolveForProvider($userId, $providerKey);
        $template = $resolved->effectiveTemplate();
        $models = $template->endpoints['models'] ?? [];
        $text = $template->endpoints['text'] ?? [];

        return [
            'protocol' => $template->protocol->value,
            'base_url' => $resolved->effectiveBaseUrl,
            'models' => ! empty($models['enabled'])
                ? strtoupper((string) ($models['method'] ?? 'GET')).' '.(string) ($models['path'] ?? '')
                : 'Not configured',
            'text' => ! empty($text['enabled'])
                ? strtoupper((string) ($text['method'] ?? 'POST')).' '.(string) ($text['path'] ?? '')
                : 'Not configured',
            'source' => $resolved->source,
            'schema_version' => $template->schemaVersion,
            'allow_override' => $this->overridePermitted($template),
            'provider_name' => $template->providerName,
        ];
    }

    public function hasTemplate(int $userId, string $providerKey): bool
    {
        try {
            $this->loadTemplate($userId, $providerKey);

            return true;
        } catch (AiProviderTemplateException) {
            return false;
        }
    }

    /**
     * @return array{0: NormalizedAiProviderTemplate, 1: string}
     */
    private function loadTemplate(int $userId, string $providerKey): array
    {
        $row = $this->importedRow($userId, $providerKey);
        if ($row instanceof AiProviderTemplate && is_array($row->config) && (bool) $row->enabled) {
            $parsed = $this->parser->parse(json_encode($row->config, JSON_THROW_ON_ERROR));
            $source = (bool) $row->is_builtin ? self::SOURCE_BUILTIN : self::SOURCE_IMPORTED;

            return [$parsed, $source];
        }

        $builtins = $this->catalog->builtins();
        if (! isset($builtins[$providerKey])) {
            throw AiProviderTemplateException::rejected('No provider template for '.$providerKey.'.');
        }

        return [
            $this->parser->parse(json_encode($builtins[$providerKey], JSON_THROW_ON_ERROR)),
            self::SOURCE_BUILTIN,
        ];
    }

    public function httpBaseUrl(ApiConnection $connection): string
    {
        return rtrim($this->resolve($connection)->effectiveBaseUrl, '/');
    }

    private function importedRow(int $userId, string $providerKey): ?AiProviderTemplate
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $model = new AiProviderTemplate();
            $connectionName = $model->getConnectionName();
            if (! Schema::connection($connectionName)->hasTable($model->getTable())) {
                return null;
            }
            $row = AiProviderTemplate::query()
                ->where('user_id', $userId)
                ->where('provider_key', $providerKey)
                ->first();

            return $row instanceof AiProviderTemplate ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function requestedOverride(array $metadata, string $templateBaseUrl): ?string
    {
        $explicit = filter_var($metadata['override_base_url'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $override = rtrim(trim((string) ($metadata['base_url_override'] ?? '')), '/');
        if ($explicit && $override !== '') {
            return $override;
        }

        $legacy = rtrim(trim((string) ($metadata['base_url'] ?? '')), '/');
        if ($legacy === '' || strcasecmp($legacy, $templateBaseUrl) === 0) {
            return null;
        }

        return $legacy;
    }

    private function overridePermitted(NormalizedAiProviderTemplate $template): bool
    {
        if ($template->allowBaseUrlOverride) {
            return true;
        }

        return ! isset($this->catalog->builtins()[$template->providerKey])
            && ! in_array($template->providerKey, [
                ApiConnectionProviders::GEMINI,
                ApiConnectionProviders::DEEPSEEK,
                ApiConnectionProviders::OPENROUTER,
                ApiConnectionProviders::CLAUDE,
            ], true);
    }
}
