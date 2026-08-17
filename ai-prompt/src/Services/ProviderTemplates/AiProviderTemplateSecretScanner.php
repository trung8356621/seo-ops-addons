<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;

final class AiProviderTemplateSecretScanner
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $skipValueKeys
     */
    public function assertClean(array $data, array $skipValueKeys = []): void
    {
        $this->walk($data, '', $skipValueKeys);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     * @param  list<string>  $skipValueKeys
     */
    private function walk(array $node, string $path, array $skipValueKeys): void
    {
        foreach ($node as $key => $value) {
            $name = is_string($key) ? strtolower($key) : (string) $key;
            $here = $path === '' ? $name : $path.'.'.$name;
            $skipValue = in_array($name, $skipValueKeys, true);

            if (is_string($key) && in_array($name, AiProviderTemplateLimits::SECRET_FIELD_NAMES, true)) {
                if (is_string($value) && trim($value) !== '' && ! in_array($name, ['authorization'], true)) {
                    throw AiProviderTemplateException::rejected(
                        'API credentials must not be stored inside provider templates. Enter secrets in the connection form.',
                    );
                }
                if ($name === 'authorization' && is_string($value) && preg_match('/bearer\s+\S+/i', $value) === 1) {
                    throw AiProviderTemplateException::rejected(
                        'API credentials must not be stored inside provider templates.',
                    );
                }
            }

            if (! $skipValue && is_string($value) && $this->looksLikeSecret($value)) {
                throw AiProviderTemplateException::rejected(
                    'API credentials must not be stored inside provider templates.',
                );
            }

            if (is_array($value)) {
                $this->walk($value, $here, $skipValueKeys);
            }
        }
    }

    private function looksLikeSecret(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        return (bool) preg_match('/^(sk-|sk-or-|sk-ant-|AIza)[A-Za-z0-9_\-]{8,}$/', $trimmed)
            || (bool) preg_match('/^Bearer\s+\S{8,}/i', $trimmed);
    }
}
