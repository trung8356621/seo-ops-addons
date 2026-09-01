<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;

/**
 * Default tone strategy for Content Project items: "Automatic variety".
 *
 * Spreads items across the configured tone-of-voice list instead of reusing one
 * site-level tone for every article. The pick is a pure function of the item
 * identity, so the same item always lands on the same tone before it is stickied
 * into seo_project_tasks.resolved_tone.
 */
final class AutomaticVarietyToneResolver
{
    public function __construct(
        private readonly SeoPromptSettingsService $promptSettings,
    ) {}

    public function resolve(
        ?int $taskId,
        ?string $keyword = null,
        ?string $postType = null,
        ?string $brief = null,
    ): ?string {
        $tones = $this->tones();
        if ($tones === []) {
            return null;
        }

        $index = (int) (crc32(self::seedFor($taskId, $keyword, $postType, $brief)) % count($tones));

        return $tones[$index];
    }

    public static function seedFor(
        ?int $taskId,
        ?string $keyword = null,
        ?string $postType = null,
        ?string $brief = null,
    ): string {
        return implode('|', [
            (string) ($taskId ?? 0),
            self::normalize($keyword),
            self::normalize($postType),
            self::normalize($brief),
        ]);
    }

    /**
     * @return list<string>
     */
    public function tones(): array
    {
        $tones = [];

        foreach ($this->promptSettings->getToneOfVoiceOptions() as $tone) {
            $normalized = trim((string) $tone);
            if ($normalized !== '') {
                $tones[] = $normalized;
            }
        }

        return $tones;
    }

    private static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
