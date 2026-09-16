<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;

/**
 * Destination-layer gate for suggestion/anchor pipeline.
 * Failures downgrade to unresolved (#) — they never drop a valid anchor.
 *
 * @phpstan-type GateResult array{
 *     destination_resolved: bool,
 *     url: ?string,
 *     href: string,
 *     destination_reject_reason: ?string
 * }
 */
final class InternalLinkDestinationGate
{
    /**
     * @param  array{
     *     text?: string,
     *     target_article_id?: int,
     *     bucket?: 'internal'|'external'|null
     * }  $suggestionSeed
     * @param  array<string, mixed>  $validationContext
     * @param  list<string>  $alreadyLinkedNormalizedUrls
     * @return GateResult
     */
    public static function evaluate(
        string $rawUrl,
        bool $indexDestinationResolved,
        array $suggestionSeed,
        array $validationContext,
        array $alreadyLinkedNormalizedUrls = [],
    ): array {
        $url = trim($rawUrl);
        $destinationResolved = $indexDestinationResolved
            && $url !== ''
            && SeoSuggestionUrlNormalizer::isParsableTarget($url);
        $rejectReason = null;

        if (! $destinationResolved) {
            if ($url === '' || $indexDestinationResolved === false) {
                $rejectReason = 'destination_unresolved';
            } elseif (! SeoSuggestionUrlNormalizer::isParsableTarget($url)) {
                $rejectReason = 'destination_url_invalid';
            }

            return [
                'destination_resolved' => false,
                'url' => null,
                'href' => '#',
                'destination_reject_reason' => $rejectReason,
            ];
        }

        $normalizedHref = SeoSuggestionUrlNormalizer::normalize($url);
        if ($normalizedHref !== '' && in_array($normalizedHref, $alreadyLinkedNormalizedUrls, true)) {
            return [
                'destination_resolved' => false,
                'url' => null,
                'href' => '#',
                'destination_reject_reason' => 'already_linked_destination',
            ];
        }

        $suggestion = array_merge([
            'href' => $url,
            'target_url' => $url,
            'bucket' => 'internal',
        ], $suggestionSeed);

        if (! LinkSuggestionValidator::isValidLinkSuggestion($suggestion, $validationContext)) {
            return [
                'destination_resolved' => false,
                'url' => null,
                'href' => '#',
                'destination_reject_reason' => 'destination_validator_reject',
            ];
        }

        return [
            'destination_resolved' => true,
            'url' => $url,
            'href' => $url,
            'destination_reject_reason' => null,
        ];
    }
}
