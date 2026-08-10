<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource\Pages;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

final class ViewAutomationRule extends ViewRecord
{
    use RedirectsSeoAutomationToAdmin;

    protected static string $resource = AutomationRuleResource::class;

    public function mount(int|string $record): void
    {
        if ($this->redirectSeoAutomationToAdmin(AutomationRuleResource::getUrl('view', ['record' => $record]))) {
            return;
        }

        parent::mount($record);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make(__('seo-content-ai::filament.automation.rule'))
                    ->schema([
                        Infolists\Components\TextEntry::make('name'),
                        Infolists\Components\TextEntry::make('code')->copyable(),
                        Infolists\Components\TextEntry::make('event_name')
                            ->label(__('seo-content-ai::filament.automation.event')),
                        Infolists\Components\IconEntry::make('is_enabled')
                            ->label(__('seo-content-ai::filament.automation.is_enabled'))
                            ->boolean(),
                        Infolists\Components\TextEntry::make('priority')
                            ->label(__('seo-content-ai::filament.automation.priority')),
                        Infolists\Components\TextEntry::make('run_mode')
                            ->label(__('seo-content-ai::filament.automation.run_mode')),
                        Infolists\Components\TextEntry::make('version')
                            ->label(__('seo-content-ai::filament.automation.version')),
                        Infolists\Components\TextEntry::make('description')
                            ->columnSpanFull()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('conditions_json')
                            ->label(__('seo-content-ai::filament.automation.conditions'))
                            ->state(fn (AutomationRule $record): string => self::jsonPreview($record->conditions))
                            ->markdown()
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('settings_json')
                            ->label(__('seo-content-ai::filament.automation.settings'))
                            ->state(fn (AutomationRule $record): string => self::jsonPreview($record->settings))
                            ->markdown()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Infolists\Components\Section::make(__('seo-content-ai::filament.automation.actions'))
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('actions')
                            ->schema([
                                Infolists\Components\TextEntry::make('position'),
                                Infolists\Components\TextEntry::make('action_code'),
                                Infolists\Components\IconEntry::make('is_enabled')
                                    ->label(__('seo-content-ai::filament.automation.is_enabled'))
                                    ->boolean(),
                                Infolists\Components\TextEntry::make('delay_seconds'),
                                Infolists\Components\TextEntry::make('input_mapping_json')
                                    ->label('Input mapping')
                                    ->state(fn ($record): string => self::jsonPreview($record->input_mapping ?? null))
                                    ->columnSpanFull(),
                                Infolists\Components\TextEntry::make('settings_json')
                                    ->label(__('seo-content-ai::filament.automation.settings'))
                                    ->state(fn ($record): string => self::jsonPreview($record->settings ?? null))
                                    ->columnSpanFull(),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => AutomationRuleResource::canEdit($this->getRecord())),
            Actions\Action::make('executions')
                ->label(__('seo-content-ai::filament.automation.view_executions'))
                ->icon('heroicon-o-queue-list')
                ->url(fn (): string => AutomationExecutionResource::getUrl('index', [
                    'tableFilters' => [
                        'automation_rule_id' => ['value' => (string) $this->getRecord()->getKey()],
                    ],
                ])),
        ];
    }

    private static function jsonPreview(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '—';
        }

        if (is_string($value)) {
            return '```json'."\n".$value."\n```";
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return '```json'."\n".($encoded !== false ? $encoded : '—')."\n```";
    }
}
