<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application;

use InvalidArgumentException;

/**
 * Stable public refs — opaque với client; decode nội bộ về numeric ID.
 * Mirror pattern của ContentProjectPublicRef.
 */
final class KeywordIntelligencePublicRef
{
    public static function workspace(int $id): string
    {
        return self::encode('kww', $id);
    }

    public static function keyword(int $id): string
    {
        return self::encode('kw', $id);
    }

    public static function cluster(int $id): string
    {
        return self::encode('kwc', $id);
    }

    public static function topic(int $id): string
    {
        return self::encode('kwt', $id);
    }

    public static function mapVersion(int $id): string
    {
        return self::encode('tmv', $id);
    }

    public static function operation(int $id): string
    {
        return self::encode('kwa', $id);
    }

    public static function relationship(int $id): string
    {
        return self::encode('kwrel', $id);
    }

    public static function articleMapping(int $id): string
    {
        return self::encode('kwam', $id);
    }

    public static function topicClusterLink(int $id): string
    {
        return self::encode('kwtcl', $id);
    }

    public static function topicalLinkSuggestion(int $id): string
    {
        return self::linkSuggestion($id);
    }

    public static function decodeWorkspace(string $ref): int
    {
        return self::decode('kww', $ref);
    }

    public static function decodeKeyword(string $ref): int
    {
        return self::decode('kw', $ref);
    }

    public static function decodeCluster(string $ref): int
    {
        return self::decode('kwc', $ref);
    }

    public static function decodeTopic(string $ref): int
    {
        return self::decode('kwt', $ref);
    }

    public static function decodeMapVersion(string $ref): int
    {
        return self::decode('tmv', $ref);
    }

    public static function decodeOperation(string $ref): int
    {
        return self::decode('kwa', $ref);
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kww_* refs.
     */
    public static function resolveWorkspaceIdStrict(string $ref): int
    {
        return self::resolveStrict('kww', $ref, fn (string $r): int => self::decodeWorkspace($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kw_* refs.
     */
    public static function resolveKeywordIdStrict(string $ref): int
    {
        return self::resolveStrict('kw', $ref, fn (string $r): int => self::decodeKeyword($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kwc_* refs.
     */
    public static function resolveClusterIdStrict(string $ref): int
    {
        return self::resolveStrict('kwc', $ref, fn (string $r): int => self::decodeCluster($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kwt_* refs.
     */
    public static function resolveTopicIdStrict(string $ref): int
    {
        return self::resolveStrict('kwt', $ref, fn (string $r): int => self::decodeTopic($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque tmv_* refs.
     */
    public static function resolveMapVersionIdStrict(string $ref): int
    {
        return self::resolveStrict('tmv', $ref, fn (string $r): int => self::decodeMapVersion($r));
    }

    public static function linkSuggestion(int $id): string
    {
        return self::encode('ktls', $id);
    }

    public static function decodeLinkSuggestion(string $ref): int
    {
        return self::decode('ktls', $ref);
    }

    public static function resolveLinkSuggestionIdStrict(string $ref): int
    {
        return self::resolveStrict('ktls', $ref, fn (string $r): int => self::decodeLinkSuggestion($r));
    }

    public static function conversion(int $id): string
    {
        return self::encode('kpc', $id);
    }

    public static function decodeConversion(string $ref): int
    {
        return self::decode('kpc', $ref);
    }

    public static function resolveConversionIdStrict(string $ref): int
    {
        return self::resolveStrict('kpc', $ref, fn (string $r): int => self::decodeConversion($r));
    }

    public static function contentProjectLink(int $id): string
    {
        return self::encode('kcpl', $id);
    }

    public static function decodeContentProjectLink(string $ref): int
    {
        return self::decode('kcpl', $ref);
    }

    public static function resolveContentProjectLinkIdStrict(string $ref): int
    {
        return self::resolveStrict('kcpl', $ref, fn (string $r): int => self::decodeContentProjectLink($r));
    }

    /**
     * Public API — reject bare numeric IDs; only opaque kwa_* refs.
     */
    public static function resolveOperationIdStrict(string $ref): int
    {
        return self::resolveStrict('kwa', $ref, fn (string $r): int => self::decodeOperation($r));
    }

    public static function serpQuery(int $id): string
    {
        return self::encode('srpq', $id);
    }

    public static function serpSnapshot(int $id): string
    {
        return self::encode('srps', $id);
    }

    public static function serpResult(int $id): string
    {
        return self::encode('srpr', $id);
    }

    public static function serpFeature(int $id): string
    {
        return self::encode('srpf', $id);
    }

    public static function serpPageEvidence(int $id): string
    {
        return self::encode('srpe', $id);
    }

    public static function serpClusterEvidence(int $id): string
    {
        return self::encode('srpc', $id);
    }

    public static function serpContentGap(int $id): string
    {
        return self::encode('srpg', $id);
    }

    public static function decodeSerpQuery(string $ref): int
    {
        return self::decode('srpq', $ref);
    }

    public static function decodeSerpSnapshot(string $ref): int
    {
        return self::decode('srps', $ref);
    }

    public static function decodeSerpResult(string $ref): int
    {
        return self::decode('srpr', $ref);
    }

    public static function decodeSerpFeature(string $ref): int
    {
        return self::decode('srpf', $ref);
    }

    public static function decodeSerpPageEvidence(string $ref): int
    {
        return self::decode('srpe', $ref);
    }

    public static function decodeSerpClusterEvidence(string $ref): int
    {
        return self::decode('srpc', $ref);
    }

    public static function decodeSerpContentGap(string $ref): int
    {
        return self::decode('srpg', $ref);
    }

    public static function resolveSerpQueryIdStrict(string $ref): int
    {
        return self::resolveStrict('srpq', $ref, fn (string $r): int => self::decodeSerpQuery($r));
    }

    public static function resolveSerpSnapshotIdStrict(string $ref): int
    {
        return self::resolveStrict('srps', $ref, fn (string $r): int => self::decodeSerpSnapshot($r));
    }

    public static function resolveSerpResultIdStrict(string $ref): int
    {
        return self::resolveStrict('srpr', $ref, fn (string $r): int => self::decodeSerpResult($r));
    }

    public static function resolveSerpFeatureIdStrict(string $ref): int
    {
        return self::resolveStrict('srpf', $ref, fn (string $r): int => self::decodeSerpFeature($r));
    }

    public static function resolveSerpPageEvidenceIdStrict(string $ref): int
    {
        return self::resolveStrict('srpe', $ref, fn (string $r): int => self::decodeSerpPageEvidence($r));
    }

    public static function resolveSerpClusterEvidenceIdStrict(string $ref): int
    {
        return self::resolveStrict('srpc', $ref, fn (string $r): int => self::decodeSerpClusterEvidence($r));
    }

    public static function resolveSerpContentGapIdStrict(string $ref): int
    {
        return self::resolveStrict('srpg', $ref, fn (string $r): int => self::decodeSerpContentGap($r));
    }

    public static function gscProperty(int $id): string
    {
        return self::encode('gscp', $id);
    }

    public static function gscSyncRun(int $id): string
    {
        return self::encode('gscs', $id);
    }

    public static function gscQueryMapping(int $id): string
    {
        return self::encode('gscq', $id);
    }

    public static function gscPageMapping(int $id): string
    {
        return self::encode('gscm', $id);
    }

    public static function gscPerformanceAggregate(int $id): string
    {
        return self::encode('gsca', $id);
    }

    public static function gscOpportunity(int $id): string
    {
        return self::encode('gsco', $id);
    }

    public static function decodeGscProperty(string $ref): int
    {
        return self::decode('gscp', $ref);
    }

    public static function decodeGscSyncRun(string $ref): int
    {
        return self::decode('gscs', $ref);
    }

    public static function decodeGscQueryMapping(string $ref): int
    {
        return self::decode('gscq', $ref);
    }

    public static function decodeGscPageMapping(string $ref): int
    {
        return self::decode('gscm', $ref);
    }

    public static function decodeGscPerformanceAggregate(string $ref): int
    {
        return self::decode('gsca', $ref);
    }

    public static function decodeGscOpportunity(string $ref): int
    {
        return self::decode('gsco', $ref);
    }

    public static function resolveGscPropertyIdStrict(string $ref): int
    {
        return self::resolveStrict('gscp', $ref, fn (string $r): int => self::decodeGscProperty($r));
    }

    public static function resolveGscSyncRunIdStrict(string $ref): int
    {
        return self::resolveStrict('gscs', $ref, fn (string $r): int => self::decodeGscSyncRun($r));
    }

    public static function resolveGscQueryMappingIdStrict(string $ref): int
    {
        return self::resolveStrict('gscq', $ref, fn (string $r): int => self::decodeGscQueryMapping($r));
    }

    public static function resolveGscPageMappingIdStrict(string $ref): int
    {
        return self::resolveStrict('gscm', $ref, fn (string $r): int => self::decodeGscPageMapping($r));
    }

    public static function resolveGscPerformanceAggregateIdStrict(string $ref): int
    {
        return self::resolveStrict('gsca', $ref, fn (string $r): int => self::decodeGscPerformanceAggregate($r));
    }

    public static function resolveGscOpportunityIdStrict(string $ref): int
    {
        return self::resolveStrict('gsco', $ref, fn (string $r): int => self::decodeGscOpportunity($r));
    }

    /**
     * @param  callable(string): int  $decoder
     */
    private static function resolveStrict(string $prefix, string $ref, callable $decoder): int
    {
        $ref = trim($ref);
        if ($ref === '' || ctype_digit($ref)) {
            throw new InvalidArgumentException("Ref must be opaque {$prefix}_* identifier.");
        }

        if (! str_starts_with($ref, $prefix.'_')) {
            throw new InvalidArgumentException("Ref must be opaque {$prefix}_* identifier.");
        }

        return $decoder($ref);
    }

    private static function encode(string $prefix, int $id): string
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid id for public ref.');
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
