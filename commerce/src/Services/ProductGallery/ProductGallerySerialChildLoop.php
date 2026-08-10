<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryShotDefinition;

/**
 * Pure serial child loop — one shot at a time, retry then continue.
 */
final class ProductGallerySerialChildLoop
{
    /**
     * @param  list<ProductGalleryShotDefinition>  $shots
     * @param  callable(ProductGalleryShotDefinition $shot, int $attempt): bool  $attemptFn  true = success
     * @return array{success_slots: list<int>, failed_slots: list<int>, attempts: list<array{slot: int, attempt: int, ok: bool}>}
     */
    public function run(array $shots, callable $attemptFn, int $retryCount = 1): array
    {
        $maxAttempts = max(1, $retryCount + 1);
        $success = [];
        $failed = [];
        $attempts = [];

        foreach ($shots as $shot) {
            if (! $shot instanceof ProductGalleryShotDefinition) {
                continue;
            }
            $ok = false;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $ok = (bool) $attemptFn($shot, $attempt);
                $attempts[] = ['slot' => $shot->slot, 'attempt' => $attempt, 'ok' => $ok];
                if ($ok) {
                    break;
                }
            }
            if ($ok) {
                $success[] = $shot->slot;
            } else {
                $failed[] = $shot->slot;
            }
        }

        return [
            'success_slots' => $success,
            'failed_slots' => $failed,
            'attempts' => $attempts,
        ];
    }
}
