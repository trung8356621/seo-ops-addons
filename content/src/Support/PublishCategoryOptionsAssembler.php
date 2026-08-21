<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalog;
use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalogResult;
use Omnichannel\Addons\Publishing\Support\PublishingTaxonomyHierarchyFlattener;

final class PublishCategoryOptionsAssembler
{
    public function __construct(
        private readonly PublishingTaxonomyCatalog $catalog,
    ) {}

    /**
     * @return array{
     *     category: list<array{id: int, label: string}>,
     *     product_category: list<array{id: int, label: string}>,
     *     status: array<string, array{ok: bool, code: string, message: string, taxonomy: string}>
     * }
     */
    public function forSite(int $siteId): array
    {
        $category = $this->catalog->getTerms($siteId, PublishingTaxonomyCatalog::TAXONOMY_CATEGORY);
        $productCat = $this->catalog->getTerms($siteId, PublishingTaxonomyCatalog::TAXONOMY_PRODUCT_CAT);

        return [
            'category' => $this->optionsFromResult($category),
            'product_category' => $this->optionsFromResult($productCat),
            'status' => [
                'category' => $this->statusFromResult($category),
                'product_category' => $this->statusFromResult($productCat),
            ],
        ];
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function optionsFromResult(PublishingTaxonomyCatalogResult $result): array
    {
        if (! $result->ok) {
            return [];
        }

        return PublishingTaxonomyHierarchyFlattener::flatten($result->items);
    }

    /**
     * @return array{ok: bool, code: string, message: string, taxonomy: string}
     */
    private function statusFromResult(PublishingTaxonomyCatalogResult $result): array
    {
        return [
            'ok' => $result->ok,
            'code' => $result->code,
            'message' => $result->message,
            'taxonomy' => $result->taxonomy,
        ];
    }
}
