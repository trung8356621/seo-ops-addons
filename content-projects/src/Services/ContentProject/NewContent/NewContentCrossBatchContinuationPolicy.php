<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Compact accepted-result fingerprints for later internal batches.
 * System automation policy — not Prompt Management / user prompt content.
 */
final class NewContentCrossBatchContinuationPolicy
{
    public const VERSION = 1;

    public const KEY = 'cross_batch_continuation';

    public const MAX_FINGERPRINTS = 80;

    /** Hard cap for continuation prompt tokens (estimator family). */
    public const MAX_CONTINUATION_TOKENS = 900;

    /**
     * @param  list<array{keyword?: string, title?: string, fingerprint?: string, source_signal?: string}>  $accepted
     * @return list<array{keyword: string, title: string, fingerprint: string, source_topic: string}>
     */
    public function compactAccepted(array $accepted): array
    {
        $out = [];
        foreach ($accepted as $row) {
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $fp = trim((string) ($row['fingerprint'] ?? ''));
            if ($fp === '' && ($keyword !== '' || $title !== '')) {
                $fp = NewContentSuggestionIdentity::fingerprint($keyword, $title);
            }
            if ($fp === '') {
                continue;
            }
            $out[] = [
                'keyword' => mb_substr($keyword, 0, 120),
                'title' => mb_substr($title, 0, 160),
                'fingerprint' => $fp,
                'source_topic' => mb_substr(trim((string) ($row['source_signal'] ?? '')), 0, 80),
            ];
            if (count($out) >= self::MAX_FINGERPRINTS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{keyword: string, title: string, fingerprint: string, source_topic: string}>  $acceptedCompact
     * @return list<string>
     */
    public function instructionLines(array $acceptedCompact, int $maxTokens = self::MAX_CONTINUATION_TOKENS): array
    {
        if ($acceptedCompact === []) {
            return [];
        }

        $lines = [
            'SYSTEM AUTOMATION POLICY ('.self::KEY.' v'.self::VERSION.'):',
            '- This is a continuation batch within one planner operation.',
            '- Do NOT repeat already accepted semantic intent (keyword/title/fingerprint) from earlier batches.',
            '- Prefer new angles that diversify Topics still requesting slots.',
            '- Already accepted compact intents:',
        ];
        $estimator = function_exists('app') && app()->bound(\Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator::class)
            ? app(\Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator::class)
            : new \Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator();

        // Prefer newest accepted intents when compacting under token cap.
        $rows = array_reverse($acceptedCompact);
        $kept = [];
        foreach ($rows as $row) {
            $kw = $row['keyword'] !== '' ? $row['keyword'] : '(keyword)';
            $title = $row['title'] !== '' ? $row['title'] : '(title)';
            $topic = $row['source_topic'] !== '' ? ' · topic='.$row['source_topic'] : '';
            $candidate = '- '.$kw.' | '.$title.$topic;
            $probe = array_merge($lines, array_reverse(array_merge($kept, [$candidate])));
            if ($estimator->estimateParts($probe) > max(200, $maxTokens)) {
                break;
            }
            $kept[] = $candidate;
            if (count($kept) >= self::MAX_FINGERPRINTS) {
                break;
            }
        }

        return array_merge($lines, array_reverse($kept));
    }

    /**
     * Pull the automation continuation block from a compiled brief (for repair re-inject).
     */
    public static function extractBlockFromBrief(string $brief): string
    {
        $marker = 'SYSTEM AUTOMATION POLICY ('.self::KEY;
        $pos = mb_strpos($brief, $marker);
        if ($pos === false) {
            return '';
        }

        return trim(mb_substr($brief, $pos));
    }
}
