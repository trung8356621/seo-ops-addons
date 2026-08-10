<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

/**
 * Build final prompt context blocks for Site MCP consumers.
 *
 * Never injects URLs / product lists / discovery statistics into AI prompts.
 * Never leaves unresolved [phone]/[email]/[facebook] placeholders.
 */
final class SiteMcpContextAssembler
{
    /** @var list<string> */
    public const PLACEHOLDER_PATTERN_TYPES = [
        'phone',
        'phone_1',
        'phone_2',
        'phone_3',
        'email',
        'email_1',
        'email_2',
        'email_3',
        'facebook',
        'instagram',
        'youtube',
        'linkedin',
        'tiktok',
        'zalo',
        'hotline',
        'address',
        'website',
        'working_hours',
        'x',
        'twitter',
        'pinterest',
        'threads',
    ];

    /**
     * @param  array<string, mixed>  $draft
     * @param  array{
     *     company_short_identity?: string,
     *     short_description?: string,
     *     tone?: string,
     *     cta_instructions?: string,
     *     resolved_contacts?: array<string, string>,
     * }  $officialOverlay
     * @return array{text: string, unresolved: list<string>, has_unresolved: bool}
     */
    public function articleContext(array $draft, array $officialOverlay = []): array
    {
        $site = is_array($draft['site'] ?? null) ? $draft['site'] : [];
        $content = is_array($draft['content_context'] ?? null) ? $draft['content_context'] : [];

        $company = trim((string) ($officialOverlay['company_short_identity']
            ?? $site['company_short_identity']
            ?? ''));
        $short = trim((string) ($officialOverlay['short_description']
            ?? $site['short_description']
            ?? $content['business_summary']
            ?? ''));
        $tone = trim((string) ($officialOverlay['tone'] ?? $content['tone'] ?? ''));
        $cta = trim((string) ($officialOverlay['cta_instructions']
            ?? $content['cta_instructions']
            ?? ''));
        $contacts = is_array($officialOverlay['resolved_contacts'] ?? null)
            ? $officialOverlay['resolved_contacts']
            : $this->sampleResolvedContacts($draft);

        $lines = [
            '=========================',
            'ARTICLE CONTEXT PREVIEW',
            '=========================',
            '',
            'Company',
            $company !== '' ? $company : '(empty)',
            '',
            'Business Context',
            $short !== '' ? $short : '(empty)',
            '',
            'Tone',
            $tone !== '' ? $tone : '(empty)',
            '',
            'CTA',
            $cta !== '' ? $cta : '(empty)',
            '',
            'Resolved Contacts',
        ];

        if ($contacts === []) {
            $lines[] = '(none resolved)';
        } else {
            foreach ($contacts as $type => $value) {
                $lines[] = $type.': '.$value;
            }
        }

        $lines[] = '';
        $lines[] = '=========================';
        $lines[] = '';
        $lines[] = 'Resolved contacts shown here are preview samples.';
        $lines[] = 'Runtime may randomly choose another value from the same domain contact list.';

        $text = implode("\n", $lines);
        $unresolved = $this->findUnresolvedPlaceholders($text);

        return [
            'text' => $text,
            'unresolved' => $unresolved,
            'has_unresolved' => $unresolved !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<string>|null  $selectedTopics  null = all main topics
     * @return array{text: string, unresolved: list<string>, has_unresolved: bool}
     */
    public function keywordContext(array $draft, ?array $selectedTopics = null): array
    {
        $site = is_array($draft['site'] ?? null) ? $draft['site'] : [];
        $keyword = is_array($draft['keyword_context'] ?? null) ? $draft['keyword_context'] : [];
        $mainTopics = $this->stringList($keyword['main_topics'] ?? []);

        if ($selectedTopics === null) {
            $selected = $mainTopics;
        } else {
            $allowed = array_fill_keys(array_map(
                static fn (string $t): string => mb_strtolower($t),
                $mainTopics,
            ), true);
            $selected = [];
            foreach ($selectedTopics as $topic) {
                $topic = trim((string) $topic);
                if ($topic === '') {
                    continue;
                }
                if (isset($allowed[mb_strtolower($topic)])) {
                    $selected[] = $topic;
                }
            }
            $selected = array_values(array_unique($selected));
        }

        $company = trim((string) ($site['company_short_identity'] ?? ''));
        $short = $this->compactDescription(trim((string) ($site['short_description'] ?? '')));
        $websiteType = trim((string) ($site['website_type'] ?? ''));

        $lines = [
            '=========================',
            'KEYWORD CONTEXT PREVIEW',
            '=========================',
            '',
            'Website Type',
            $websiteType !== '' ? $websiteType : '(empty)',
            '',
            'Company',
            $company !== '' ? $company : '(empty)',
            '',
            'Short Description',
            $short !== '' ? $short : '(empty)',
            '',
            'Main Topics',
        ];

        if ($selected === []) {
            $lines[] = '(none selected)';
        } else {
            foreach ($selected as $topic) {
                $lines[] = '- '.$topic;
            }
        }

        $lines[] = '';
        $lines[] = '=========================';

        $text = implode("\n", $lines);
        $unresolved = $this->findUnresolvedPlaceholders($text);

        return [
            'text' => $text,
            'unresolved' => $unresolved,
            'has_unresolved' => $unresolved !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{text: string, unresolved: list<string>, has_unresolved: bool}
     */
    public function outlineContext(array $draft): array
    {
        $site = is_array($draft['site'] ?? null) ? $draft['site'] : [];
        $company = trim((string) ($site['company_short_identity'] ?? ''));
        $websiteType = trim((string) ($site['website_type'] ?? ''));

        $lines = [
            '=========================',
            'OUTLINE CONTEXT',
            '=========================',
            '',
            'Company Short Identity',
            $company !== '' ? $company : '(empty)',
            '',
            'Website Type',
            $websiteType !== '' ? $websiteType : '(empty)',
            '',
            '=========================',
        ];

        $text = implode("\n", $lines);
        $unresolved = $this->findUnresolvedPlaceholders($text);

        return [
            'text' => $text,
            'unresolved' => $unresolved,
            'has_unresolved' => $unresolved !== [],
        ];
    }

    /**
     * @return list<string>
     */
    public function findUnresolvedPlaceholders(string $text): array
    {
        $found = [];
        foreach (self::PLACEHOLDER_PATTERN_TYPES as $type) {
            if (preg_match('/\['.preg_quote($type, '/').'\]/iu', $text) === 1) {
                $found[] = '['.$type.']';
            }
        }

        return array_values(array_unique($found));
    }

    public function assertNoUnresolvedPlaceholders(string $text): void
    {
        $found = $this->findUnresolvedPlaceholders($text);
        if ($found !== []) {
            throw new \RuntimeException(
                'Unresolved CTA placeholders must not reach AI: '.implode(', ', $found)
            );
        }
    }

    public function compactDescription(string $text, int $maxChars = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars - 1)).'…';
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, string>
     */
    public function sampleResolvedContacts(array $draft): array
    {
        $contact = is_array($draft['contact'] ?? null) ? $draft['contact'] : [];
        $out = [];

        $phones = $this->contactValues($contact['phones'] ?? []);
        if ($phones !== []) {
            $out['phone'] = $phones[array_rand($phones)];
        }

        $emails = $this->contactValues($contact['emails'] ?? $contact['email'] ?? []);
        if ($emails !== []) {
            $out['email'] = $emails[array_rand($emails)];
        }

        $socials = is_array($contact['socials'] ?? null) ? $contact['socials'] : [];
        foreach ($socials as $row) {
            if (! is_array($row)) {
                continue;
            }
            $network = mb_strtolower(trim((string) ($row['network'] ?? '')));
            $value = trim((string) ($row['url'] ?? $row['value'] ?? ''));
            if ($network === '' || $value === '' || isset($out[$network])) {
                continue;
            }
            $out[$network] = $value;
        }

        return $out;
    }

    /**
     * @param  mixed  $rows
     * @return list<string>
     */
    private function contactValues(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_string($row) && trim($row) !== '') {
                $out[] = trim($row);
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $value = trim((string) ($row['value'] ?? ''));
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $text = trim((string) ($item['keyword'] ?? $item['value'] ?? ''));
            } else {
                $text = trim((string) $item);
            }
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return array_values(array_unique($out));
    }
}
