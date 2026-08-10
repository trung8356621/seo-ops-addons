<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\McpCapabilityMarkdownPresenter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Developer MCP Reference — Markdown docs rendered as sanitized HTML.
 * Domain in URL is navigation shell only. Not an admin capability browser.
 */
final class ViewDomainMcp extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.view-domain-mcp';

    /** @var array<string, mixed> */
    public array $mcpCapabilityDoc = [];

    /** Sanitized HTML from generated Markdown (presentation only). */
    public string $mcpHtml = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);
        abort_unless(SeoAccessControl::canAccessManagerFeatures(), 403);

        $this->loadMcpCapabilityDoc();
    }

    public function loadMcpCapabilityDoc(): void
    {
        try {
            $this->mcpCapabilityDoc = app(McpCapabilityMarkdownPresenter::class)->present(
                includeInternal: true,
                filter: McpCapabilityMarkdownPresenter::FILTER_ALL,
            );
        } catch (\Throwable) {
            $this->mcpCapabilityDoc = [
                'title' => 'Developer MCP Reference',
                'filter' => McpCapabilityMarkdownPresenter::FILTER_ALL,
                'filters' => [],
                'items' => [],
                'internal_items' => [],
                'markdown' => '',
                'include_internal' => true,
                'count' => 0,
            ];
        }

        $markdown = (string) ($this->mcpCapabilityDoc['markdown'] ?? '');
        $this->mcpHtml = $markdown !== ''
            ? app(SimpleMarkdownHtmlConverter::class)->toHtml($markdown)
            : '';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Developer MCP Reference';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Developer MCP Reference';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Global MCP system-action documentation. Domain URL is navigation only.';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back')
                ->url(fn (): string => DomainResource::getUrl('general', ['record' => $this->getRecord()])),
        ];
    }
}
