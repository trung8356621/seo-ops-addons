<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

final class TopicSuggestionsResponseParser
{
    /**
     * @param  list<string>  $allowedTopicRefs
     * @return array{suggestions: list<array{topic_ref: string, title: string, keyword: string, intent: string, reason: string}>}
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

        if (! isset($decoded['suggestions']) || ! is_array($decoded['suggestions'])) {
            throw new AgentResponseRejected('Topic suggestions response must contain a "suggestions" array.');
        }

        $allowedSet = array_flip($allowedTopicRefs);
        $suggestions = [];

        foreach ($decoded['suggestions'] as $index => $item) {
            if (! is_array($item)) {
                throw new AgentResponseRejected("Suggestion at index {$index} must be an object.");
            }

            $topicRef = trim((string) ($item['topic_ref'] ?? ''));
            if ($topicRef === '') {
                throw new AgentResponseRejected("Suggestion at index {$index} is missing topic_ref.");
            }

            if (! isset($allowedSet[$topicRef])) {
                throw new AgentResponseRejected("Suggestion topic_ref [{$topicRef}] is not present in topic evidence.");
            }

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                throw new AgentResponseRejected("Suggestion at index {$index} is missing title.");
            }

            $keyword = trim((string) ($item['keyword'] ?? ''));
            if ($keyword === '') {
                throw new AgentResponseRejected("Suggestion at index {$index} is missing keyword.");
            }

            $intent = trim((string) ($item['intent'] ?? 'informational'));
            if ($intent === '') {
                $intent = 'informational';
            }

            $reason = trim((string) ($item['reason'] ?? ''));

            $suggestions[] = [
                'topic_ref' => $topicRef,
                'title' => $title,
                'keyword' => $keyword,
                'intent' => $intent,
                'reason' => $reason,
            ];

            if (count($suggestions) >= 5) {
                break;
            }
        }

        if ($suggestions === []) {
            throw new AgentResponseRejected('Topic suggestions list is empty.');
        }

        return ['suggestions' => $suggestions];
    }
}
