<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SeoWpMediaBackup extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_wp_media_backups';

    protected $guarded = [];

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function backupUrl(): ?string
    {
        $path = ltrim((string) $this->backup_path, '/');
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return '/storage/' . $path;
    }
}
