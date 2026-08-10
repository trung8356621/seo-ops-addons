<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoImageOptimizationSetting extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_image_optimization_settings';

    protected $guarded = [];

    protected $attributes = [
        'auto_convert_webp' => true,
        'quality' => 80,
        'limit_dimensions' => true,
        'max_width' => 1200,
        'max_height' => 1200,
        'clean_filename' => true,
        'auto_alt_tag' => true,
        'alt_tag_pattern' => '{post_title} - {focus_keyword}',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'auto_convert_webp' => 'boolean',
        'quality' => 'integer',
        'limit_dimensions' => 'boolean',
        'max_width' => 'integer',
        'max_height' => 'integer',
        'clean_filename' => 'boolean',
        'auto_alt_tag' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toFormData(): array
    {
        return [
            'auto_convert_webp' => (bool) $this->auto_convert_webp,
            'quality' => (int) ($this->quality ?? 80),
            'limit_dimensions' => (bool) $this->limit_dimensions,
            'max_width' => max(0, (int) ($this->max_width ?? 0)),
            'max_height' => max(0, (int) ($this->max_height ?? 0)),
            'clean_filename' => (bool) $this->clean_filename,
            'auto_alt_tag' => (bool) $this->auto_alt_tag,
            'alt_tag_pattern' => (string) ($this->alt_tag_pattern ?? '{post_title} - {focus_keyword}'),
        ];
    }
}
