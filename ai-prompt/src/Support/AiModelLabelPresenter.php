<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;

final class AiModelLabelPresenter
{
    public function __construct(
        private readonly AiModelFamilyCatalog $catalog = new AiModelFamilyCatalog(),
    ) {}

    public function normal(string $providerModelId, ?string $fallbackDisplay = null): string
    {
        $family = $this->catalog->familyForModelId($providerModelId);
        if ($family instanceof AiModelFamily) {
            return $family->displayName;
        }

        $fallback = trim((string) $fallbackDisplay);

        return $fallback !== '' ? $fallback : $providerModelId;
    }

    public function technical(string $providerModelId, ?string $provider = null, ?string $fallbackDisplay = null): string
    {
        $label = $this->normal($providerModelId, $fallbackDisplay);
        $parts = [$label, $providerModelId];
        if ($provider !== null && trim($provider) !== '') {
            $parts[] = 'Provider: '.$provider;
        }

        return implode("\n", $parts);
    }
}
