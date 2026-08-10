<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

final class ListFocusKeywords extends ListKeywords
{
    public function getKeywordWorkspaceMode(): string
    {
        return 'focus';
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'focus';
    }
}
