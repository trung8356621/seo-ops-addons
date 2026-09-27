<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

use InvalidArgumentException;

final class RetrievalDecisionParser
{
    public function parse(string $raw): RetrievalDecision
    {
        $decoded = $this->decodeObject($raw);
        $intent = trim((string) ($decoded['intent'] ?? ''));
        if ($intent === '') {
            throw new InvalidArgumentException('Retrieval decision is missing intent.');
        }

        $needs = [];
        $needsRaw = $decoded['needs'] ?? [];
        if (! is_array($needsRaw)) {
            throw new InvalidArgumentException('Retrieval decision needs must be an object.');
        }
        foreach ($needsRaw as $key => $value) {
            $name = trim((string) $key);
            if ($name === '' || ! is_numeric($value)) {
                throw new InvalidArgumentException('Retrieval decision need scores must be numeric.');
            }
            $score = (float) $value;
            if ($score < 0.0 || $score > 1.0) {
                throw new InvalidArgumentException('Retrieval decision need scores must be between 0 and 1.');
            }
            $needs[$name] = $score;
        }

        $parameters = [];
        $paramsRaw = $decoded['parameters'] ?? [];
        if (! is_array($paramsRaw)) {
            throw new InvalidArgumentException('Retrieval decision parameters must be an object.');
        }
        foreach ($paramsRaw as $key => $value) {
            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException('Retrieval decision parameters must be scalar.');
            }
            $parameters[trim((string) $key)] = trim((string) $value);
        }

        return new RetrievalDecision(
            intent: $intent,
            needs: $needs,
            parameters: $parameters,
            requiresParameterExtraction: (bool) ($decoded['requires_parameter_extraction'] ?? false),
            requiresUserConfirmation: (bool) ($decoded['requires_user_confirmation'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $match) === 1) {
            $raw = $match[1];
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new InvalidArgumentException('Retrieval decision is not a JSON object.');
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Retrieval decision JSON is invalid.');
        }

        return $decoded;
    }
}
