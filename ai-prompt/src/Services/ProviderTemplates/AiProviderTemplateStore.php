<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;
use Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

final class AiProviderTemplateStore
{
    public function __construct(
        private readonly AiProviderTemplateParser $parser = new AiProviderTemplateParser(),
        private readonly AiProviderTemplateCatalog $catalog = new AiProviderTemplateCatalog(),
    ) {}

    public function persist(int $userId, NormalizedAiProviderTemplate $template, bool $builtin = false): AiProviderTemplate
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            throw AiProviderTemplateException::rejected('Not authorized to import provider templates.');
        }

        $row = AiProviderTemplate::query()->where('user_id', $userId)->where('provider_key', $template->providerKey)->first();
        $revision = $row instanceof AiProviderTemplate ? ((int) $row->revision + 1) : 1;

        return AiProviderTemplate::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'provider_key' => $template->providerKey,
            ],
            [
                'name' => $template->providerName,
                'protocol' => $template->protocol->value,
                'schema_version' => $template->schemaVersion,
                'config' => $template->toStorageArray(),
                'is_builtin' => $builtin,
                'enabled' => true,
                'revision' => $revision,
                'updated_by' => $userId,
            ],
        );
    }

    public function exportForConnection(int $userId, ApiConnection $connection): string
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            throw AiProviderTemplateException::rejected('Not authorized to export provider templates.');
        }
        if ((int) $connection->user_id !== $userId && ! (bool) $connection->is_global) {
            throw AiProviderTemplateException::rejected('Not authorized to export this connection.');
        }

        $row = AiProviderTemplate::query()
            ->where('user_id', $userId)
            ->where('provider_key', (string) $connection->provider)
            ->first();
        $config = is_array($row?->config) ? $row->config : ($this->catalog->builtins()[(string) $connection->provider] ?? null);
        if (! is_array($config)) {
            throw AiProviderTemplateException::rejected('No portable template for this provider.');
        }

        if (! isset($config['package_type'])) {
            $config['package_type'] = 'ai_provider_template';
        }
        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (preg_match('/sk-|Bearer\s+\S|api_key/i', $encoded) === 1 && str_contains($encoded, 'sk-')) {
            throw AiProviderTemplateException::rejected('Export blocked because it would leak credentials.');
        }

        return $encoded."\n";
    }

    public function applyToConnection(int $userId, ApiConnection $connection, NormalizedAiProviderTemplate $template): void
    {
        if ((int) $connection->user_id !== $userId && ! (bool) $connection->is_global) {
            throw AiProviderTemplateException::rejected('Not authorized to update this connection.');
        }
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $meta['provider_template'] = $template->toStorageArray();
        unset($meta['base_url']);
        $connection->metadata = $meta;
        $connection->save();
    }

    /**
     * @return list<string>
     */
    public function diff(array $before, array $after): array
    {
        $keys = [
            'connection.base_url' => 'Base URL',
            'endpoints.models.path' => 'Models endpoint',
            'endpoints.text.path' => 'Text endpoint',
            'connection.auth.type' => 'Authentication',
        ];
        $out = [];
        foreach ($keys as $path => $label) {
            $left = $this->dot($before, $path);
            $right = $this->dot($after, $path);
            $out[] = $left === $right
                ? $label.' unchanged'
                : $label.' changed';
        }

        return $out;
    }

    public function createDraftConnection(int $userId, NormalizedAiProviderTemplate $template): ApiConnection
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            throw AiProviderTemplateException::rejected('Not authorized to create provider connection.');
        }

        $existing = ApiConnection::query()
            ->where('user_id', $userId)
            ->where('provider', $template->providerKey)
            ->first();
        if ($existing instanceof ApiConnection) {
            $this->applyToConnection($userId, $existing, $template);

            return $existing;
        }

        $connection = ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $template->providerKey,
            'name' => $template->providerName,
            'api_key' => '',
            'status' => 'inactive',
            'is_global' => false,
            'metadata' => [
                'provider_template' => $template->toStorageArray(),
            ],
        ]);
        (new AiModelPriorityService())->assignBottomProviderPriority($userId, $connection);

        return $connection;
    }

    private function dot(array $data, string $path): string
    {
        $cursor = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return '';
            }
            $cursor = $cursor[$segment];
        }

        if (is_scalar($cursor)) {
            return (string) $cursor;
        }

        $encoded = json_encode($cursor);

        return is_string($encoded) ? $encoded : '';
    }
}
