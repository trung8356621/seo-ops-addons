<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Canonical step scope for Content Project step rerun.
 * No free-form step strings.
 */
enum ContentProjectRerunFromStep: string
{
    case Outline = 'outline';
    case Article = 'article';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'outline', 'regenerate_outline' => self::Outline,
            'article', 'regenerate_article', 'content' => self::Article,
            default => null,
        };
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromMixed(mixed $value): self
    {
        $step = self::tryFromMixed($value);
        if (! $step instanceof self) {
            throw new \InvalidArgumentException(
                'rerun_from_step must be outline|article.',
            );
        }

        return $step;
    }
}
