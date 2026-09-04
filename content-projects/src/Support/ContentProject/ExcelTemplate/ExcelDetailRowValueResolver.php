<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelHyperlinkHelper;

/**
 * Resolves export cell values by stable system column code.
 */
final class ExcelDetailRowValueResolver
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function resolve(array $row, string $code, ?string $writerName = null): mixed
    {
        return match ($code) {
            ExcelDetailColumnRegistry::CODE_WRITER_NAME => (string) ($writerName ?? ''),
            ExcelDetailColumnRegistry::CODE_PROJECT_NAME => (string) ($row['project'] ?? ''),
            ExcelDetailColumnRegistry::CODE_DOMAIN => (string) ($row['domain'] ?? ''),
            ExcelDetailColumnRegistry::CODE_ARTICLE_TITLE => $this->titleCell($row),
            ExcelDetailColumnRegistry::CODE_KEYWORD => (string) ($row['keyword'] ?? ''),
            ExcelDetailColumnRegistry::CODE_ARTICLE_TYPE => (string) ($row['post_type'] ?? ''),
            ExcelDetailColumnRegistry::CODE_PLAN_TYPE => (string) ($row['plan'] ?? ''),
            ExcelDetailColumnRegistry::CODE_INDEX_STATUS => (string) ($row['index_status'] ?? ''),
            ExcelDetailColumnRegistry::CODE_REVIEWER_NAME => (string) ($row['reviewed_at'] ?? ''),
            ExcelDetailColumnRegistry::CODE_ARCHIVED_BY => (string) ($row['archived_by'] ?? ''),
            default => null,
        };
    }

    /**
     * Default-order values for writer sheets (no writer_name).
     *
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public function defaultWriterSheetValues(array $row): array
    {
        $registry = new ExcelDetailColumnRegistry();
        $values = [];
        foreach ($registry->writerSheetCodes() as $code) {
            $values[] = $this->resolve($row, $code);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function titleCell(array $row): mixed
    {
        $title = (string) ($row['title'] ?? '');
        $hyperlinkUrl = trim((string) ($row['hyperlink_url'] ?? ''));
        $wordpressUrl = trim((string) ($row['wordpress_url'] ?? ''));
        $linkUrl = $hyperlinkUrl !== '' ? $hyperlinkUrl : $wordpressUrl;

        return $linkUrl !== '' && $title !== ''
            ? ExcelHyperlinkHelper::formula($linkUrl, $title)
            : $title;
    }
}
