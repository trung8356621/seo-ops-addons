<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * FREE / PAID / MIXED from successful article-content route cost classes.
 *
 * Cost class is authoritative. Execution shape is display-only and never
 * selects FREE vs PAID.
 */
final class ContentProjectItemAiModeClassifier
{
    public const MODE_FREE = 'FREE';

    public const MODE_PAID = 'PAID';

    public const MODE_MIXED = 'MIXED';

    public const SHAPE_SPLIT = 'SPLIT';

    public const SHAPE_SINGLE = 'SINGLE';

    /**
     * @param  list<string>  $successfulCostClasses  free|paid only
     * @return array{mode: string|null, shape: string|null, label: string}
     */
    public static function classify(array $successfulCostClasses, ?string $executionShape = null): array
    {
        $free = false;
        $paid = false;
        foreach ($successfulCostClasses as $costClass) {
            $normalized = strtolower(trim($costClass));
            if ($normalized === 'free') {
                $free = true;
            } elseif ($normalized === 'paid') {
                $paid = true;
            }
        }

        $mode = match (true) {
            $free && $paid => self::MODE_MIXED,
            $free => self::MODE_FREE,
            $paid => self::MODE_PAID,
            default => null,
        };

        $shape = self::shapeLabel($executionShape);

        return [
            'mode' => $mode,
            'shape' => $shape,
            'label' => self::label($mode, $shape),
        ];
    }

    /**
     * @return array{mode: null, shape: null, label: string}
     */
    public static function unknown(): array
    {
        return [
            'mode' => null,
            'shape' => null,
            'label' => '—',
        ];
    }

    public static function label(?string $mode, ?string $shape): string
    {
        if ($mode === null || $mode === '') {
            return '—';
        }

        if ($shape === null || $shape === '') {
            return $mode;
        }

        return $mode.' · '.$shape;
    }

    public static function shapeLabel(?string $executionShape): ?string
    {
        $normalized = strtolower(trim((string) $executionShape));

        return match ($normalized) {
            'sectioned', 'sectioned_free', 'split', 'multiple_pass' => self::SHAPE_SPLIT,
            'single_pass', 'single' => self::SHAPE_SINGLE,
            default => null,
        };
    }
}
