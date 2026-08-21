<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support;

final class PublishingTaxonomySelectionFilter
{
    /**
     * @param  list<int|string>  $selectedIds
     * @param  list<int>  $catalogIds
     * @return list<int>
     */
    public static function filter(array $selectedIds, array $catalogIds, bool $catalogOk): array
    {
        $normalized = [];
        foreach ($selectedIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $normalized[$intId] = $intId;
            }
        }

        $normalized = array_values($normalized);
        if ($normalized === [] || ! $catalogOk) {
            return $normalized;
        }

        $allowed = [];
        foreach ($catalogIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $allowed[$intId] = true;
            }
        }

        $kept = [];
        foreach ($normalized as $id) {
            if (isset($allowed[$id])) {
                $kept[] = $id;
            }
        }

        return $kept;
    }
}
