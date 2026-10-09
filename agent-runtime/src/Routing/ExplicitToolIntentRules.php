<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

/**
 * Legacy substring helper. It is not a routing authority.
 * Business preferences belong in semantic routing settings.
 */
final class ExplicitToolIntentRules
{
    /**
     * @param  list<array{key: string, positive_examples: list<string>, negative_examples: list<string>}>  $intents
     * @return array{status: string, matches: list<array{ref: string, score: float, lexical: bool}>}
     */
    public function match(string $message, array $intents): array
    {
        $haystack = mb_strtolower(trim($message));
        $hits = [];
        foreach ($intents as $intent) {
            foreach ($intent['positive_examples'] as $example) {
                $needle = mb_strtolower(trim($example));
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $hits[] = [
                        'ref' => $intent['key'],
                        'score' => 1.0,
                        'lexical' => true,
                    ];
                    break;
                }
            }
        }

        if ($hits === []) {
            return ['status' => 'none', 'matches' => []];
        }
        if (count($hits) > 1) {
            return ['status' => 'ambiguous', 'matches' => $hits];
        }

        return ['status' => 'confident', 'matches' => $hits];
    }
}
