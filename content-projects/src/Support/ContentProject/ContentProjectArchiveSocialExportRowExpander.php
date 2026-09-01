<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Appends canonical social-evidence child rows immediately after each article row.
 *
 * @phpstan-type SocialLink array{id: int, url: string, domain: string, recorded_at: string|null}
 * @phpstan-type WriterSheetRow array<string, mixed>
 */
final class ContentProjectArchiveSocialExportRowExpander
{
    /**
     * @param  list<WriterSheetRow>  $rows
     * @param  array<int, list<SocialLink>>  $linksByArticle
     * @return list<WriterSheetRow>
     */
    public function expandWriterSheetRows(array $rows, array $linksByArticle): array
    {
        $expanded = [];

        foreach ($rows as $row) {
            $expanded[] = $row;

            $articleId = (int) ($row['article_id'] ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            foreach ($linksByArticle[$articleId] ?? [] as $link) {
                $child = $this->buildSocialChildRow($link);
                if ($child !== null) {
                    $expanded[] = $child;
                }
            }
        }

        return $expanded;
    }

    /**
     * @param  SocialLink  $link
     * @return WriterSheetRow|null
     */
    private function buildSocialChildRow(array $link): ?array
    {
        $url = trim((string) ($link['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $domain = trim((string) ($link['domain'] ?? ''));
        $label = '↳ '.($domain !== '' ? $domain.' — ' : '').$url;

        return [
            'project' => '',
            'domain' => '',
            'title' => $label,
            'hyperlink_url' => $url,
            'keyword' => '',
            'wordpress_url' => '',
            'post_type' => '',
            'plan' => '',
            'index_status' => '',
            'archived_at' => (string) ($link['recorded_at'] ?? ''),
            'archived_by' => '',
            'article_id' => 0,
            'row_kind' => 'social',
        ];
    }
}
