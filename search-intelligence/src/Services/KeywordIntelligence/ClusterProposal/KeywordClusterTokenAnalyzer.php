<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final class KeywordClusterTokenAnalyzer
{
    /** @var list<string> */
    private const FUNCTION_STOPWORDS = [
        'va', 'cua', 'cho', 'la', 'tai', 'o', 'mot', 'cac', 'nhung', 'voi', 'den', 'tu',
        'trong', 'theo', 'nhu', 'khi', 'co', 'duoc', 'se', 'da', 'neu', 'thi', 'ma',
    ];

    /** @var list<string> */
    private const INTENT_LEAD = [
        'mua', 'gia', 'dat', 'order', 'buy', 'cach', 'huong', 'dan', 'bao',
    ];

    /**
     * @return array{tokens: list<string>, bigrams: list<string>, significant_tokens: list<string>, significant_phrase: string}
     */
    public function analyze(string $foldedText): array
    {
        $rawTokens = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($foldedText), 'UTF-8')) ?: [],
            static fn (string $t): bool => $t !== '',
        ));

        $tokens = [];
        foreach ($rawTokens as $token) {
            if (mb_strlen($token, 'UTF-8') < 2) {
                continue;
            }
            if (in_array($token, self::FUNCTION_STOPWORDS, true)) {
                continue;
            }
            $tokens[] = $token;
        }

        $bigrams = [];
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $bigrams[] = $tokens[$i].' '.$tokens[$i + 1];
        }

        $significantTokens = $this->stripIntentLead($tokens);
        if ($significantTokens === []) {
            $significantTokens = $tokens;
        }

        return [
            'tokens' => $tokens,
            'bigrams' => $bigrams,
            'significant_tokens' => $significantTokens,
            'significant_phrase' => implode(' ', $significantTokens),
        ];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function stripIntentLead(array $tokens): array
    {
        $working = $tokens;
        while ($working !== [] && in_array($working[0], self::INTENT_LEAD, true)) {
            array_shift($working);
        }

        return array_values($working);
    }
}
