<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\WpOption;
use InvalidArgumentException;

final class AiResilienceSettingsService
{
    public const OPTION_KEY = 'ai_resilience_settings';

    public const KEY_MAX_AI_ATTEMPTS = 'max_ai_attempts';

    public const KEY_MAX_FREE_ATTEMPTS = 'max_free_attempts';

    public const DEFAULT_MAX_AI_ATTEMPTS = 6;

    public const DEFAULT_MAX_FREE_ATTEMPTS = 3;

    public const MIN_MAX_AI_ATTEMPTS = 1;

    public const MAX_MAX_AI_ATTEMPTS = 20;

    /**
     * @return array{max_ai_attempts: int, max_free_attempts: int}
     */
    public function get(int $userId = 0): array
    {
        $bag = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($bag)) {
            return $this->defaults();
        }

        $scoped = is_array($bag[(string) $userId] ?? null)
            ? $bag[(string) $userId]
            : (is_array($bag['global'] ?? null) ? $bag['global'] : $bag);

        return $this->normalize($scoped);
    }

    /**
     * @param  array{max_ai_attempts?: int, max_free_attempts?: int}  $settings
     * @return array{max_ai_attempts: int, max_free_attempts: int}
     */
    public function save(int $userId, array $settings): array
    {
        $normalized = $this->normalize($settings);
        $bag = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($bag)) {
            $bag = [];
        }
        $bag[(string) max(0, $userId)] = $normalized;
        WpOption::set(self::OPTION_KEY, $bag);

        return $normalized;
    }

    /**
     * @return array{max_ai_attempts: int, max_free_attempts: int}
     */
    public function defaults(): array
    {
        return [
            self::KEY_MAX_AI_ATTEMPTS => self::DEFAULT_MAX_AI_ATTEMPTS,
            self::KEY_MAX_FREE_ATTEMPTS => self::DEFAULT_MAX_FREE_ATTEMPTS,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{max_ai_attempts: int, max_free_attempts: int}
     */
    private function normalize(array $settings): array
    {
        $maxAi = (int) ($settings[self::KEY_MAX_AI_ATTEMPTS] ?? self::DEFAULT_MAX_AI_ATTEMPTS);
        $maxFree = (int) ($settings[self::KEY_MAX_FREE_ATTEMPTS] ?? self::DEFAULT_MAX_FREE_ATTEMPTS);

        if ($maxAi < self::MIN_MAX_AI_ATTEMPTS || $maxAi > self::MAX_MAX_AI_ATTEMPTS) {
            throw new InvalidArgumentException(
                'max_ai_attempts must be between '.self::MIN_MAX_AI_ATTEMPTS.' and '.self::MAX_MAX_AI_ATTEMPTS.'.',
            );
        }

        if ($maxFree < 0 || $maxFree > $maxAi) {
            throw new InvalidArgumentException('max_free_attempts must be between 0 and max_ai_attempts.');
        }

        return [
            self::KEY_MAX_AI_ATTEMPTS => $maxAi,
            self::KEY_MAX_FREE_ATTEMPTS => $maxFree,
        ];
    }
}
