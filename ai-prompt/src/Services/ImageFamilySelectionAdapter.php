<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;

/**
 * Maps image family UX ↔ stored slug lists. Does not call ImageRoutingStrategy.
 */
final class ImageFamilySelectionAdapter
{
    public function __construct(
        private readonly AiModelFamilyCatalog $catalog = new AiModelFamilyCatalog(),
    ) {}

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    public function familiesFromSlugs(array $slugs): array
    {
        $keys = [];
        foreach ($slugs as $slug) {
            $family = $this->catalog->familyForModelId((string) $slug);
            if ($family === null) {
                continue;
            }
            $keys[$family->familyKey] = true;
        }

        return array_keys($keys);
    }

    /**
     * Keep existing eligible slugs in the same order; drop unselected families;
     * append missing operational members of newly selected families.
     *
     * @param  list<string>  $familyKeys
     * @param  list<string>  $existingSlugs
     * @return list<string>
     */
    public function expandPreservingOrder(array $familyKeys, array $existingSlugs): array
    {
        $allowed = $this->normalizeFamilyKeys($familyKeys);
        $kept = [];
        foreach ($existingSlugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '' || ! GeminiModelVersionPolicy::isEligibleForAutoRouting($slug)) {
                continue;
            }
            $family = $this->catalog->familyForModelId($slug);
            if ($family === null || ! in_array($family->familyKey, $allowed, true)) {
                continue;
            }
            $kept[] = $slug;
        }

        foreach ($allowed as $key) {
            $family = $this->catalog->find($key);
            if ($family === null) {
                continue;
            }
            foreach ($family->memberModelIds as $id) {
                if (! GeminiModelVersionPolicy::isEligibleForAutoRouting($id)) {
                    continue;
                }
                if (! in_array($id, $kept, true)) {
                    $kept[] = $id;
                }
            }
        }

        return array_values(array_unique($kept));
    }

    /**
     * @param  list<string>  $familyKeys
     * @return list<string>
     */
    public function expandByMode(array $familyKeys, AiUsageMode $mode): array
    {
        return $this->catalog->expandToModelIds($this->normalizeFamilyKeys($familyKeys), $mode);
    }

    /**
     * @param  list<string>  $familyKeys
     * @return list<string>
     */
    private function normalizeFamilyKeys(array $familyKeys): array
    {
        $familyKeys = array_values(array_filter(
            $familyKeys,
            static fn (string $key): bool => $key !== '' && $key !== AiModelFamilyCatalog::AUTOMATIC,
        ));

        if ($familyKeys === []) {
            $familyKeys = [];
            foreach ($this->catalog->all() as $family) {
                if ($family->modality === 'image') {
                    $familyKeys[] = $family->familyKey;
                }
            }
        }

        return $familyKeys;
    }
}
