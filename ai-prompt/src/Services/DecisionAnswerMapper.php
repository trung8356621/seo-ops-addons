<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

/**
 * Maps a Decisions API answers object onto the routing JSON the runtime already parses.
 * Need scores are the returned noul values. Missing answers are omitted, not written as zero.
 */
final class DecisionAnswerMapper
{
    /**
     * Same cutoff the retrieval planner uses for "needs this resource".
     * Boolean flags in the routing schema are not probabilities.
     */
    public const FLAG_THRESHOLD = 0.5;

    /**
     * @param  array<string, mixed>  $response
     */
    public static function toRoutingJson(array $response): string
    {
        $answers = is_array($response['answers'] ?? null) ? $response['answers'] : [];
        $needs = [];
        foreach ([
            'site' => 'need_site',
            'keywords' => 'need_keywords',
            'gsc' => 'need_gsc',
        ] as $resource => $question) {
            $score = self::noul($answers, $question);
            if ($score !== null) {
                $needs[$resource] = $score;
            }
        }

        $payload = [
            'intent' => 'routing',
            'needs' => $needs,
            'parameters' => [],
            'requires_parameter_extraction' => self::flag($answers, 'needs_parameter_extraction'),
            'requires_user_confirmation' => self::flag($answers, 'needs_user_confirmation'),
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private static function noul(array $answers, string $question): ?float
    {
        $row = $answers[$question] ?? null;
        if (! is_array($row) || ! is_numeric($row['noul'] ?? null)) {
            return null;
        }
        $score = (float) $row['noul'];
        if ($score < 0.0 || $score > 1.0) {
            return null;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private static function flag(array $answers, string $question): bool
    {
        $score = self::noul($answers, $question);

        return $score !== null && $score >= self::FLAG_THRESHOLD;
    }
}
