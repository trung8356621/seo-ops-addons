<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

final class IndustryContextAuxiliaryPromptGuidance
{
    public const HEADING = 'Shared Auxiliary Topic Semantic Guidance:';

    public static function guidance(): string
    {
        return implode("\n", [
            self::HEADING,
            '',
            '`topic` is NOT:',
            '- article title',
            '- headline',
            '- content idea',
            '- proposed blog post title',
            '- publish-ready angle',
            '',
            '`topic` IS:',
            '- a stable canonical label for a reusable subject/concept area',
            '- concise noun phrase',
            '- reusable across many future articles and queries',
            '',
            'Normally keep `topic` concise, preferably about 2–8 words.',
            '',
            'Examples:',
            '',
            'Knowledge & Search:',
            '- Denier và mật độ sợi',
            '- Chống thấm PU/PVC',
            '- Mút EVA và chống sốc',
            '- Khóa kéo và phụ liệu chịu lực',
            '',
            'Lifestyle & Usage:',
            '- EDC hằng ngày',
            '- Balo trong phong cách streetwear',
            '- Balo đi làm',
            '- Balo du lịch cuối tuần',
            '- Phối balo với trang phục công sở',
            '',
            'Avoid publish-ready framing such as:',
            '- Cách...',
            '- Hướng dẫn...',
            '- Chiến lược...',
            '- Top...',
            '- Tại sao...',
            '- Nên...',
            '- Xây dựng...',
            '- numbered/listicle framing',
            '',
            'Do NOT ban those words mechanically if they are legitimately part of a concept.',
            'The rule is semantic: do not produce article-title style topics.',
            '',
            '`query_examples` is where natural search queries belong.',
            '`keywords` is where keyword phrases belong.',
        ]);
    }

    public static function appliesTo(string $typeOrHook): bool
    {
        return in_array($typeOrHook, [
            'discovery',
            'industry.discovery.generate',
            'breakout',
            'industry.breakout.generate',
        ], true);
    }
}
