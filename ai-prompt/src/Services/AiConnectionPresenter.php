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

    /** Effective system-owned provider code for a connection. */
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
        $out = [];
        foreach ($baseById as $id => $base) {
            $out[$id] = $base;
        }

        return $this->workspaceCodes[$userId] = $out;
    }

    /** Badge palette identity is the provider, shared by sibling credential lanes. */
    public function badgeVariant(ApiConnection $connection): string
    {
        $provider = strtolower(trim((string) $connection->provider));
        $index = (int) (sprintf('%u', crc32($provider !== '' ? $provider : 'ai')) % self::BADGE_VARIANT_COUNT);

        return 'badge-'.($index + 1);
    }

    public function baseCode(ApiConnection $connection): string
    {
        $provider = (string) $connection->provider;
        $builtin = AiConnectionShortCode::builtin($provider);
        if ($builtin !== null) {
            return $builtin;
        }

        $fromTemplate = $this->templateShortCode($connection);
        if ($fromTemplate !== null) {
            return $fromTemplate;
        }

        return AiConnectionShortCode::generate($provider !== '' ? $provider : 'AI');
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

}
