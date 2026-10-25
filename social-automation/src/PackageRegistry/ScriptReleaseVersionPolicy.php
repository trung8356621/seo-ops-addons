<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

final class ScriptReleaseVersionPolicy
{
    /** @return array{ok: bool, ordinal?: int, legacy?: bool, error?: string} */
    public function parse(string $version): array
    {
        $version = trim($version);
        if (preg_match('/^[1-9][0-9]*$/', $version) === 1) {
            return ['ok' => true, 'ordinal' => (int) $version, 'legacy' => false];
        }
        if (preg_match('/^([1-9][0-9]*)\.0\.0$/', $version, $match) === 1) {
            return ['ok' => true, 'ordinal' => (int) $match[1], 'legacy' => true];
        }

        return ['ok' => false, 'error' => 'script_version_compatibility_unsupported'];
    }

    /** @return list<string> */
    public function validateNext(?string $previous, string $incoming): array
    {
        $next = $this->parse($incoming);
        if (! ($next['ok'] ?? false)) {
            return [(string) $next['error']];
        }
        if ($previous === null) {
            return ($next['legacy'] ?? false) || ($next['ordinal'] ?? 0) !== 1
                ? ['script_version_sequence_conflict']
                : [];
        }

        $prior = $this->parse($previous);
        if (! ($prior['ok'] ?? false)) {
            return [(string) $prior['error']];
        }

        return ($next['legacy'] ?? false) || ($next['ordinal'] ?? 0) !== ($prior['ordinal'] ?? 0) + 1
            ? ['script_version_sequence_conflict']
            : [];
    }

    /** @return list<string> */
    public function validateCanonicalChange(?string $previousExecutionDigest, string $incomingExecutionDigest): array
    {
        return $previousExecutionDigest !== null
            && hash_equals(strtolower($previousExecutionDigest), strtolower($incomingExecutionDigest))
            ? ['script_content_unchanged']
            : [];
    }
}
