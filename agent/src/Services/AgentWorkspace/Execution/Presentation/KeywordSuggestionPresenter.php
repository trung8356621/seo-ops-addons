<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

final class KeywordSuggestionPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function present(array $data): array
    {
        $keywords = [];
        if (isset($data['keywords']) && is_array($data['keywords'])) {
            $keywords = $data['keywords'];
        } elseif (isset($data['suggestions']) && is_array($data['suggestions'])) {
            $keywords = $data['suggestions'];
        }

        if ($keywords === []) {
            return ReadResultPresenter::card('Gợi ý keyword', ['Chưa có gợi ý keyword.']);
        }

        $lines = ['Gợi ý keyword'];
        $i = 1;
        foreach ($keywords as $row) {
            if (is_string($row) && trim($row) !== '') {
                $lines[] = $i.'. '.trim($row);
                $i++;
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $kw = trim((string) ($row['keyword'] ?? $row['label'] ?? $row['name'] ?? ''));
            if ($kw === '') {
                continue;
            }
            $lines[] = $i.'. '.$kw;
            $i++;
        }

        return ReadResultPresenter::card('Gợi ý keyword', $lines);
    }
}
