<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialAccountStatus;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Models\SeedingSocialAccount;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manager CRUD + active read model for seeding_social_accounts.
 * Does not wire into Topic / Website Share / Gen Comment — foundation only.
 */
final class SeedingSocialAccountService
{
    public function __construct(
        private readonly SeedingServiceResolver $resolver,
        private readonly SeedingAccess $access,
    ) {}

    public function tableReady(): bool
    {
        return Schema::connection(SeedingServiceConfig::CONNECTION)
            ->hasTable('seeding_social_accounts');
    }

    /**
     * Manager catalog for current installation.
     *
     * @return array{
     *   accounts: list<array<string, mixed>>,
     *   sites: list<array{id: int, domain: string}>,
     *   platforms: list<array{value: string, label: string}>
     * }
     */
    public function listForManager(): array
    {
        $accounts = [];
        if ($this->tableReady()) {
            $accounts = SeedingSocialAccount::query()
                ->forInstallation($this->resolver->installationNamespace())
                ->orderBy('domain')
                ->orderBy('platform')
                ->orderBy('id')
                ->get()
                ->map(static fn (SeedingSocialAccount $row): array => $row->toManagerApiArray())
                ->all();
        }

        return [
            'accounts' => $accounts,
            'sites' => $this->accessibleSites(),
            'platforms' => $this->platformOptions(),
        ];
    }

    /**
     * Canonical ACTIVE accounts for a future Seeder social source.
     * Locked accounts excluded. No passwords / usernames.
     *
     * @return list<array<string, mixed>>
     */
    public function activeForWorkspace(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return SeedingSocialAccount::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->active()
            ->orderBy('domain')
            ->orderBy('platform')
            ->orderBy('id')
            ->get()
            ->map(static fn (SeedingSocialAccount $row): array => $row->toActiveReadArray())
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeForSite(int $siteId): array
    {
        if ($siteId <= 0 || ! $this->tableReady()) {
            return [];
        }

        return SeedingSocialAccount::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->active()
            ->forSite($siteId)
            ->orderBy('platform')
            ->orderBy('id')
            ->get()
            ->map(static fn (SeedingSocialAccount $row): array => $row->toActiveReadArray())
            ->all();
    }

    /**
     * @param  array{
     *   site_id?: int|null,
     *   domain?: string|null,
     *   platform: string,
     *   label?: string|null,
     *   username?: string|null,
     *   password?: string|null,
     *   status?: string|null,
     *   meta?: array<string, mixed>|null
     * }  $payload
     */
    public function create(int $actorUserId, array $payload): SeedingSocialAccount
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Thiếu người tạo');
        }
        if (! $this->tableReady()) {
            throw new InvalidArgumentException('Bảng social account chưa sẵn sàng');
        }

        $identity = $this->resolveSiteDomain($payload);
        $platform = $this->requirePlatform($payload['platform'] ?? null);
        $status = $this->resolveStatus($payload['status'] ?? null) ?? SeedingSocialAccountStatus::Active;
        $username = $this->nullableCredential($payload['username'] ?? null);
        $password = $this->nullableCredential($payload['password'] ?? null);

        $row = new SeedingSocialAccount([
            'installation_id' => $this->resolver->installationNamespace(),
            'site_id' => $identity['site_id'],
            'domain' => $identity['domain'],
            'platform' => $platform,
            'label' => $this->nullableLabel($payload['label'] ?? null),
            'username_encrypted' => $username,
            'password_encrypted' => $password,
            'status' => $status,
            'meta_json' => $this->sanitizeMeta($payload['meta'] ?? null),
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
            'credential_updated_at' => ($username !== null || $password !== null) ? now() : null,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * @param  array{
     *   site_id?: int|null,
     *   domain?: string|null,
     *   platform?: string|null,
     *   label?: string|null,
     *   username?: string|null,
     *   password?: string|null,
     *   status?: string|null,
     *   meta?: array<string, mixed>|null
     * }  $payload
     */
    public function update(int $actorUserId, int $accountId, array $payload): SeedingSocialAccount
    {
        $row = $this->findForInstallation($accountId);
        $credentialTouched = false;

        if (array_key_exists('site_id', $payload) || array_key_exists('domain', $payload)) {
            $identity = $this->resolveSiteDomain([
                'site_id' => $payload['site_id'] ?? $row->site_id,
                'domain' => $payload['domain'] ?? $row->domain,
            ]);
            $row->site_id = $identity['site_id'];
            $row->domain = $identity['domain'];
        }

        if (array_key_exists('platform', $payload) && $payload['platform'] !== null) {
            $row->platform = $this->requirePlatform($payload['platform']);
        }
        if (array_key_exists('label', $payload)) {
            $row->label = $this->nullableLabel($payload['label']);
        }
        if (array_key_exists('status', $payload) && $payload['status'] !== null) {
            $status = $this->resolveStatus($payload['status']);
            if ($status instanceof SeedingSocialAccountStatus) {
                $row->status = $status;
            }
        }
        if (array_key_exists('meta', $payload)) {
            $row->meta_json = $this->sanitizeMeta($payload['meta']);
        }

        if (array_key_exists('username', $payload)) {
            $username = $this->nullableCredential($payload['username']);
            if ($username !== null) {
                $row->username_encrypted = $username;
                $credentialTouched = true;
            }
        }

        // Blank password on update = keep current encrypted value.
        if (array_key_exists('password', $payload)) {
            $password = $this->nullableCredential($payload['password']);
            if ($password !== null) {
                $row->password_encrypted = $password;
                $credentialTouched = true;
            }
        }

        if ($credentialTouched) {
            $row->credential_updated_at = now();
        }
        $row->updated_by = $actorUserId > 0 ? $actorUserId : $row->updated_by;
        $row->save();

        return $row->fresh() ?? $row;
    }

    public function lock(int $actorUserId, int $accountId): SeedingSocialAccount
    {
        $row = $this->findForInstallation($accountId);
        $row->status = SeedingSocialAccountStatus::Locked;
        $row->updated_by = $actorUserId > 0 ? $actorUserId : $row->updated_by;
        $row->save();

        return $row->fresh() ?? $row;
    }

    public function unlock(int $actorUserId, int $accountId): SeedingSocialAccount
    {
        $row = $this->findForInstallation($accountId);
        $row->status = SeedingSocialAccountStatus::Active;
        $row->updated_by = $actorUserId > 0 ? $actorUserId : $row->updated_by;
        $row->save();

        return $row->fresh() ?? $row;
    }

    public function delete(int $accountId): void
    {
        $row = $this->findForInstallation($accountId);
        $row->delete();
    }

    /**
     * Explicit Manager copy — decrypts only the requested credential.
     */
    public function copyUsername(int $accountId): string
    {
        $row = $this->findForInstallation($accountId);
        if (! $row->hasUsername()) {
            throw new InvalidArgumentException('Tài khoản chưa có username');
        }
        $plain = $row->username_encrypted;
        if (! is_string($plain) || $plain === '') {
            throw new InvalidArgumentException('Tài khoản chưa có username');
        }

        return $plain;
    }

    public function copyPassword(int $accountId): string
    {
        $row = $this->findForInstallation($accountId);
        if (! $row->hasPassword()) {
            throw new InvalidArgumentException('Tài khoản chưa có password');
        }
        $plain = $row->password_encrypted;
        if (! is_string($plain) || $plain === '') {
            throw new InvalidArgumentException('Tài khoản chưa có password');
        }

        return $plain;
    }

    public function findForInstallation(int $accountId): SeedingSocialAccount
    {
        if ($accountId <= 0 || ! $this->tableReady()) {
            throw new NotFoundHttpException('Không tìm thấy tài khoản social');
        }

        $row = SeedingSocialAccount::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->whereKey($accountId)
            ->first();

        if (! $row instanceof SeedingSocialAccount) {
            throw new NotFoundHttpException('Không tìm thấy tài khoản social');
        }

        return $row;
    }

    /**
     * @return list<array{id: int, domain: string}>
     */
    private function accessibleSites(): array
    {
        return $this->access->accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain'])
            ->map(static fn (Site $site): array => [
                'id' => (int) $site->id,
                'domain' => (string) $site->domain,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function platformOptions(): array
    {
        return array_map(
            static fn (SeedingSocialPlatform $p): array => [
                'value' => $p->value,
                'label' => $p->label(),
            ],
            SeedingSocialPlatform::cases(),
        );
    }

    /**
     * @param  array{site_id?: int|null, domain?: string|null}  $payload
     * @return array{site_id: int|null, domain: string}
     */
    private function resolveSiteDomain(array $payload): array
    {
        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : 0;
        if ($siteId > 0) {
            $this->access->assertCanAccessSite($siteId);
            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                throw new InvalidArgumentException('Site không tồn tại');
            }
            $domain = strtolower(trim((string) $site->domain));
            if ($domain === '') {
                throw new InvalidArgumentException('Site thiếu domain');
            }

            return ['site_id' => $siteId, 'domain' => $domain];
        }

        $domain = strtolower(trim((string) ($payload['domain'] ?? '')));
        if ($domain === '') {
            throw new InvalidArgumentException('Chọn Domain/Site');
        }

        return ['site_id' => null, 'domain' => $domain];
    }

    private function requirePlatform(mixed $raw): SeedingSocialPlatform
    {
        $platform = SeedingSocialPlatform::tryFromLabelOrValue(
            is_string($raw) ? $raw : null
        );
        if (! $platform instanceof SeedingSocialPlatform) {
            throw new InvalidArgumentException('Platform không hợp lệ');
        }

        return $platform;
    }

    private function resolveStatus(mixed $raw): ?SeedingSocialAccountStatus
    {
        if ($raw === null) {
            return null;
        }

        $status = SeedingSocialAccountStatus::tryFromLabelOrValue(
            is_string($raw) ? $raw : null
        );
        if (! $status instanceof SeedingSocialAccountStatus) {
            throw new InvalidArgumentException('Status không hợp lệ');
        }

        return $status;
    }

    private function nullableLabel(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);

        return $trimmed !== '' ? mb_substr($trimmed, 0, 255) : null;
    }

    private function nullableCredential(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Non-secret metadata only — strip known secret keys if present.
     *
     * @return array<string, mixed>|null
     */
    private function sanitizeMeta(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $blocked = ['password', 'token', 'secret', 'api_key', 'access_token', 'refresh_token'];
        $clean = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $lower = strtolower($key);
            if (in_array($lower, $blocked, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
