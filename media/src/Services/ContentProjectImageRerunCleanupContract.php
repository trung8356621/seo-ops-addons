<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

/**
 * Image rerun cleanup order contract — không subsystem mới.
 *
 * Contract bắt buộc:
 * 1) Generate new asset
 * 2) Persist DB / ownership
 * 3) Update article/gallery reference
 * 4) Commit success
 * 5) Delete/detach old asset (nếu path có replace)
 *
 * Cấm: delete old → generate/persist new.
 * Fail: giữ asset cũ + reference cũ.
 */
final class ContentProjectImageRerunCleanupContract
{
    public const ORDER = [
        'generate',
        'persist',
        'update_reference',
        'commit',
        'cleanup_old',
    ];

    /**
     * @return list<string>
     */
    public static function requiredOrder(): array
    {
        return self::ORDER;
    }

    public static function assertsCleanupAfterPersist(string $serviceSource): bool
    {
        // Soft audit: source không được gọi delete/unlink trước storeBinary / update completed.
        $lower = mb_strtolower($serviceSource);
        $persistMarkers = ['storebinarymedia', 'attachstoredpathtomedia', "status' => 'completed'", 'status" => "completed"'];
        $earlyDelete = false;
        $persistPos = PHP_INT_MAX;
        foreach ($persistMarkers as $marker) {
            $pos = mb_stripos($lower, mb_strtolower($marker));
            if ($pos !== false) {
                $persistPos = min($persistPos, $pos);
            }
        }

        foreach (['->delete(', 'storage::delete', 'unlink(', 'forceDelete'] as $del) {
            $pos = mb_stripos($lower, $del);
            if ($pos === false) {
                continue;
            }
            if ($persistPos === PHP_INT_MAX || $pos < $persistPos) {
                $earlyDelete = true;
                break;
            }
        }

        return ! $earlyDelete;
    }
}
