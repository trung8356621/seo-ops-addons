<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

final class AuditListPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function present(array $data): array
    {
        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            $items = $data['items'];
        } elseif (isset($data['articles']) && is_array($data['articles'])) {
            $items = $data['articles'];
        }

        $total = (int) ($data['total'] ?? count($items));
        $postType = trim((string) ($data['post_type'] ?? ''));

        if ($items === []) {
            $empty = ['Chưa có bài cần xử lý.'];
            if ($postType !== '') {
                $empty[] = 'post_type='.$postType;
            }

            return ReadResultPresenter::card('SEO Audit', $empty);
        }

        $lines = ['Danh sách bài cần xử lý ('.$total.')'];
        if ($postType !== '') {
            $lines[] = 'post_type='.$postType;
        }
        $lines[] = '';

        $i = 1;
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $row['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $score = $row['score'] ?? null;
            $scoreText = is_numeric($score) ? ' score='.(string) $score : '';
            $reasons = [];
            if (isset($row['reason_labels']) && is_array($row['reason_labels'])) {
                $reasons = array_values(array_filter(array_map('strval', $row['reason_labels'])));
            }
            $reasonText = $reasons !== [] ? ' — '.implode('; ', array_slice($reasons, 0, 3)) : '';
            $lines[] = $i.'. '.$title.$scoreText.$reasonText;
            $i++;
        }

        return ReadResultPresenter::card('SEO Audit', $lines);
    }
}
