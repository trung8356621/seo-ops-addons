<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\ProviderTemplates;

final class AiProviderTemplateDocumentationStripper
{
    /**
     * Recursively drop keys that start with `_` (documentation-only).
     *
     * @param  array<string, mixed>|list<mixed>  $data
     * @return array<string, mixed>|list<mixed>
     */
    public function strip(array $data): array
    {
        $out = [];
        $isList = array_is_list($data);
        foreach ($data as $key => $value) {
            if (! $isList && is_string($key) && str_starts_with($key, '_')) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->strip($value) : $value;
        }

        return $out;
    }
}
