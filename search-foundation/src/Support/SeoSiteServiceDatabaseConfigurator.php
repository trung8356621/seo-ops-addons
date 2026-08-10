<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Filament\Resources\SeoDatabaseConnectionResource;
use App\Models\SiteService;
use App\Services\SiteServiceBindingService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

final class SeoSiteServiceDatabaseConfigurator
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mergeFormSettings(array $data): array
    {
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $dbConfigType = (string) ($data['seo_db_config_type'] ?? $settings['db_config_type'] ?? 'auto');

        if (! in_array($dbConfigType, ['auto', 'manual'], true)) {
            $dbConfigType = 'auto';
        }

        $settings['db_config_type'] = $dbConfigType;
        $data['settings'] = $settings;
        unset($data['seo_db_config_type']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateFormSettings(array $data): array
    {
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['seo_db_config_type'] = (string) ($settings['db_config_type'] ?? 'auto');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateBeforeSave(array $data, ?SiteService $existing): array
    {
        $serviceId = (int) ($data['service_id'] ?? 0);
        $db = app(SeoDatabaseConnectionService::class);

        if (! $db->isSeoContentAiService($serviceId)) {
            return $data;
        }

        $defaults = (new \App\Addons\SeoContentAi\Settings)->getDefaults();
        $settings = array_merge(
            $defaults,
            is_array($data['settings'] ?? null) ? $data['settings'] : [],
        );

        unset(
            $settings['db_host'],
            $settings['db_port'],
            $settings['db_name'],
            $settings['db_username'],
            $settings['db_password'],
        );

        $settings['db_config_type'] = in_array(
            (string) ($settings['db_config_type'] ?? 'auto'),
            ['auto', 'manual'],
            true,
        ) ? (string) $settings['db_config_type'] : 'auto';

        $data['settings'] = $settings;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assertConnectionFromFormData(array $data, ?SiteService $existing): void
    {
        // Site service chỉ ghi nhận chế độ DB; không chặn tạo/sửa khi chưa có SEO Database Connection.
    }

    public static function runMigrations(SiteService $record): void
    {
        $db = app(SeoDatabaseConnectionService::class);

        if (! $db->isSeoContentAiService((int) $record->service_id)) {
            return;
        }

        $settings = is_array($record->settings) ? $record->settings : [];
        $type = (string) ($settings['db_config_type'] ?? 'auto');

        if ($type === 'manual') {
            $ownerId = app(SiteServiceBindingService::class)->resolveOwnerId($record);
            $connection = $db->resolveActiveConnectionForOwner($ownerId);

            if ($connection === null) {
                Notification::make()
                    ->title(__('site-service.seo_db_activated_title'))
                    ->body(__('site-service.seo_db_manual_create_connection_later'))
                    ->warning()
                    ->send();

                return;
            }
        }

        try {
            $connection = $db->syncConnectionFromSiteService($record);
            $result = $db->runMigrationsForConnection($connection);
        } catch (Throwable $exception) {
            Log::error('SEO site service database setup failed after save', [
                'site_service_id' => $record->getKey(),
                'message' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title(__('site-service.seo_db_config_error_title'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if (! ($result['executed'] ?? false)) {
            $reconciled = (int) ($result['reconciled'] ?? 0);
            $body = $reconciled > 0
                ? __('site-service.seo_db_connected_reconciled', ['count' => $reconciled])
                : __('site-service.seo_db_connected_no_migrations');

            Notification::make()
                ->title(__('site-service.seo_db_activated_title'))
                ->body($body)
                ->success()
                ->send();

            return;
        }

        $reconciled = (int) ($result['reconciled'] ?? 0);
        $body = __('site-service.seo_db_migrations_applied', ['count' => (int) ($result['pending'] ?? 0)]);
        if ($reconciled > 0) {
            $body .= __('site-service.seo_db_migrations_reconciled_suffix', ['count' => $reconciled]);
        }

        Notification::make()
            ->title(__('site-service.seo_db_ready_title'))
            ->body($body)
            ->success()
            ->send();
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        $connectionsUrl = SeoDatabaseConnectionResource::getUrl('index');

        return [
            Forms\Components\Section::make(__('site-service.seo_db_section_title'))
                ->description(__('site-service.seo_db_section_description'))
                ->visible(fn (Get $get): bool => self::isSeoServiceSelected($get('service_id')))
                ->schema([
                    Forms\Components\Select::make('seo_db_config_type')
                        ->label(__('site-service.seo_db_config_mode'))
                        ->options([
                            'auto' => __('site-service.seo_db_mode_auto'),
                            'manual' => __('site-service.seo_db_mode_manual'),
                        ])
                        ->default('auto')
                        ->required(fn (Get $get): bool => self::isSeoServiceSelected($get('service_id')))
                        ->dehydrated(fn (Get $get): bool => self::isSeoServiceSelected($get('service_id')))
                        ->live()
                        ->native(false),

                    Forms\Components\Placeholder::make('db_auto_hint')
                        ->label(__('site-service.seo_db_auto_note'))
                        ->visible(fn (Get $get): bool => ($get('seo_db_config_type') ?? 'auto') === 'auto')
                        ->content(function (Get $get): string {
                            $siteId = (int) ($get('site_id') ?? 0);
                            $perSite = (bool) config('seo-content-ai.auto_per_site_database', false);
                            $legacy = (string) config('seo-content-ai.legacy_shared_database', 'omi_seo_ai');

                            if ($perSite && $siteId > 0) {
                                $prefix = (string) config('seo-content-ai.auto_database_prefix', 'omi_seo_ai');

                                return __('site-service.seo_db_auto_per_site', [
                                    'database' => "{$prefix}_{$siteId}",
                                ]);
                            }

                            return __('site-service.seo_db_auto_shared', [
                                'database' => $legacy,
                            ]);
                        }),

                    Forms\Components\Placeholder::make('db_manual_hint')
                        ->label(__('site-service.seo_db_manual_note'))
                        ->visible(fn (Get $get): bool => ($get('seo_db_config_type') ?? '') === 'manual')
                        ->content(fn (): HtmlString => new HtmlString(
                            __('site-service.seo_db_manual_hint', [
                                'link' => '<a href="'.e($connectionsUrl).'" class="text-primary-600 underline font-medium" target="_blank" rel="noopener">'
                                    .e(__('SEO Database Connections')).'</a>',
                            ])
                        )),
                ])
                ->columns(1),
        ];
    }

    private static function isSeoServiceSelected(mixed $serviceId): bool
    {
        if (! is_numeric($serviceId)) {
            return false;
        }

        return app(SeoDatabaseConnectionService::class)->isSeoContentAiService((int) $serviceId);
    }
}
