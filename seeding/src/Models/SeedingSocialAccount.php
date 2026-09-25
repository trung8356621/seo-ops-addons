<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialAccountStatus;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

/**
 * Installation-scoped social login account (Manager SoT).
 * Credentials live in encrypted columns — never put secrets in meta_json.
 */
class SeedingSocialAccount extends Model
{
    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'seeding_social_accounts';

    /** @var list<string> */
    protected $fillable = [
        'installation_id',
        'site_id',
        'domain',
        'platform',
        'label',
        'username_encrypted',
        'password_encrypted',
        'status',
        'meta_json',
        'created_by',
        'updated_by',
        'credential_updated_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'username_encrypted',
        'password_encrypted',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'platform' => SeedingSocialPlatform::class,
        'status' => SeedingSocialAccountStatus::class,
        'username_encrypted' => 'encrypted',
        'password_encrypted' => 'encrypted',
        'meta_json' => 'array',
        'credential_updated_at' => 'datetime',
    ];

    public function hasUsername(): bool
    {
        $raw = $this->attributes['username_encrypted'] ?? null;

        return is_string($raw) && $raw !== '';
    }

    public function hasPassword(): bool
    {
        $raw = $this->attributes['password_encrypted'] ?? null;

        return is_string($raw) && $raw !== '';
    }

    /**
     * Manager list/detail — never includes password plaintext.
     *
     * @return array{
     *   id: int,
     *   site_id: int|null,
     *   domain: string,
     *   platform: string,
     *   platform_label: string,
     *   label: string|null,
     *   username: string|null,
     *   has_username: bool,
     *   has_password: bool,
     *   status: string,
     *   status_label: string,
     *   meta: array<string, mixed>|null,
     *   created_by: int|null,
     *   updated_by: int|null,
     *   credential_updated_at: string|null,
     *   created_at: string|null,
     *   updated_at: string|null
     * }
     */
    public function toManagerApiArray(): array
    {
        $platform = $this->platform instanceof SeedingSocialPlatform
            ? $this->platform
            : SeedingSocialPlatform::Other;
        $status = $this->status instanceof SeedingSocialAccountStatus
            ? $this->status
            : SeedingSocialAccountStatus::Active;

        $username = null;
        if ($this->hasUsername()) {
            $plain = $this->username_encrypted;
            $username = is_string($plain) && $plain !== '' ? $plain : null;
        }

        $meta = is_array($this->meta_json) ? $this->meta_json : null;

        return [
            'id' => (int) $this->id,
            'site_id' => $this->site_id !== null ? (int) $this->site_id : null,
            'domain' => (string) $this->domain,
            'platform' => $platform->value,
            'platform_label' => $platform->label(),
            'label' => $this->nullableString($this->label),
            'username' => $username,
            'has_username' => $this->hasUsername(),
            'has_password' => $this->hasPassword(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'meta' => $meta,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'updated_by' => $this->updated_by !== null ? (int) $this->updated_by : null,
            'credential_updated_at' => $this->credential_updated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Active read model for future Seeder social choices — no credentials.
     *
     * @return array{
     *   id: int,
     *   site_id: int|null,
     *   domain: string,
     *   platform: string,
     *   platform_label: string,
     *   label: string|null,
     *   status: string
     * }
     */
    public function toActiveReadArray(): array
    {
        $platform = $this->platform instanceof SeedingSocialPlatform
            ? $this->platform
            : SeedingSocialPlatform::Other;

        return [
            'id' => (int) $this->id,
            'site_id' => $this->site_id !== null ? (int) $this->site_id : null,
            'domain' => (string) $this->domain,
            'platform' => $platform->value,
            'platform_label' => $platform->label(),
            'label' => $this->nullableString($this->label),
            'status' => SeedingSocialAccountStatus::Active->value,
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeForInstallation(Builder $query, string $installationId): Builder
    {
        return $query->where('installation_id', $installationId);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SeedingSocialAccountStatus::Active->value);
    }

    /** @param  Builder<static>  $query */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
