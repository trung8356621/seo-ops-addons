<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DTO in-memory cho block parse từ markdown (bảng `prompt_parts` đã bỏ).
 */
class PromptPart extends Model
{
    protected $connection = 'omi_seo_ai';

    /** @deprecated Bảng đã drop; chỉ dùng instance không lưu DB. */
    protected $table = 'prompt_parts';

    protected $fillable = [
        'prompt_id',
        'position',
        'role',
        'name',
        'content',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }
}
