<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;

final class AgentEntityPresentationIndex
{
    /** @param array<string, array{ref: string, label: string, href: string, type: string}> $entities */
    private function __construct(private readonly array $entities) {}

    public static function fromBundle(RetrievalBundle $bundle): self
    {
        /** @var array<string, array<string, array{ref: string, label: string, href: string, type: string}>> $byLabel */
        $byLabel = [];
        foreach ($bundle->sources as $source) {
            if ($source->status === 'ok') {
                self::collectTopics($source->data, $byLabel);
            }
        }

        $entities = [];
        foreach ($byLabel as $label => $matches) {
            if (count($matches) === 1) {
                $entities[$label] = reset($matches);
            }
        }
        uksort($entities, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return new self($entities);
    }

    public function decorate(AgentResponse $response): AgentResponse
    {
        $blocks = array_map(function (array $block): array {
            if (($block['type'] ?? null) === 'markdown' && is_string($block['text'] ?? null)) {
                $block['text'] = $this->decorateMarkdown($block['text']);
            } elseif (($block['type'] ?? null) === 'table' && is_array($block['rows'] ?? null)) {
                $block['rows'] = array_map(fn (array $row): array => array_map(
                    fn (mixed $value): mixed => $this->decorateTableCell($value),
                    $row,
                ), $block['rows']);
            }

            return $block;
        }, $response->blocks);

        return new AgentResponse(
            $this->decorateMarkdown($response->message),
            $blocks,
            $response->actions,
            $response->sources,
        );
    }

    public function decorateMarkdown(string $markdown): string
    {
        if ($this->entities === []) {
            return $markdown;
        }

        $labels = array_map(static fn (string $label): string => implode('\\s+', array_map(
            static fn (string $part): string => preg_quote($part, '~'),
            preg_split('/\s+/u', $label) ?: [],
        )), array_keys($this->entities));
        $entityPattern = '(?<![\p{L}\p{N}_])('.implode('|', $labels).')(?![\p{L}\p{N}_])';
        $protectedPattern = '(```[\s\S]*?```|`[^`\n]*`|!?\[[^\]\n]+\]\([^\s)]+\)|https?://[^\s<>()]+)';
        $result = preg_replace_callback(
            '~'.$protectedPattern.'|'.$entityPattern.'~iu',
            function (array $match): string {
                if (($match[1] ?? '') !== '') {
                    return $match[1];
                }

                $label = (string) ($match[2] ?? '');
                $entity = $this->entities[self::normalizeLabel($label)] ?? null;

                return $entity === null ? $label : '['.$label.']('.$entity['href'].')';
            },
            $markdown,
        );

        return is_string($result) ? $result : $markdown;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, array{ref: string, label: string, href: string, type: string}>>  $byLabel
     */
    private static function collectTopics(array $node, array &$byLabel): void
    {
        $ref = $node['topic_ref'] ?? null;
        $label = $node['name'] ?? null;
        $href = $node['ui_href'] ?? null;
        if (is_string($ref) && preg_match('/^topic:[1-9]\d*$/', $ref) === 1
            && is_string($label) && trim($label) !== ''
            && is_string($href) && self::isHttpUrl($href)) {
            $label = trim($label);
            $normalizedLabel = self::normalizeLabel($label);
            $identity = $ref."\0".$href;
            $byLabel[$normalizedLabel][$identity] = [
                'ref' => $ref,
                'label' => $label,
                'href' => $href,
                'type' => 'topic',
            ];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                self::collectTopics($value, $byLabel);
            }
        }
    }

    private static function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function decorateTableCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $entity = $this->entities[self::normalizeLabel($value)] ?? null;

        return $entity === null ? $value : ['label' => $value, 'href' => $entity['href']];
    }

    private static function normalizeLabel(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
    }
}
