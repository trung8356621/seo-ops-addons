<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

final class TopicSuggestionsResponseParser
{
    /**
     * @param  list<string>  $allowedTopicRefs
     * @return array{topics: list<array{ref: string, ideas: list<array{0: string, 1: string}>}>, suggestions: list<array{topic_ref: string, title: string, keyword: string}>}
     *
     * @throws AgentResponseRejected
     */
    public function parse(string $raw, array $allowedTopicRefs): array
    {
        $clean = trim($raw);
        if ($clean === '') {
            throw new AgentResponseRejected('Topic suggestions response is empty.');
        }

        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $clean, $matches)) {
            $clean = trim($matches[1]);
        }

        try {
            $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new AgentResponseRejected('Topic suggestions JSON is invalid.');
        }

        if (! is_array($decoded)) {
            throw new AgentResponseRejected('Topic suggestions JSON must be an object.');
        }

        if (! isset($decoded['topics']) || ! is_array($decoded['topics'])) {
            throw new AgentResponseRejected('Topic suggestions response must contain a "topics" array.');
        }

        $allowedSet = array_flip($allowedTopicRefs);
        $totalIdeas = 0;
        $maxIdeas = 15;
        $seenPairs = [];
        $validatedGroups = [];
        $flatSuggestions = [];

        foreach ($decoded['topics'] as $groupIndex => $group) {
            if (! is_array($group)) {
                throw new AgentResponseRejected("Topic group at index {$groupIndex} must be an object.");
            }

            // Each group must contain ref and ideas
            if (! array_key_exists('ref', $group)) {
                throw new AgentResponseRejected("Topic group at index {$groupIndex} is missing \"ref\".");
            }
            if (! array_key_exists('ideas', $group)) {
                throw new AgentResponseRejected("Topic group at index {$groupIndex} is missing \"ideas\".");
            }

            $ref = trim((string) $group['ref']);
            if ($ref === '') {
                throw new AgentResponseRejected("Topic group at index {$groupIndex} has empty \"ref\".");
            }

            if (! isset($allowedSet[$ref])) {
                throw new AgentResponseRejected("Topic ref [{$ref}] is not present in authorized topic evidence.");
            }

            if (! is_array($group['ideas'])) {
                throw new AgentResponseRejected("Ideas in topic group [{$ref}] must be an array.");
            }

            $groupIdeas = [];
            foreach ($group['ideas'] as $ideaIndex => $idea) {
                if (! is_array($idea)) {
                    throw new AgentResponseRejected("Idea at index {$ideaIndex} in topic [{$ref}] must be a 2-element array [keyword, title].");
                }

                if (count($idea) !== 2 || ! array_key_exists(0, $idea) || ! array_key_exists(1, $idea)) {
                    throw new AgentResponseRejected("Idea at index {$ideaIndex} in topic [{$ref}] must have exactly two non-empty strings [keyword, title].");
                }

                if (! is_string($idea[0]) || ! is_string($idea[1])) {
                    throw new AgentResponseRejected("Idea elements in topic [{$ref}] at index {$ideaIndex} must be strings.");
                }

                $keyword = trim($idea[0]);
                $title = trim($idea[1]);

                if ($keyword === '') {
                    throw new AgentResponseRejected("Idea at index {$ideaIndex} in topic [{$ref}] has an empty keyword.");
                }
                if ($title === '') {
                    throw new AgentResponseRejected("Idea at index {$ideaIndex} in topic [{$ref}] has an empty title.");
                }

                // Deterministic deduplication
                $dedupKey = mb_strtolower($keyword).'|'.mb_strtolower($title);
                if (isset($seenPairs[$dedupKey])) {
                    continue;
                }
                $seenPairs[$dedupKey] = true;

                $totalIdeas++;
                if ($totalIdeas > $maxIdeas) {
                    throw new AgentResponseRejected("Total accepted ideas exceeds maximum allowed limit of {$maxIdeas}.");
                }

                $groupIdeas[] = [$keyword, $title];
                $flatSuggestions[] = [
                    'topic_ref' => $ref,
                    'title' => $title,
                    'keyword' => $keyword,
                ];
            }

            if ($groupIdeas !== []) {
                $validatedGroups[] = [
                    'ref' => $ref,
                    'ideas' => $groupIdeas,
                ];
            }
        }

        if ($flatSuggestions === []) {
            throw new AgentResponseRejected('Topic suggestions list is empty.');
        }

        return [
            'topics' => $validatedGroups,
            'suggestions' => $flatSuggestions,
        ];
    }
}
