<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\DomainResource\Pages\Concerns;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

trait PersistsDomainPromptContext
{
    /** @var array<string, mixed> */
    protected array $pendingPromptContext = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillDomainPromptContextFormData(Site $site, array $data): array
    {
        $site->loadMissing('metas');

        $data['promptContext'] = $this->preparePromptContextForForm(
            app(SiteDomainPromptContextService::class)->getRawPayloadForSite($site),
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripPromptContextFromFormData(array $data): array
    {
        unset($data['promptContext']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $formState
     */
    protected function queuePromptContextFromFormState(array $formState): void
    {
        $ctx = is_array($formState['promptContext'] ?? null) ? $formState['promptContext'] : [];

        $this->pendingPromptContext = [
            // Domain tone UI removed — preserve legacy meta; generation ignores it.
            'tone' => null,
            'company_short_identity' => trim((string) ($ctx['company_short_identity'] ?? '')),
            'short_description' => (string) ($ctx['short_description'] ?? ''),
            'cta_intro' => (string) ($ctx['cta_intro'] ?? ''),
            'phones' => $this->repeaterItemsFromState($ctx['phones'] ?? []),
            'emails' => $this->repeaterItemsFromState($ctx['emails'] ?? []),
            'socials' => $this->repeaterItemsFromState($ctx['socials'] ?? []),
            'address' => trim((string) ($ctx['address'] ?? '')),
            'cta' => [],
            'links' => $this->repeaterItemsFromState($ctx['links'] ?? []),
        ];
    }

    protected function persistPendingDomainPromptContext(Site $site): void
    {
        if ($this->pendingPromptContext === []) {
            return;
        }

        try {
            $existing = app(SiteDomainPromptContextService::class)->getRawPayloadForSite($site);
            $payload = $this->pendingPromptContext;
            // Never wipe legacy domain tone from a form that no longer edits it.
            if (($payload['tone'] ?? null) === null) {
                $payload['tone'] = trim((string) ($existing['tone'] ?? ''));
            }
            app(SiteDomainPromptContextService::class)->saveForSite($site, $payload);
            // Save-fast: persist manual links to SiteLinkCatalog only — NEVER keyword sync / parse / crawl.
            app(\Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteLinkCatalogReconciler::class)
                ->syncManualLinksFromSettings($site, $this->pendingPromptContext['links'] ?? []);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.save_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->pendingPromptContext = [];
        }
    }

    /**
     * @param  array{
     *     tone?: string,
     *     short_description?: string,
     *     cta_intro?: string,
     *     cta?: list<array<string, string>>,
     *     links?: list<array<string, string>>,
     * }  $context
     * @return array<string, mixed>
     */
    protected function preparePromptContextForForm(array $context): array
    {
        $service = app(SiteDomainPromptContextService::class);

        $phones = $service->phonesFromPayload($context);
        $emails = $service->emailsFromPayload($context);
        $socials = $service->socialsFromPayload($context);

        return [
            'tone' => (string) ($context['tone'] ?? ''),
            'company_short_identity' => (string) ($context['company_short_identity'] ?? ''),
            'short_description' => (string) ($context['short_description'] ?? ''),
            'cta_intro' => (string) ($context['cta_intro'] ?? ''),
            'phones' => $this->repeaterStateForFill($phones),
            'emails' => $this->repeaterStateForFill($emails),
            'socials' => $this->repeaterStateForFill($socials),
            'address' => $service->ctaValueFromRows($context['cta'] ?? [], 'address'),
            'links' => $this->repeaterStateForFill($context['links'] ?? []),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function filterDedicatedCtaRows(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $row): bool => ! in_array(
                mb_strtolower(trim((string) ($row['type'] ?? ''))),
                [
                    ...SiteDomainPromptContextService::reservedCtaTypes(),
                    ...SiteDomainPromptContextService::globalOnlyCtaTypes(),
                ],
                true,
            ),
        ));
    }

    /**
     * Filament Repeater cần key UUID khi hydrate — list thuần từ JSON sẽ không hiển thị.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    protected function repeaterStateForFill(array $items): array
    {
        $state = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $state[(string) Str::uuid()] = $item;
        }

        return $state;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function repeaterItemsFromState(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $items = [];

        foreach ($state as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }
}
