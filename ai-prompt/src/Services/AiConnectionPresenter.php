<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode;

/**
 * Connection display metadata: short code + deterministic badge variant.
 */
final class AiConnectionPresenter
{
    public const BADGE_VARIANT_COUNT = 12;

    /** @var array<int, array<int, string>> */
    private array $workspaceCodes = [];

    public function __construct(
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
        private readonly ProviderConnectionResolver $templates = new ProviderConnectionResolver(),
    ) {}

    public function forgetMemo(): void
    {
        $this->workspaceCodes = [];
    }

    /**
     * Effective short code for a connection (collision-safe within workspace).
     */
    public function shortCode(ApiConnection $connection, ?int $userId = null): string
    {
        $uid = $userId ?? (int) ($connection->user_id ?? 0);
        if ($uid > 0) {
            $map = $this->codesForUser($uid);
            $id = (int) $connection->id;
            if (isset($map[$id])) {
                return $map[$id];
            }
        }

        return $this->baseCode($connection);
    }

    /**
     * @return array<int, string> connectionId => short code
     */
    public function codesForUser(int $userId): array
    {
        if (isset($this->workspaceCodes[$userId])) {
            return $this->workspaceCodes[$userId];
        }
        $baseById = [];
        foreach ($this->priorities->aiConnections($userId) as $connection) {
            $baseById[(int) $connection->id] = $this->baseCode($connection);
        }
        ksort($baseById);
        $used = [];
        $out = [];
        foreach ($baseById as $id => $base) {
            $candidate = $base;
            $n = 2;
            while (isset($used[$candidate])) {
                $candidate = $this->withNumericSuffix($base, $n);
                $n++;
            }
            $used[$candidate] = true;
            $out[$id] = $candidate;
        }

        return $this->workspaceCodes[$userId] = $out;
    }

    /**
     * Badge palette index identity is the connection id (stable across label text changes).
     */
    public function badgeVariant(ApiConnection $connection): string
    {
        $id = max(1, (int) $connection->id);
        $index = (int) (($id * 2654435761) % self::BADGE_VARIANT_COUNT);

        return 'badge-'.($index + 1);
    }

    public function baseCode(ApiConnection $connection): string
    {
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $override = AiConnectionShortCode::normalize(
            isset($meta['display_code']) ? (string) $meta['display_code'] : null,
        );
        if ($override !== null) {
            return $override;
        }

        $fromTemplate = $this->templateShortCode($connection);
        if ($fromTemplate !== null) {
            return $fromTemplate;
        }

        $builtin = AiConnectionShortCode::builtin((string) $connection->provider);
        if ($builtin !== null) {
            return $builtin;
        }

        $name = trim((string) $connection->name);
        if ($name === '') {
            $name = (string) $connection->provider;
        }

        return AiConnectionShortCode::generate($name !== '' ? $name : 'AI');
    }

    private function templateShortCode(ApiConnection $connection): ?string
    {
        try {
            $resolved = $this->templates->resolve($connection);
            $code = $resolved->template->shortCode;

            return AiConnectionShortCode::normalize($code);
        } catch (\Throwable) {
            return AiConnectionShortCode::builtin((string) $connection->provider);
        }
    }

    private function withNumericSuffix(string $base, int $n): string
    {
        $suffix = (string) $n;
        $maxBase = AiConnectionShortCode::MAX_LENGTH - strlen($suffix);
        if ($maxBase < AiConnectionShortCode::MIN_LENGTH) {
            $maxBase = AiConnectionShortCode::MIN_LENGTH;
        }
        $trimmed = substr($base, 0, $maxBase);

        return AiConnectionShortCode::normalize($trimmed.$suffix) ?? ($trimmed.$suffix);
    }
}
