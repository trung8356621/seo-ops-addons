<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Members;

use App\Core\Members\MembersSectionContributor;
use App\Models\User;
use Filament\Forms;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingRoleAssignment;

/**
 * Seeding contributes exclusive Spatie seeding.* role into Core Members tabs.
 * Form-only field — never a users.* column.
 */
final class SeedingMembersSectionContributor implements MembersSectionContributor
{
    public const FORM_KEY = 'seeding_role';

    public function addonSlug(): string
    {
        return 'seeding-members';
    }

    public function tabLabel(): string
    {
        return 'Seeding';
    }

    public function tabIcon(): ?string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public function sort(): int
    {
        return 20;
    }

    public function isAvailable(): bool
    {
        return \App\Core\Addon\AddonEnablement::anyEnabled(['seeding'], ['seeding']);
    }

    public function formOnlyStateKeys(): array
    {
        return [self::FORM_KEY];
    }

    public function formSections(): array
    {
        return [
            Forms\Components\Section::make()
                ->description('Vai trò chỉ áp dụng cho addon Seeding.')
                ->schema([$this->roleSelect()])
                ->columns(1),
        ];
    }

    public function customizeModalSchema(): array
    {
        return [
            Forms\Components\Section::make('Seeding')
                ->schema([$this->roleSelect()])
                ->columns(1),
        ];
    }

    public function fillCustomizeModal(User $user): array
    {
        $assignment = app(SeedingRoleAssignment::class);

        return [
            self::FORM_KEY => $assignment->resolveForUser($user),
        ];
    }

    public function afterUserSaved(User $user, array $formState): void
    {
        if (! array_key_exists(self::FORM_KEY, $formState)) {
            return;
        }

        $raw = $formState[self::FORM_KEY] ?? null;
        app(SeedingRoleAssignment::class)->assignExclusive(
            $user,
            is_string($raw) || is_numeric($raw) ? (string) $raw : null,
        );
    }

    private function roleSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make(self::FORM_KEY)
            ->label('Vai trò Seeding')
            ->options([
                SeedingAccess::ROLE_MANAGER => 'Manager',
                SeedingAccess::ROLE_TOPIC_CREATOR => 'Topic Creator',
                SeedingAccess::ROLE_SEEDER => 'Seeder',
            ])
            ->default(SeedingAccess::ROLE_SEEDER)
            ->required()
            ->native(false)
            ->dehydrated(true)
            ->helperText('Vai trò chỉ áp dụng cho addon Seeding.');
    }
}
