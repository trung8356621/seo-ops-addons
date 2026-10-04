<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

final class AgentResponseTemplateCatalog
{
    /** @return list<array{key: string, description: string}> */
    public static function modelVisible(): array
    {
        return [
            ['key' => 'text', 'description' => 'Simple answer, explanation, clarification, or short recommendation.'],
            ['key' => 'table', 'description' => 'A concrete list of comparable records where rows and columns are the primary presentation.'],
            ['key' => 'schema', 'description' => 'One main entity or object with named attributes or grouped fields.'],
            ['key' => 'report', 'description' => 'Analysis or synthesis requiring multiple sections, evidence types, findings, implications, or recommendations.'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::modelVisible(), 'key');
    }

    public static function supports(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
