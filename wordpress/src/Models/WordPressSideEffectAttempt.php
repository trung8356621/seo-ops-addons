<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $operation
 * @property string $origin
 * @property string $correlation_id
 * @property int|null $automation_execution_id
 * @property int|null $automation_node_execution_id
 * @property int|null $user_id
 * @property int|null $article_id
 * @property int|null $site_id
 * @property string|null $idempotency_key
 * @property string $status
 * @property string|null $blocked_reason
 * @property int|null $remote_post_id
 */
final class WordPressSideEffectAttempt extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'wordpress_side_effect_attempts';

    public $timestamps = false;

    protected $fillable = [
        'operation',
        'origin',
        'correlation_id',
        'automation_execution_id',
        'automation_node_execution_id',
        'user_id',
        'article_id',
        'site_id',
        'idempotency_key',
        'status',
        'blocked_reason',
        'remote_post_id',
        'created_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
