<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use InvalidArgumentException;

/**
 * Opaque public refs for agent plans, steps, policies, approvals.
 */
final class ContentProjectAgentPlanRef
{
    public static function plan(int $id): string
    {
        return self::encode('apl', $id);
    }

    public static function step(int $id): string
    {
        return self::encode('aps', $id);
    }

    public static function policy(int $id): string
    {
        return self::encode('apy', $id);
    }

    public static function approval(int $id): string
    {
        return self::encode('apv', $id);
    }

    public static function decodePlan(string $ref): int
    {
        return self::decode('apl', $ref);
    }

    public static function decodeStep(string $ref): int
    {
        return self::decode('aps', $ref);
    }

    public static function decodePolicy(string $ref): int
    {
        return self::decode('apy', $ref);
    }

    public static function decodeApproval(string $ref): int
    {
        return self::decode('apv', $ref);
    }

    public static function resolvePlanIdStrict(string $ref): int
    {
        return self::resolveStrict('apl', $ref, 'Plan ref must be opaque apl_* identifier.');
    }

    public static function resolveStepIdStrict(string $ref): int
    {
        return self::resolveStrict('aps', $ref, 'Step ref must be opaque aps_* identifier.');
    }

    public static function resolvePolicyIdStrict(string $ref): int
    {
        return self::resolveStrict('apy', $ref, 'Policy ref must be opaque apy_* identifier.');
    }

    public static function resolveApprovalIdStrict(string $ref): int
    {
        return self::resolveStrict('apv', $ref, 'Approval ref must be opaque apv_* identifier.');
    }

    private static function resolveStrict(string $prefix, string $ref, string $message): int
    {
        $ref = trim($ref);
        if ($ref === '' || ctype_digit($ref) || ! str_starts_with($ref, $prefix.'_')) {
            throw new InvalidArgumentException($message);
        }

        return self::decode($prefix, $ref);
    }

    private static function encode(string $prefix, int $id): string
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid id for agent plan ref.');
        }

        $payload = rtrim(strtr(base64_encode(pack('N', $id)), '+/', '-_'), '=');
        $checksum = substr(hash('xxh3', $prefix.'|'.$id), 0, 6);

        return $prefix.'_'.$payload.'_'.$checksum;
    }

    private static function decode(string $prefix, string $ref): int
    {
        $ref = trim($ref);
        if (! preg_match('/^'.preg_quote($prefix, '/').'_([A-Za-z0-9_-]+)_([a-f0-9]{6})$/', $ref, $m)) {
            throw new InvalidArgumentException("Invalid {$prefix} ref.");
        }

        $raw = base64_decode(strtr($m[1], '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 4) {
            throw new InvalidArgumentException("Invalid {$prefix} ref payload.");
        }

        $unpacked = unpack('Nid', substr($raw, 0, 4));
        $id = (int) ($unpacked['id'] ?? 0);
        if ($id <= 0 || substr(hash('xxh3', $prefix.'|'.$id), 0, 6) !== $m[2]) {
            throw new InvalidArgumentException("Invalid {$prefix} ref checksum.");
        }

        return $id;
    }
}
