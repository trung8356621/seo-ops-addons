<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SeoMediaProcessingHistory extends Model
{
    use BelongsToOnDefaultConnection;

    public const SOURCE_WORDPRESS = 'wordpress';

    public const SOURCE_LOCAL = 'local';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_media_processing_histories';

    protected $guarded = [];

    protected $casts = [
        'is_watermarked' => 'boolean',
        'is_optimized' => 'boolean',
        'watermarked_at' => 'datetime',
        'optimized_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function backupExists(): bool
    {
        $path = ltrim((string) $this->backup_path, '/');

        return $path !== '' && Storage::disk('public')->exists($path);
    }

    public function lastProcessedAt(): ?Carbon
    {
        $times = array_filter([
            $this->watermarked_at,
            $this->optimized_at,
        ]);

        if ($times === []) {
            return null;
        }

        return collect($times)->max();
    }

    public function isCurrentlyModified(): bool
    {
        if (! $this->is_watermarked && ! $this->is_optimized) {
            return false;
        }

        $lastProcessed = $this->lastProcessedAt();
        if ($lastProcessed === null) {
            return false;
        }

        if ($this->restored_at === null) {
            return true;
        }

        return $this->restored_at->lt($lastProcessed);
    }
}
