<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Validate pinned dependencies against an already-published release catalog (pure).
 *
 * @phpstan-type PublishedRelease array{
 *     package_key: string,
 *     version: string,
 *     content_digest: string,
 *     status?: string
 * }
 * @phpstan-type Dependency array{
 *     package_key: string,
 *     version: string,
 *     content_digest: string
 * }
 */
final class PackageDependencyValidator
{
    /**
     * @param  list<Dependency>  $dependencies
     * @param  list<PublishedRelease>  $published
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(array $dependencies, array $published): array
    {
        if ($dependencies === []) {
            return ['ok' => true, 'errors' => []];
        }

        $index = [];
        foreach ($published as $row) {
            $key = strtolower(trim((string) ($row['package_key'] ?? '')));
            $version = trim((string) ($row['version'] ?? ''));
            if ($key === '' || $version === '') {
                continue;
            }
            $status = strtolower(trim((string) ($row['status'] ?? 'published')));
            if ($status !== '' && $status !== 'published') {
                continue;
            }
            $index[$key.'|'.$version] = strtolower(trim((string) ($row['content_digest'] ?? '')));
        }

        $errors = [];
        foreach ($dependencies as $i => $dep) {
            $depKey = strtolower(trim((string) ($dep['package_key'] ?? '')));
            $depVersion = trim((string) ($dep['version'] ?? ''));
            $depDigest = strtolower(trim((string) ($dep['content_digest'] ?? '')));
            $lookup = $depKey.'|'.$depVersion;
            if (! array_key_exists($lookup, $index)) {
                $errors[] = 'dependency_missing:'.$i;
                continue;
            }
            if (! PackageDigest::equals($index[$lookup], $depDigest)) {
                $errors[] = 'dependency_digest_mismatch:'.$i;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
