<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Members;

use App\Core\Members\MembersSectionContributor;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;

/**
 * SEO contributes role + monthly capacity into Core Members tabs / customize modal.
 *
 * Persistence invariant for monthly capacity:
 *   null  = inherit system default (SSOT: ContentProjectWriterCapacitySettingsService)
 *   int N = explicit per-user override
 *
 * UI may display the effective default while override remains null.
 */
final class SeoMembersSectionContributor implements MembersSectionContributor
{
    public function addonSlug(): string
    {
        return 'seo-members';
    }

    public function tabLabel(): string
    {
        return 'SEO';
    }

    public function tabIcon(): ?string
    {
        return 'heroicon-o-magnifying-glass';
    }

    public function sort(): int
    {
        return 10;
    }

    public function isAvailable(): bool
    {
        if (! class_exists(ContentProjectWriterCapacitySettingsService::class)) {
            return false;
        }

        $skip = [];
        try {
            $skip = array_map('strval', (array) config('addons.skip_slugs', []));
        } catch (\Throwable) {
            $skip = [];
        }

        foreach (['seo', 'seo-content-ai'] as $slug) {
            if (in_array($slug, $skip, true)) {
                return false;
            }
        }

        try {
            if (! app()->bound(\App\Core\Addon\AddonRegistry::class)) {
                return true;
            }

            return \App\Core\Addon\AddonEnablement::seoStackEnabled();
        } catch (\Throwable) {
            return true;
        }
    }

    public function formSections(): array
    {
        return [
            Forms\Components\Section::make()
                ->description('Vai trò SEO và hạn mức bài viết hàng tháng.')
                ->schema($this->capacityAndRoleFields(includeSeoRole: true))
                ->columns(1),
        ];
    }

    public function customizeModalSchema(): array
    {
        return [
            Forms\Components\Section::make('SEO')
                ->schema($this->capacityAndRoleFields(includeSeoRole: false))
                ->columns(1),
        ];
    }

    public function fillCustomizeModal(User $user): array
    {
        $settings = app(ContentProjectWriterCapacitySettingsService::class);
        $override = $settings->overrideForUserId((int) $user->getKey());
        $default = $settings->defaultMonthlyCapacity();

        return [
            'seo_capacity_use_default' => $override === null,
            // Effective display value — may equal default while override is null.
            'seo_monthly_capacity_override' => $override ?? $default,
        ];
    }

    public function afterUserSaved(User $user, array $formState): void
    {
        if (array_key_exists('seo_role', $formState)) {
            $raw = $formState['seo_role'];
            $legacy = is_string($raw) ? trim($raw) : '';
            try {
                app(\App\Core\Permissions\LegacySeoRoleBridge::class)
                    ->assign($user, $legacy !== '' ? $legacy : null);
            } catch (\Throwable) {
                if ((string) ($user->seo_role ?? '') !== $legacy) {
                    $user->forceFill(['seo_role' => $legacy !== '' ? $legacy : null])->saveQuietly();
                }
            }
        }

        if (! array_key_exists('seo_capacity_use_default', $formState)
            && ! array_key_exists('seo_monthly_capacity_override', $formState)
        ) {
            return;
        }

        $settings = app(ContentProjectWriterCapacitySettingsService::class);
        if (! empty($formState['seo_capacity_use_default'])) {
            // Displayed default must NOT become an explicit override.
            $settings->setUserOverride($user, null);

            return;
        }

        if (array_key_exists('seo_monthly_capacity_override', $formState)
            && $formState['seo_monthly_capacity_override'] !== null
            && $formState['seo_monthly_capacity_override'] !== ''
        ) {
            $settings->setUserOverride($user, (int) $formState['seo_monthly_capacity_override']);
        }
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private function capacityAndRoleFields(bool $includeSeoRole): array
    {
        $fields = [];

        if ($includeSeoRole) {
            $fields[] = Forms\Components\Select::make('seo_role')
                ->label('SEO role')
                ->options([
                    'manager' => 'Manager (seo.manager)',
                    'planner' => 'Planner (seo.planner)',
                    'content_manager' => 'Content manager (seo.content_manager)',
                ])
                ->helperText('Addon role (Spatie). Không phải Manager tổ chức Core.')
                ->native(false)
                ->nullable();
        }

        $defaultCapacity = ContentProjectWriterCapacitySettingsService::DEFAULT_CAPACITY;
        try {
            $defaultCapacity = app(ContentProjectWriterCapacitySettingsService::class)->defaultMonthlyCapacity();
        } catch (\Throwable) {
        }

        $fields[] = Forms\Components\TextInput::make('seo_monthly_capacity_override')
            ->label('Giới hạn bài SEO / tháng')
            ->numeric()
            ->integer()
            ->minValue(ContentProjectWriterCapacitySettingsService::MIN_CAPACITY)
            ->maxValue(ContentProjectWriterCapacitySettingsService::MAX_CAPACITY)
            ->default($defaultCapacity)
            // Only dehydrate when custom mode — never persist displayed default as override.
            ->dehydrated(fn (Get $get): bool => ! (bool) $get('seo_capacity_use_default'))
            ->disabled(fn (Get $get): bool => (bool) $get('seo_capacity_use_default'))
            ->required(fn (Get $get): bool => ! (bool) $get('seo_capacity_use_default'))
            ->helperText(fn (Get $get): ?string => (bool) $get('seo_capacity_use_default')
                ? 'Đang dùng hạn mức hệ thống — lưu sẽ không ghi đè giá trị hiển thị.'
                : 'Hạn mức riêng cho thành viên này.');

        $fields[] = Forms\Components\Checkbox::make('seo_capacity_use_default')
            ->label('Dùng hạn mức mặc định ('.$defaultCapacity.' bài/tháng)')
            ->dehydrated(true)
            ->live()
            ->default(true)
            ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($defaultCapacity): void {
                if ($state) {
                    $set('seo_monthly_capacity_override', $defaultCapacity);

                    return;
                }

                $current = $get('seo_monthly_capacity_override');
                if ($current === null || $current === '') {
                    $set('seo_monthly_capacity_override', $defaultCapacity);
                }
            });

        return $fields;
    }
}
