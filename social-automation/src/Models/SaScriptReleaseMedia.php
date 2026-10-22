<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * @property int $id
 * @property int $release_id
 * @property string $kind
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $checksum_sha256
 * @property string $status
 * @property array<string, mixed>|null $metadata
 */
final class SaScriptReleaseMedia extends Model
{
    public const KIND_VIDEO_DEMO = 'video_demo';

    public const KIND_THUMBNAIL = 'thumbnail';

    public const STATUS_READY = 'ready';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_PENDING = 'pending';

    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_script_release_media';

    protected $fillable = [
        'release_id',
        'kind',
        'storage_disk',
        'storage_path',
        'mime',
        'size_bytes',
        'checksum_sha256',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'release_id' => 'integer',
            'size_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<SaPackageRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(SaPackageRelease::class, 'release_id');
    }
}
