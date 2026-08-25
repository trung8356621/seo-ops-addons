<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-URL item inside a GSC URL Inspection run.
 *
 * @property int $id
 * @property int $run_id
 * @property int $article_id
 * @property string|null $url
 * @property string $status
 * @property string|null $check_status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $check_id
 * @property array<string, mixed>|null $diagnostics
 */
final class SeoGscUrlInspectionRunItem extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_url_inspection_run_items';

    protected $guarded = [];

    protected $casts = [
        'run_id' => 'integer',
        'article_id' => 'integer',
        'check_id' => 'integer',
        'diagnostics' => 'array',
    ];

    /** @return BelongsTo<SeoGscUrlInspectionRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoGscUrlInspectionRun::class, 'run_id');
    }
}
