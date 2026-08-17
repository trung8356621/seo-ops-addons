<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

use Omnichannel\Addons\AiPrompt\DataTransfer\NormalizedAiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException;

final class AiProviderDotPath
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function get(array $payload, string $path): mixed
    {
        $segments = explode('.', $path);
        $cursor = $payload;
        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                throw AiProviderTemplateException::rejected('expected value at "'.$path.'".');
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
