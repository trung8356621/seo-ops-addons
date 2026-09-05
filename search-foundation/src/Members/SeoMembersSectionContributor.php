<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Members;

use App\Core\Members\MembersSectionContributor;
use App\Models\User;
use Filament\Forms;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;

/**
 * SEO contributes monthly capacity into Core Members customize modal / edit form.
 *
 * Availability = capacity service exists and SEO stack is enabled (AddonRegistry).
 * Does NOT depend on current Filament panel URL.
 * Does NOT import SEO peer classes (search-foundation must stay SEO-optional).
 */
final class SeoMembersSectionContributor implements MembersSectionContributor
{
    public function addonSlug(): string
    {
        return 'seo-members';
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
            Forms\Components\Section::make('SEO')
                ->description('Vai trò SEO và hạn mức bài viết hàng tháng.')
                ->schema($this->capacityAndRoleFields(includeSeoRole: true))
                ->columns(2)
                ->collapsible(),
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

        return [
            'seo_capacity_use_default' => $override === null,
            'seo_monthly_capacity_override' => $override ?? $settings->defaultMonthlyCapacity(),
        ];
    }

    public function afterUserSaved(User $user, array $formState): void
    {
        $settings = app(ContentProjectWriterCapacitySettingsService::class);
        if (! empty($formState['seo_capacity_use_default'])) {
            $settings->setUserOverride($user, null);

            return;
        }

        if (array_key_exists('seo_monthly_capacity_override', $formState)) {
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
                    'manager' => 'Manager',
                    'planner' => 'Planner',
                    'content_manager' => 'Content manager',
                ])
                ->native(false)
                ->nullable();
        }

        $defaultCapacity = 30;
        try {
            $defaultCapacity = app(ContentProjectWriterCapacitySettingsService::class)->defaultMonthlyCapacity();
        } catch (\Throwable) {
        }

        $fields[] = Forms\Components\Toggle::make('seo_capacity_use_default')
            ->label('Dùng mặc định ('.$defaultCapacity.')')
            ->dehydrated(false)
            ->live()
            ->default(true);

        $fields[] = Forms\Components\TextInput::make('seo_monthly_capacity_override')
            ->label('Giới hạn bài SEO / tháng')
            ->numeric()
            ->integer()
            ->minValue(ContentProjectWriterCapacitySettingsService::MIN_CAPACITY)
            ->maxValue(ContentProjectWriterCapacitySettingsService::MAX_CAPACITY)
            ->dehydrated(false)
            ->disabled(fn (Forms\Get $get): bool => (bool) $get('seo_capacity_use_default'))
            ->required(fn (Forms\Get $get): bool => ! (bool) $get('seo_capacity_use_default'))
            ->helperText('Để trống / bật mặc định nếu dùng hạn mức hệ thống.');

        return $fields;
    }
}
