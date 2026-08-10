<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOnDefaultConnection
{
    /**
     * BelongsTo model trên connection mặc định (bảng core, không nằm trong omi_seo_ai).
     */
    protected function belongsToOnDefaultConnection(
        string $related,
        string $foreignKey,
        string $ownerKey = 'id',
        ?string $relation = null,
    ): BelongsTo {
        /** @var Model $instance */
        $instance = new $related;
        $instance->setConnection((string) config('database.core_connection', 'mysql'));

        return $this->newBelongsTo(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation ?? $this->guessBelongsToRelationName($related, $foreignKey),
        );
    }

    /**
     * @param  class-string<Model>  $related
     */
    private function guessBelongsToRelationName(string $related, string $foreignKey): string
    {
        if (str_ends_with($foreignKey, '_id')) {
            return str_replace('_id', '', $foreignKey);
        }

        return strtolower(class_basename($related));
    }
}
