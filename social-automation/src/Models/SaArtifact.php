<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaArtifact extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_artifacts';

    protected $fillable = [
        'artifact_uuid', 'job_id', 'attempt_id', 'kind', 'mime', 'size_bytes',
        'storage_disk', 'storage_path', 'checksum_sha256', 'metadata', 'retain_until',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'retain_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(SaJob::class, 'job_id');
    }
}
