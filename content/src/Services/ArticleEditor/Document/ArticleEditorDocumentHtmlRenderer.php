<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor\Document;

use Omnichannel\Addons\Content\Support\ArticleEditorDocumentErrorCode;

/**
 * Server TipTap JSON → HTML renderer (deterministic, no regex structure walk).
 */
final class ArticleEditorDocumentHtmlRenderer
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function renderEnvelope(array $envelope): string
    {
        $parts = [];
        foreach (($envelope['blocks'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'image') {
                $parts[] = $this->renderImageBlock(is_array($block['image'] ?? null) ? $block['image'] : []);
                continue;
            }
            $doc = is_array($block['document'] ?? null) ? $block['document'] : null;
            if ($doc === null) {
                continue;
            }
            $html = $this->renderDoc($doc);
            if (trim($html) !== '') {
                $parts[] = $html;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    public function renderDoc(array $doc): string
    {
        try {
            $content = is_array($doc['content'] ?? null) ? $doc['content'] : [];

            return $this->renderNodes($content);
        } catch (\Throwable $exception) {
            throw new ArticleEditorDocumentException(
                ArticleEditorDocumentErrorCode::RENDER_FAILED,
                'Failed to render editor document HTML: '.$exception->getMessage(),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function renderNodes(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $html .= $this->renderNode($node);
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderNode(array $node): string
    {
        $type = (string) ($node['type'] ?? '');
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $children = is_array($node['content'] ?? null) ? $this->renderNodes($node['content']) : '';

        return match ($type) {
            'text' => $this->renderText($node),
            'hardBreak' => '<br>',
            'horizontalRule' => '<hr>',
            'paragraph' => $this->wrapBlock('p', $children, $attrs),
            'heading' => $this->wrapHeading($children, $attrs),
            'blockquote' => '<blockquote>'.$children.'</blockquote>',
            'bulletList' => '<ul>'.$children.'</ul>',
            'orderedList' => '<ol>'.$children.'</ol>',
            'listItem' => '<li>'.$children.'</li>',
            'codeBlock' => '<pre><code>'.$children.'</code></pre>',
            'table' => '<table>'.$children.'</table>',
            'tableRow' => '<tr>'.$children.'</tr>',
            'tableHeader' => '<th>'.$children.'</th>',
            'tableCell' => '<td>'.$children.'</td>',
            'image', 'articleImage' => $this->renderImageNode($attrs),
            default => $children,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderText(array $node): string
    {
        $text = htmlspecialchars((string) ($node['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $marks = is_array($node['marks'] ?? null) ? $node['marks'] : [];

        foreach (array_reverse($marks) as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            $markType = (string) ($mark['type'] ?? '');
            $markAttrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
            $text = match ($markType) {
                'bold' => '<strong>'.$text.'</strong>',
                'italic' => '<em>'.$text.'</em>',
                'underline' => '<u>'.$text.'</u>',
                'strike' => '<s>'.$text.'</s>',
                'code' => '<code>'.$text.'</code>',
                'highlight' => '<mark>'.$text.'</mark>',
                'subscript' => '<sub>'.$text.'</sub>',
                'superscript' => '<sup>'.$text.'</sup>',
                'link' => $this->wrapLink($text, $markAttrs),
                'textStyle' => $this->wrapTextStyle($text, $markAttrs),
                default => $text,
            };
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function wrapLink(string $inner, array $attrs): string
    {
        $href = trim((string) ($attrs['href'] ?? ''));
        if ($href === '' || preg_match('/^\s*javascript:/i', $href) === 1) {
            return $inner;
        }
        $safeHref = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $target = htmlspecialchars((string) ($attrs['target'] ?? '_blank'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rel = htmlspecialchars((string) ($attrs['rel'] ?? 'noopener noreferrer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $class = htmlspecialchars((string) ($attrs['class'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $classAttr = $class !== '' ? ' class="'.$class.'"' : '';

        return '<a href="'.$safeHref.'" target="'.$target.'" rel="'.$rel.'"'.$classAttr.'>'.$inner.'</a>';
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function wrapTextStyle(string $inner, array $attrs): string
    {
        $color = trim((string) ($attrs['color'] ?? ''));
        if ($color === '' || ! preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            return $inner;
        }

        return '<span style="color: '.htmlspecialchars($color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$inner.'</span>';
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function wrapHeading(string $children, array $attrs): string
    {
        $level = max(1, min(6, (int) ($attrs['level'] ?? 2)));

        return $this->wrapBlock('h'.$level, $children, $attrs);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function wrapBlock(string $tag, string $children, array $attrs): string
    {
        $class = trim((string) ($attrs['class'] ?? $attrs['className'] ?? ''));
        $align = trim((string) ($attrs['textAlign'] ?? ''));
        $attrParts = [];
        if ($class !== '') {
            $attrParts[] = 'class="'.htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }
        if ($align !== '' && in_array($align, ['left', 'center', 'right', 'justify'], true)) {
            $attrParts[] = 'style="text-align: '.$align.'"';
        }
        // Preserve FAQ / CTA markers
        foreach (['data-omi-faq'] as $dataKey) {
            if (isset($attrs[$dataKey]) && (string) $attrs[$dataKey] !== '') {
                $attrParts[] = $dataKey.'="'.htmlspecialchars((string) $attrs[$dataKey], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            }
        }
        $open = $attrParts === [] ? "<{$tag}>" : '<'.$tag.' '.implode(' ', $attrParts).'>';

        return $open.$children."</{$tag}>";
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function renderImageBlock(array $image): string
    {
        return $this->renderImageNode($image);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function renderImageNode(array $attrs): string
    {
        $src = trim((string) ($attrs['src'] ?? $attrs['url'] ?? ''));
        if ($src === '') {
            return '';
        }
        $alt = htmlspecialchars(trim((string) ($attrs['alt'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars(trim((string) ($attrs['title'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = trim((string) ($attrs['caption'] ?? ''));
        $safeSrc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleAttr = $title !== '' ? ' title="'.$title.'"' : '';
        $alignClass = $this->imageAlignClass(trim((string) ($attrs['align'] ?? 'none')));
        $figureClass = trim('wp-block-image '.$alignClass);
        $img = '<img src="'.$safeSrc.'" alt="'.$alt.'"'.$titleAttr.' />';
        if ($caption !== '') {
            $safeCaption = htmlspecialchars($caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<figure class="'.$figureClass.'">'.$img.'<figcaption>'.$safeCaption.'</figcaption></figure>';
        }

        return '<figure class="'.$figureClass.'">'.$img.'</figure>';
    }

    private function imageAlignClass(string $align): string
    {
        return match ($align) {
            'left' => 'alignleft',
            'center' => 'aligncenter',
            'right' => 'alignright',
            'full' => 'alignfull',
            default => '',
        };
    }
}
