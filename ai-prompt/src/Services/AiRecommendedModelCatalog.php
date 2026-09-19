<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Opinionated recommended defaults for AI Center auto-map.
 *
 * Classification (primary type) ≠ recommendation (auto-enable).
 * Identity is family/canonical key from {@see AiModelFamilyCatalog} — not per-connection.
 */
final class AiRecommendedModelCatalog
{
    /**
     * @return list<array{
     *   family_key: string,
     *   default_area: AiModelArea,
     *   default_rank: int,
     *   recommended: bool
     * }>
     */
    public function entries(): array
    {
        return [
            // Fast text
            $this->entry('openai.gpt54_nano', AiModelArea::TextFast, 10),
            $this->entry('openai.gpt54_mini', AiModelArea::TextFast, 20),
            $this->entry('deepseek.flash', AiModelArea::TextFast, 30),
            $this->entry('gemini.flash_lite', AiModelArea::TextFast, 40),
            $this->entry('gemini.flash', AiModelArea::TextFast, 50),
            $this->entry('claude.haiku', AiModelArea::TextFast, 60),
            $this->entry('qwen.flash', AiModelArea::TextFast, 70),
            $this->entry('deepseek.v32', AiModelArea::TextFast, 80),

            // Long-form text
            $this->entry('deepseek.v4_pro', AiModelArea::TextLongform, 10),
            $this->entry('claude.sonnet', AiModelArea::TextLongform, 20),
            $this->entry('openai.gpt54', AiModelArea::TextLongform, 30),
            $this->entry('gemini.pro', AiModelArea::TextLongform, 40),
            $this->entry('deepseek.chat', AiModelArea::TextLongform, 50),

            // Reasoning text
            $this->entry('deepseek.reasoner', AiModelArea::TextReasoning, 10),
            $this->entry('claude.opus', AiModelArea::TextReasoning, 20),

            // Image / video
            $this->entry('nano_banana', AiModelArea::Image, 10),
            $this->entry('nano_banana_pro', AiModelArea::Image, 20),
            $this->entry('imagen', AiModelArea::Image, 30),
            $this->entry('veo', AiModelArea::Video, 10),
        ];
    }

    /**
     * @return array{family_key: string, default_area: AiModelArea, default_rank: int, recommended: bool}|null
     */
    public function find(string $familyOrCanonicalKey): ?array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['family_key'] === $familyOrCanonicalKey) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{family_key: string, default_area: AiModelArea, default_rank: int, recommended: bool}|null
     */
    public function recommendedFor(string $familyOrCanonicalKey): ?array
    {
        $entry = $this->find($familyOrCanonicalKey);
        if ($entry === null || ! $entry['recommended']) {
            return null;
        }

        return $entry;
    }

    /**
     * @return list<string>
     */
    public function recommendedFamilyKeys(?AiModelArea $area = null): array
    {
        $keys = [];
        foreach ($this->entries() as $entry) {
            if (! $entry['recommended']) {
                continue;
            }
            if ($area !== null && $entry['default_area'] !== $area) {
                continue;
            }
            $keys[] = $entry['family_key'];
        }

        return $keys;
    }

    /**
     * @return array{family_key: string, default_area: AiModelArea, default_rank: int, recommended: bool}
     */
    private function entry(string $familyKey, AiModelArea $area, int $rank, bool $recommended = true): array
    {
        return [
            'family_key' => $familyKey,
            'default_area' => $area,
            'default_rank' => $rank,
            'recommended' => $recommended,
        ];
    }
}
