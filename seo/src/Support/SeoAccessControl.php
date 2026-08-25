<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use App\Models\SeoDatabaseConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cookie;

final class SeoAccessControl
{
    private const GLOBAL_SITE_COOKIE = 'seo_global_site_id';

    private const GLOBAL_CONTENT_PROJECT_COOKIE = 'seo_global_content_project_id';

    private const GLOBAL_CONTENT_PROJECT_SITE_COOKIE = 'seo_global_content_project_site_id';

    private const GLOBAL_SITE_COOKIE_MINUTES = 60 * 24 * 365;

    public const ROLE_MANAGER = 'manager';

    public const ROLE_PLANNER = 'planner';

    public const ROLE_CONTENT_MANAGER = 'content_manager';

    /** @var array<string, int> */
    private const ROLE_RANK = [
        self::ROLE_CONTENT_MANAGER => 1,
        self::ROLE_PLANNER => 2,
        self::ROLE_MANAGER => 3,
    ];

    public static function actualRole(): string
    {
        /** @var User|null $user */
        $user = auth()->user();

        // seo_role is the only SEO rank source (including owner).
        // owner + content_manager keeps content_manager SEO rules while client setup stays owner-gated.
        return self::normalizeRole((string) ($user?->seo_role ?? self::ROLE_CONTENT_MANAGER));
    }

    public static function effectiveRole(): string
    {
        $actual = self::actualRole();
        $simulated = self::normalizeRole((string) session('seo_simulated_role', $actual));

        return in_array($simulated, self::allowedSimulationTargets($actual), true)
            ? $simulated
            : $actual;
    }

    /**
     * @return list<string>
     */
    public static function allowedSimulationTargets(?string $actualRole = null): array
    {
        $actualRole = self::normalizeRole((string) ($actualRole ?? self::actualRole()));

        return match ($actualRole) {
            self::ROLE_MANAGER => [self::ROLE_CONTENT_MANAGER, self::ROLE_PLANNER, self::ROLE_MANAGER],
            self::ROLE_PLANNER => [self::ROLE_CONTENT_MANAGER, self::ROLE_PLANNER],
            default => [self::ROLE_CONTENT_MANAGER],
        };
    }

    public static function canAccessManagerFeatures(): bool
    {
        return self::rank(self::effectiveRole()) >= self::rank(self::ROLE_MANAGER);
    }

    /** Operation Center — manager/admin only, không expose khách. */
    public static function canAccessContentOperations(): bool
    {
        return self::canAccessManagerFeatures();
    }

    public static function canArchiveContentProjects(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessManagerFeatures();
    }

    public static function canViewProjectArchives(): bool
    {
        return self::canAccessManagerFeatures();
    }

    public static function canReviewKeywords(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessPlannerFeatures();
    }

    public static function canRestoreKeywords(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessManagerFeatures();
    }

    public static function canManageKeywordReviewReasons(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessManagerFeatures();
    }

    public static function canOverrideKeywordReviewSeverity(): bool
    {
        return self::canManageKeywordReviewReasons();
    }

    public static function canAccessPlannerFeatures(): bool
    {
        return self::rank(self::effectiveRole()) >= self::rank(self::ROLE_PLANNER);
    }

    /**
     * Planner / manager (actual rank > content_manager) được ghi đè
     * bài writer khi conflict token lệch — không phụ thuộc VIEW AS giả lập.
     */
    public static function canForceArticleContentOverwrite(): bool
    {
        if (! self::canMutateInSeoPanel()) {
            return false;
        }

        return self::rank(self::actualRole()) > self::rank(self::ROLE_CONTENT_MANAGER);
    }

    public static function canViewAutomationRules(): bool
    {
        return self::canAccessPlannerFeatures() || self::canMutateInSeoPanel();
    }

    public static function canManageAutomationRules(): bool
    {
        return self::canMutateInSeoPanel()
            && (self::canAccessPlannerFeatures() || self::canAccessManagerFeatures());
    }

    public static function canPublishAutomationRules(): bool
    {
        return self::canManageAutomationRules();
    }

    public static function canExportAutomationRules(): bool
    {
        return self::canViewAutomationRules();
    }

    public static function canImportAutomationRules(): bool
    {
        return self::canManageAutomationRules();
    }

    public static function canRunAutomationTests(): bool
    {
        return self::canManageAutomationRules();
    }

    public static function canAccessContentFeatures(): bool
    {
        return self::rank(self::effectiveRole()) >= self::rank(self::ROLE_CONTENT_MANAGER);
    }

    public static function canAccessSeoPanel(?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        // Owner luôn vào SEO panel (kể cả khi seo_role trống).
        if ((string) $user->role === User::ROLE_OWNER) {
            return true;
        }

        return $user->isStaff()
            && (int) $user->parent_id > 0
            && filled($user->seo_role);
    }

    public static function canManageWordPressPlugin(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ((string) $user->role === User::ROLE_OWNER) {
            return true;
        }

        return self::canAccessManagerFeatures();
    }

    public static function isContentManager(): bool
    {
        return self::effectiveRole() === self::ROLE_CONTENT_MANAGER;
    }

    public static function isPlanner(): bool
    {
        return self::effectiveRole() === self::ROLE_PLANNER;
    }

    public static function shouldShowGlobalSeoBar(): bool
    {
        return ! self::isContentManager();
    }

    public static function shouldShowGlobalSitePicker(): bool
    {
        if (SeoPanelRoutes::is('filament.seo.resources.keywords.*')) {
            return false;
        }

        if (SeoPanelRoutes::is('filament.seo.pages.mcp-intelligence')) {
            return false;
        }

        if (
            SeoPanelRoutes::is('filament.seo.pages.articles-optimal', 'filament.seo.pages.content-projects-seo-audit')
            || request()->is(
                'seo/*/articles/optimal',
                'seo/*/articles/optimal/*',
                'seo/*/content-projects/seo-audit',
                'seo/*/content-projects/seo-audit/*',
                'seo/content-projects/seo-audit',
                'seo/content-projects/seo-audit/*',
            )
        ) {
            return false;
        }

        if (SeoPanelRoutes::is('filament.seo.pages.performance-hub')) {
            $source = (string) request()->query('source', 'gsc');

            if ($source !== '' && $source !== 'gsc') {
                return false;
            }
        }

        return true;
    }

    public static function isSeoPanelAdminViewer(): bool
    {
        return false;
    }

    public static function isSeoPanelReadOnly(): bool
    {
        return false;
    }

    public static function canMutateInSeoPanel(): bool
    {
        return true;
    }

    public static function shouldScopeToAccountOwner(): bool
    {
        return true;
    }

    public static function panelOwnerId(): ?int
    {
        $connection = SeoConnectionContext::current();
        if (! $connection instanceof SeoDatabaseConnection) {
            return null;
        }

        $owner = $connection->users()
            ->where('role', User::ROLE_OWNER)
            ->orderBy('users.id')
            ->first();

        if ($owner instanceof User) {
            return (int) $owner->id;
        }

        $fallback = $connection->users()->orderBy('users.id')->first();

        return $fallback instanceof User ? (int) $fallback->id : null;
    }

    public static function guardSeoPanelMutation(): void
    {
        abort_if(self::isSeoPanelReadOnly(), 403);
    }

    public static function canMutateContentProjects(): bool
    {
        return self::canManageContentProjectWorkflow();
    }

    /**
     * Planner-equivalent Content Project workflow management.
     * planner + manager via seo_role rank. content_manager = false.
     * Does not grant Prompt / user / system settings rights.
     */
    public static function canManageContentProjectWorkflow(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessPlannerFeatures();
    }

    /**
     * Dev/recovery lifecycle override (Approved ↔ Scheduled ↔ Published).
     * Feature-flagged; Planner-equivalent only; never content_manager.
     */
    public static function canDebugContentProjectLifecycle(): bool
    {
        if (! (bool) config('seo-content-ai.content_project.debug_lifecycle_override', false)) {
            return false;
        }

        return self::canManageContentProjectWorkflow();
    }

    /**
     * Content Manager simplified ops presentation (3 KPI cards, edit-only).
     */
    public static function usesContentManagerOpsPresentation(): bool
    {
        return self::canSubmitArticleReview() && ! self::canManageContentProjectWorkflow();
    }

    /**
     * Generate / rerun / enqueue AI for Content Project — planner-equivalent only.
     * Assigned content_manager writers may view/edit, not run generation.
     */
    public static function canAccessContentProjectRun(?SeoProject $project): bool
    {
        if (! $project instanceof SeoProject) {
            return false;
        }

        return self::canManageContentProjectWorkflow();
    }

    public static function canRetryProjectRunItem(?SeoProject $project = null): bool
    {
        return self::canManageContentProjectWorkflow();
    }

    public static function canDeleteSeoMedia(): bool
    {
        return self::canMutateInSeoPanel() && ! self::isContentManager();
    }

    public static function canSyncArticlesToWordPress(): bool
    {
        return self::canMutateInSeoPanel() && ! self::isContentManager();
    }

    /**
     * Article Review workflow — content manager gửi bài để planner duyệt.
     */
    public static function canSubmitArticleReview(): bool
    {
        return self::canMutateInSeoPanel() && self::isContentManager();
    }

    /**
     * Article Review workflow — planner (hoặc rank cao hơn) duyệt bài.
     */
    public static function canApproveArticleReview(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessPlannerFeatures();
    }

    /**
     * Article Review workflow — chỉ manager mới được lưu trữ (archive) bài đã duyệt.
     */
    public static function canFinalizeArticleReview(): bool
    {
        return self::canArchiveContentProjects();
    }

    /**
     * @return list<int>
     */
    public static function accessibleSiteIds(): array
    {
        return self::accessibleSitesQuery()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Lọc theo site_id bằng danh sách đã resolve trên DB core — tránh subquery cross-database trên hosting.
     */
    public static function applyAccessibleSiteScope(Builder $query, string $column = 'site_id'): Builder
    {
        if (! self::shouldScopeToAccountOwner()) {
            return $query;
        }

        $siteIds = self::accessibleSiteIds();
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $siteIds);
    }

    public static function accountSiteOwnerId(): int
    {
        return self::accountOwnerId() ?? (int) auth()->id();
    }

    /**
     * @return Builder<Site>
     */
    public static function accessibleSitesQuery(): Builder
    {
        $query = Site::query();

        if (! self::shouldScopeToAccountOwner()) {
            return $query;
        }

        return $query->where('user_id', self::accountSiteOwnerId());
    }

    public static function canAccessSite(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        return self::accessibleSitesQuery()->whereKey($siteId)->exists();
    }

    public static function canAccessArticle(SeoArticle $article): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if (! self::canAccessSeoPanel($user)) {
            return false;
        }

        if ((string) $user->role === User::ROLE_OWNER) {
            return true;
        }

        if (self::isContentManager()) {
            return ArticleResource::canContentManagerAccessArticle($article);
        }

        $siteId = (int) ($article->site_id ?? 0);

        return $siteId > 0 && self::canAccessSite($siteId);
    }

    public static function shouldApplyGlobalSiteScope(): bool
    {
        return ! self::isContentManager() && self::globalSiteId() !== null;
    }

    public static function domainContext(): DomainContext
    {
        if (self::isContentManager()) {
            return DomainContext::all();
        }

        return app(DomainContextResolver::class)->current();
    }

    public static function accountOwnerId(): ?int
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        $ownerId = $user->accountOwnerId();

        return $ownerId ?? (int) $user->id;
    }

    public static function globalSiteId(): ?int
    {
        return self::domainContext()->siteId;
    }

    public static function hasGlobalSiteSelection(): bool
    {
        return app(DomainContextResolver::class)->hasExplicitRequestKey()
            || ! self::domainContext()->isAllDomains;
    }

    public static function hasGlobalSiteScope(): bool
    {
        return self::globalSiteId() !== null;
    }

    public static function setGlobalSiteId(?int $siteId): void
    {
        $previousSiteId = self::globalSiteId();
        $next = app(DomainContextResolver::class)->contextForAccessibleSiteId($siteId);

        if ($previousSiteId !== $next->siteId) {
            self::clearGlobalContentProjectSelection();
        }

        app(DomainContextResolver::class)->bind($next);
        self::forgetLegacyGlobalSitePersistence();
    }

    public static function clearGlobalSiteSelection(): void
    {
        app(DomainContextResolver::class)->bind(DomainContext::all());
        self::forgetLegacyGlobalSitePersistence();
        self::clearGlobalContentProjectSelection();
    }

    /**
     * Drop legacy PHP session + cookie that used to own the Global Domain Selector.
     * Browser sessionStorage / localStorage / ?domain= are the source of truth now.
     */
    public static function forgetLegacyGlobalSitePersistence(): void
    {
        session()->forget('seo_global_site_id');
        Cookie::queue(Cookie::forget(self::GLOBAL_SITE_COOKIE, '/'));
    }

    public static function canUseGlobalContentProjectPicker(): bool
    {
        return self::isPlanner() && self::globalSiteId() !== null;
    }

    public static function globalContentProjectId(): ?int
    {
        if (! self::canUseGlobalContentProjectPicker()) {
            return null;
        }

        $siteId = (int) self::globalSiteId();
        $storedSiteId = self::resolveStoredGlobalContentProjectSiteId();
        $projectId = self::resolveStoredGlobalContentProjectId();

        if ($storedSiteId !== $siteId || $projectId === null) {
            return null;
        }

        if (! self::isAssignableGlobalContentProject($projectId, $siteId)) {
            self::clearGlobalContentProjectSelection();

            return null;
        }

        return $projectId;
    }

    public static function setGlobalContentProjectId(?int $projectId): void
    {
        $siteId = self::globalSiteId();
        if ($siteId === null || $projectId === null || $projectId <= 0) {
            self::clearGlobalContentProjectSelection();

            return;
        }

        if (! self::isAssignableGlobalContentProject($projectId, $siteId)) {
            self::clearGlobalContentProjectSelection();

            return;
        }

        session([
            'seo_global_content_project_id' => $projectId,
            'seo_global_content_project_site_id' => $siteId,
        ]);

        Cookie::queue(cookie(
            self::GLOBAL_CONTENT_PROJECT_COOKIE,
            (string) $projectId,
            self::GLOBAL_SITE_COOKIE_MINUTES,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax',
        ));
        Cookie::queue(cookie(
            self::GLOBAL_CONTENT_PROJECT_SITE_COOKIE,
            (string) $siteId,
            self::GLOBAL_SITE_COOKIE_MINUTES,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax',
        ));
    }

    public static function clearGlobalContentProjectSelection(): void
    {
        session()->forget([
            'seo_global_content_project_id',
            'seo_global_content_project_site_id',
        ]);
        Cookie::queue(Cookie::forget(self::GLOBAL_CONTENT_PROJECT_COOKIE, '/'));
        Cookie::queue(Cookie::forget(self::GLOBAL_CONTENT_PROJECT_SITE_COOKIE, '/'));
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));

        return array_key_exists($role, self::ROLE_RANK)
            ? $role
            : self::ROLE_CONTENT_MANAGER;
    }

    private static function rank(string $role): int
    {
        $role = self::normalizeRole($role);

        return self::ROLE_RANK[$role] ?? self::ROLE_RANK[self::ROLE_CONTENT_MANAGER];
    }

    private static function resolveStoredGlobalContentProjectSiteId(): ?int
    {
        $cookieSiteId = request()->cookie(self::GLOBAL_CONTENT_PROJECT_SITE_COOKIE);
        $siteId = $cookieSiteId !== null && $cookieSiteId !== ''
            ? $cookieSiteId
            : session('seo_global_content_project_site_id');

        if ($siteId === null || $siteId === '') {
            return null;
        }

        $siteId = (int) $siteId;

        return $siteId > 0 ? $siteId : null;
    }

    private static function resolveStoredGlobalContentProjectId(): ?int
    {
        $cookieProjectId = request()->cookie(self::GLOBAL_CONTENT_PROJECT_COOKIE);
        $projectId = $cookieProjectId !== null && $cookieProjectId !== ''
            ? $cookieProjectId
            : session('seo_global_content_project_id');

        if ($projectId === null || $projectId === '') {
            return null;
        }

        $projectId = (int) $projectId;

        return $projectId > 0 ? $projectId : null;
    }

    private static function isAssignableGlobalContentProject(int $projectId, int $siteId): bool
    {
        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return false;
        }

        if ((int) $project->site_id !== $siteId) {
            return false;
        }

        return $project->canRegisterMoreTasks();
    }

    public static function canViewAutomation(): bool
    {
        return self::canAccessPlannerFeatures() || self::canMutateInSeoPanel();
    }

    /**
     * Legacy helper. /admin no longer hosts Automation UI and no longer
     * grants panel access through this method. Automation product UI is on /seo.
     */
    public static function canAccessAdminAutomationPanel(?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        // Evaluate as current auth user (Filament always checks the authenticated user).
        if (auth()->id() !== $user->id) {
            return false;
        }

        return self::canViewAutomation();
    }

    public static function canEditAutomation(): bool
    {
        return self::canMutateInSeoPanel()
            && (self::canAccessPlannerFeatures() || self::canMutateInSeoPanel());
    }

    public static function canPublishAutomation(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessManagerFeatures();
    }

    public static function canEnableAutomation(): bool
    {
        return self::canPublishAutomation();
    }

    public static function canExecuteAutomationTest(): bool
    {
        return self::canMutateInSeoPanel() && self::canAccessPlannerFeatures();
    }

    public static function canRetryAutomationExecution(): bool
    {
        return self::canExecuteAutomationTest();
    }

    public static function canCancelAutomationExecution(): bool
    {
        return self::canExecuteAutomationTest();
    }

    public static function canClearAutomationLogs(): bool
    {
        if (! self::canAccessManagerFeatures()) {
            return false;
        }

        return self::canMutateInSeoPanel();
    }

    public static function canManageAutomationSettings(): bool
    {
        return self::canClearAutomationLogs();
    }

    public static function guardAutomationEdit(): void
    {
        abort_unless(self::canEditAutomation(), 403);
    }

    public static function guardAutomationPublish(): void
    {
        abort_unless(self::canPublishAutomation(), 403);
    }

    public static function guardAutomationExecuteTest(): void
    {
        abort_unless(self::canExecuteAutomationTest(), 403);
    }

    public static function guardAutomationRetry(): void
    {
        abort_unless(self::canRetryAutomationExecution(), 403);
    }

    public static function guardAutomationCancel(): void
    {
        abort_unless(self::canCancelAutomationExecution(), 403);
    }

    public static function guardAutomationClearLogs(): void
    {
        abort_unless(self::canClearAutomationLogs(), 403);
    }
}
