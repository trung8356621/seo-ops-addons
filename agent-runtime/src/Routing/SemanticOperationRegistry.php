<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

final class SemanticOperationRegistry
{
    public const SEO_AUDIT_PATH = '/seo/content-projects/seo-audit';

    /** @return array<string, array{label: string, operations: array<string, array<string, mixed>>}> */
    public static function modules(): array
    {
        $document = SemanticRoutingConfig::builtInRegistry()->document();
        $modules = [];
        foreach ((array) ($document['modules'] ?? []) as $key => $_groups) {
            $modules[$key] = ['label' => $key, 'operations' => array_filter(
                (array) ($document['operations'] ?? []),
                static fn (string $ref): bool => str_starts_with($ref, $key.'.'),
                ARRAY_FILTER_USE_KEY,
            )];
        }
        return $modules;
    }

    /** @return list<string> */
    public static function moduleKeys(): array
    {
        return array_keys(self::modules());
    }

    /** @return array<string, mixed>|null */
    public static function operation(string $ref): ?array
    {
        foreach (self::modules() as $module) {
            if (isset($module['operations'][$ref])) {
                return $module['operations'][$ref];
            }
        }

        return null;
    }

    public static function knownOperation(string $ref): bool
    {
        return self::operation($ref) !== null;
    }

    public static function knownModule(string $ref): bool
    {
        return isset(self::modules()[$ref]);
    }

}
