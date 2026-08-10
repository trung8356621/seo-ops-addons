<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SeoTeam extends SeoPanelPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'team';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = 'Team members';

    protected static ?string $title = 'Team management';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'seo-content-ai::filament.pages.seo-team';

    public function table(Table $table): Table
    {
        $readOnly = $this->isPanelReadOnly();

        return $table
            ->query(fn (): Builder => $this->teamMembersQuery())
            ->heading(__('seo-content-ai::filament.team.team_list'))
            ->description($readOnly
                ? __('seo-content-ai::filament.global_bar.admin_view_only')
                : __('seo-content-ai::filament.team.add_team_member_hint'))
            ->emptyStateHeading(__('seo-content-ai::filament.team.no_members'))
            ->emptyStateIcon('heroicon-o-users')
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label(__('seo-content-ai::filament.team.name'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('name', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('name', $direction))
                    ->weight('medium')
                    ->extraAttributes([
                        'x-data' => '{ timer: null }',
                        'x-on:click' => 'if (timer) { clearTimeout(timer); timer = null; } else { timer = setTimeout(() => { timer = null; }, 250); }',
                        'x-on:dblclick.prevent' => 'recordKey = $el.closest(\'tr\').getAttribute(\'wire:key\'); id = recordKey?.split(\'-\').pop(); if (id) { $wire.mountTableAction(\'editNickname\', id); }',
                    ]),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('seo-content-ai::filament.team.email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\SelectColumn::make('seo_role')
                    ->label(__('seo-content-ai::filament.team.seo_role'))
                    ->options($this->seoRoleOptions())
                    ->selectablePlaceholder(false)
                    ->sortable()
                    ->disabled($readOnly)
                    ->beforeStateUpdated(function (mixed $state, User $record): void {
                        $this->assertCanManageMember($record);
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.team.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        User::STATUS_BLOCK => __('seo-content-ai::filament.team.user_status_banned'),
                        User::STATUS_PENDING => __('seo-content-ai::filament.team.user_status_pending'),
                        default => __('seo-content-ai::filament.team.user_status_normal'),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        User::STATUS_BLOCK => 'danger',
                        User::STATUS_PENDING => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\ToggleColumn::make('is_banned')
                    ->label(__('seo-content-ai::filament.team.ban_toggle'))
                    ->onColor('danger')
                    ->offColor('success')
                    ->disabled($readOnly)
                    ->getStateUsing(fn (User $record): bool => $record->status === User::STATUS_BLOCK)
                    ->updateStateUsing(function (User $record, bool $state): void {
                        $this->assertCanManageMember($record);

                        $record->update([
                            'status' => $state ? User::STATUS_BLOCK : User::STATUS_NORMAL,
                        ]);

                        Notification::make()
                            ->title($state
                                ? __('seo-content-ai::filament.team.member_banned')
                                : __('seo-content-ai::filament.team.member_unbanned'))
                            ->success()
                            ->send();
                    })
                    ->tooltip(__('seo-content-ai::filament.team.ban_toggle_hint')),
            ])
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->headerActions($readOnly ? [] : [
                $this->addMemberAction(),
            ])
            ->actions($readOnly ? [] : [
                Tables\Actions\Action::make('editNickname')
                    ->label(__('Sửa biệt danh'))
                    ->modalHeading(__('Sửa biệt danh'))
                    ->modalSubmitActionLabel(__('Lưu'))
                    ->modalCancelActionLabel(__('Huỷ'))
                    ->form([
                        Forms\Components\TextInput::make('nickname')
                            ->label(__('Biệt danh'))
                            ->maxLength(255)
                            ->default(fn ($record): ?string => $record->getMeta('nickname')),
                    ])
                    ->action(function ($record, array $data): void {
                        $record->setMeta('nickname', $data['nickname']);
                        Notification::make()
                            ->title(__('Đã cập nhật biệt danh'))
                            ->success()
                            ->send();
                    })
                    ->modalAutofocus(),
                Tables\Actions\Action::make('removeFromTeam')
                    ->label(__('seo-content-ai::filament.team.remove_member'))
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('seo-content-ai::filament.team.remove_member'))
                    ->modalDescription(__('seo-content-ai::filament.team.remove_member_confirm'))
                    ->action(function (User $record): void {
                        $this->assertCanManageMember($record);

                        $record->update([
                            'parent_id' => null,
                            'role' => User::ROLE_OWNER,
                            'seo_role' => User::SEO_ROLE_MANAGER,
                            'status' => User::STATUS_NORMAL,
                        ]);

                        Notification::make()
                            ->title(__('seo-content-ai::filament.team.member_removed'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function seoRoleOptions(): array
    {
        return [
            SeoAccessControl::ROLE_MANAGER => __('seo-content-ai::filament.team.role_manager'),
            SeoAccessControl::ROLE_PLANNER => __('seo-content-ai::filament.team.role_planner'),
            SeoAccessControl::ROLE_CONTENT_MANAGER => __('seo-content-ai::filament.team.role_content_manager'),
        ];
    }

    public function addMemberAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('addMember')
            ->label(__('seo-content-ai::filament.team.add_member'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->modalHeading(__('seo-content-ai::filament.team.add_team_member'))
            ->modalDescription(__('seo-content-ai::filament.team.add_team_member_hint'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.team.add_member'))
            ->modalWidth('lg')
            ->form($this->addMemberFormSchema())
            ->action(function (array $data): void {
                $this->persistTeamMember($data);
            });
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private function addMemberFormSchema(): array
    {
        return [
            Forms\Components\Hidden::make('existingUserId'),
            Forms\Components\TextInput::make('memberEmail')
                ->label(__('seo-content-ai::filament.team.email'))
                ->email()
                ->required()
                ->autocomplete('off')
                ->placeholder('member@example.com')
                ->live(debounce: 350)
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $email = strtolower(trim((string) $state));
                    if ($email === '') {
                        $set('existingUserId', null);
                        $set('pickExistingEmail', null);
                        $set('memberName', '');

                        return;
                    }

                    $existing = User::query()->where('email', $email)->first();
                    if ($existing instanceof User) {
                        $this->applyExistingUserToForm($existing, $set);

                        return;
                    }

                    $set('existingUserId', null);
                    $set('pickExistingEmail', null);
                }),
            Forms\Components\Select::make('pickExistingEmail')
                ->label(__('seo-content-ai::filament.team.pick_existing_email'))
                ->placeholder(__('seo-content-ai::filament.team.pick_existing_email_placeholder'))
                ->options(fn (Get $get): array => $this->searchExistingUsersForTeam($get('memberEmail')))
                ->live()
                ->visible(fn (Get $get): bool => blank($get('existingUserId'))
                    && mb_strlen(trim((string) $get('memberEmail'))) >= 2)
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if (! filled($state)) {
                        return;
                    }

                    $existing = User::query()->where('email', strtolower(trim($state)))->first();
                    if ($existing instanceof User) {
                        $this->applyExistingUserToForm($existing, $set);
                    }
                }),
            Forms\Components\Placeholder::make('existingUserNotice')
                ->label('')
                ->content(function (Get $get): HtmlString {
                    $name = trim((string) $get('memberName'));
                    $email = trim((string) $get('memberEmail'));

                    return new HtmlString(
                        '<div class="rounded-lg border border-success-200 bg-success-50 px-3 py-2 text-sm text-success-800 dark:border-success-800 dark:bg-success-950 dark:text-success-200">'
                        .e(__('seo-content-ai::filament.team.existing_user_notice', [
                            'name' => $name !== '' ? $name : $email,
                            'email' => $email,
                        ]))
                        .'</div>',
                    );
                })
                ->visible(fn (Get $get): bool => filled($get('existingUserId'))),
            Forms\Components\TextInput::make('memberName')
                ->label(__('seo-content-ai::filament.team.name'))
                ->placeholder(__('seo-content-ai::filament.team.full_name_placeholder'))
                ->maxLength(255)
                ->required(fn (Get $get): bool => blank($get('existingUserId')))
                ->visible(fn (Get $get): bool => blank($get('existingUserId'))),
            Forms\Components\TextInput::make('memberPassword')
                ->label(__('seo-content-ai::filament.team.password'))
                ->password()
                ->revealable()
                ->placeholder(__('seo-content-ai::filament.team.password_placeholder'))
                ->minLength(8)
                ->required(fn (Get $get): bool => blank($get('existingUserId')))
                ->visible(fn (Get $get): bool => blank($get('existingUserId'))),
            Forms\Components\Select::make('memberSeoRole')
                ->label(__('seo-content-ai::filament.team.seo_role'))
                ->options($this->seoRoleOptions())
                ->default(SeoAccessControl::ROLE_CONTENT_MANAGER)
                ->required(fn (Get $get): bool => blank($get('existingUserId')))
                ->visible(fn (Get $get): bool => blank($get('existingUserId'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistTeamMember(array $data): void
    {
        SeoAccessControl::guardSeoPanelMutation();

        /** @var User|null $owner */
        $owner = auth()->user();
        if (! $owner instanceof User) {
            return;
        }

        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) $owner->id;
        $existingUserId = (int) ($data['existingUserId'] ?? 0);

        if ($existingUserId > 0) {
            $existing = User::query()->find($existingUserId);
            if (! $existing instanceof User) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.team.member_add_failed'))
                    ->body(__('seo-content-ai::filament.team.existing_user_not_found'))
                    ->danger()
                    ->send();

                return;
            }

            try {
                $this->attachExistingMember($ownerId, $existing);
            } catch (ValidationException $exception) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.team.member_add_failed'))
                    ->body(collect($exception->errors())->flatten()->first() ?? '')
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title(__('seo-content-ai::filament.team.member_linked'))
                ->success()
                ->send();

            return;
        }

        $validated = validator($data, [
            'memberName' => ['required', 'string', 'max:255'],
            'memberEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'memberPassword' => ['required', 'string', 'min:8'],
            'memberSeoRole' => ['required', Rule::in(array_keys($this->seoRoleOptions()))],
        ])->validate();

        User::query()->create([
            'parent_id' => $ownerId,
            'role' => User::ROLE_STAFF,
            'seo_role' => (string) $validated['memberSeoRole'],
            'status' => User::STATUS_NORMAL,
            'name' => trim((string) $validated['memberName']),
            'email' => strtolower(trim((string) $validated['memberEmail'])),
            'password' => Hash::make((string) $validated['memberPassword']),
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.team.member_added'))
            ->success()
            ->send();
    }

    private function attachExistingMember(int $ownerId, User $existing): void
    {
        if ((int) $existing->id === $ownerId) {
            throw ValidationException::withMessages([
                'memberEmail' => __('seo-content-ai::filament.team.cannot_add_self'),
            ]);
        }

        if ((int) $existing->parent_id === $ownerId) {
            throw ValidationException::withMessages([
                'memberEmail' => __('seo-content-ai::filament.team.already_team_member'),
            ]);
        }

        if ((int) $existing->parent_id > 0 && (int) $existing->parent_id !== $ownerId) {
            throw ValidationException::withMessages([
                'memberEmail' => __('seo-content-ai::filament.team.already_other_team'),
            ]);
        }

        $existing->update([
            'parent_id' => $ownerId,
            'role' => User::ROLE_STAFF,
            'seo_role' => SeoAccessControl::normalizeRole(
                (string) ($existing->seo_role ?: SeoAccessControl::ROLE_CONTENT_MANAGER),
            ),
        ]);
    }

    private function applyExistingUserToForm(User $existing, Set $set): void
    {
        $set('memberEmail', (string) $existing->email);
        $set('pickExistingEmail', (string) $existing->email);
        $set('existingUserId', (int) $existing->id);
        $set('memberName', (string) $existing->display_name);
    }

    /**
     * @return array<string, string>
     */
    private function searchExistingUsersForTeam(mixed $search): array
    {
        $term = strtolower(trim((string) $search));
        if (mb_strlen($term) < 2) {
            return [];
        }

        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();
        if ($ownerId <= 0) {
            return [];
        }

        return User::query()
            ->where('email', 'like', '%'.$term.'%')
            ->whereKeyNot($ownerId)
            ->where(function ($query) use ($ownerId): void {
                $query->whereNull('parent_id')
                    ->orWhere('parent_id', '!=', $ownerId);
            })
            ->orderBy('email')
            ->limit(8)
            ->get()
            ->mapWithKeys(function (User $user): array {
                $label = trim((string) $user->email);
                $name = trim((string) $user->display_name);
                if ($name !== '') {
                    $label .= ' — '.$name;
                }

                return [(string) $user->email => $label];
            })
            ->all();
    }

    private function teamMembersQuery(): Builder
    {
        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();

        return User::query()
            ->where('parent_id', $ownerId)
            ->where('role', User::ROLE_STAFF);
    }

    private function assertCanManageMember(User $member): void
    {
        SeoAccessControl::guardSeoPanelMutation();

        $ownerId = SeoAccessControl::accountOwnerId() ?? (int) auth()->id();

        if ((int) $member->parent_id !== $ownerId || $member->role !== User::ROLE_STAFF) {
            abort(403);
        }
    }

    private function isPanelReadOnly(): bool
    {
        return SeoAccessControl::isSeoPanelReadOnly();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.team_members');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.team_management');
    }
}
