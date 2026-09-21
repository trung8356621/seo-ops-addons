@props([
    'matrix' => [],
    'embedded' => false,
])

@php
    $matrix = is_array($matrix) ? $matrix : [];
    $months = is_array($matrix['months'] ?? null) ? $matrix['months'] : [];
    $rows = is_array($matrix['rows'] ?? null) ? $matrix['rows'] : [];
    $activeMonth = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::normalize(
        $matrix['active_month'] ?? null,
    );
    $embedded = (bool) $embedded;
@endphp

@once
    <style>
        .cp-list-monthly-planning {
            margin-bottom: 1rem;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.75rem;
            background: #fff;
            overflow: hidden;
        }
        .dark .cp-list-monthly-planning {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(17 24 39);
        }
        .cp-list-monthly-planning--embedded {
            margin: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            display: flex;
            flex-direction: column;
            min-height: 0;
            flex: 1 1 auto;
            overflow: hidden;
        }
        .dark .cp-list-monthly-planning--embedded {
            background: transparent;
        }
        .cp-list-monthly-planning__head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-bottom: 1px solid rgb(229 231 235);
        }
        .cp-list-monthly-planning--embedded .cp-list-monthly-planning__head {
            padding: 0 0 0.75rem;
            margin-bottom: 0.75rem;
            border-bottom: 0;
            align-items: flex-start;
        }
        .dark .cp-list-monthly-planning__head {
            border-bottom-color: rgb(255 255 255 / 0.1);
        }
        .cp-list-monthly-planning__title {
            margin: 0;
            font-size: 0.875rem;
            font-weight: 600;
            color: rgb(17 24 39);
        }
        .dark .cp-list-monthly-planning__title {
            color: #fff;
        }
        .cp-list-monthly-planning__hint {
            margin: 0.125rem 0 0;
            font-size: 0.75rem;
            color: rgb(107 114 128);
        }
        .dark .cp-list-monthly-planning__hint {
            color: rgb(156 163 175);
        }
        .cp-list-monthly-planning__scroll {
            max-height: 14rem;
            overflow: auto;
            min-height: 0;
            flex: 1 1 auto;
        }
        .cp-list-monthly-planning--embedded .cp-list-monthly-planning__scroll {
            max-height: 12.5rem;
            margin: 0 -0.25rem;
        }
        .cp-list-monthly-planning__table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }
        .cp-list-monthly-planning__table th,
        .cp-list-monthly-planning__table td {
            padding: 0.375rem 0.5rem;
            text-align: right;
            white-space: nowrap;
            border-bottom: 1px solid rgb(243 244 246);
        }
        .dark .cp-list-monthly-planning__table th,
        .dark .cp-list-monthly-planning__table td {
            border-bottom-color: rgb(255 255 255 / 0.06);
        }
        .cp-list-monthly-planning__table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: rgb(249 250 251);
            font-weight: 600;
            color: rgb(107 114 128);
        }
        .dark .cp-list-monthly-planning__table thead th {
            background: rgb(31 41 55);
            color: rgb(156 163 175);
        }
        .cp-list-monthly-planning--embedded .cp-list-monthly-planning__table thead th {
            background: #fff;
        }
        .dark .cp-list-monthly-planning--embedded .cp-list-monthly-planning__table thead th {
            background: rgb(17 24 39);
        }
        .cp-list-monthly-planning__domain {
            text-align: left !important;
            font-weight: 500;
            color: rgb(31 41 55);
            max-width: 8.5rem;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dark .cp-list-monthly-planning__domain {
            color: rgb(229 231 235);
        }
        .cp-list-monthly-planning__table th.is-active,
        .cp-list-monthly-planning__table td.is-active {
            background: rgb(254 243 199 / 0.55);
            color: rgb(146 64 14);
            font-weight: 600;
        }
        .dark .cp-list-monthly-planning__table th.is-active,
        .dark .cp-list-monthly-planning__table td.is-active {
            background: rgb(146 64 14 / 0.25);
            color: rgb(253 230 138);
        }
        .cp-list-monthly-planning__empty {
            margin: 0;
            padding: 0.5rem 0;
            font-size: 0.75rem;
            color: rgb(107 114 128);
        }
    </style>
@endonce

<section
    @class([
        'cp-list-monthly-planning',
        'cp-list-monthly-planning--embedded' => $embedded,
    ])
    data-cp-list-monthly-planning="1"
>
    <div class="cp-list-monthly-planning__head">
        <div class="min-w-0">
            <h3 class="cp-list-monthly-planning__title">
                {{ __('seo-content-ai::filament.projects.list_monthly_planning_title') }}
            </h3>
            <p class="cp-list-monthly-planning__hint">
                {{ __('seo-content-ai::filament.projects.list_monthly_planning_hint') }}
            </p>
        </div>
    </div>

    @if ($rows === [] || $months === [])
        <p class="cp-list-monthly-planning__empty">
            {{ __('seo-content-ai::filament.projects.site_planning_empty') }}
        </p>
    @else
        <div class="cp-list-monthly-planning__scroll">
            <table class="cp-list-monthly-planning__table">
                <thead>
                    <tr>
                        <th scope="col" class="cp-list-monthly-planning__domain">
                            {{ __('seo-content-ai::filament.projects.site_planning_domain_col') }}
                        </th>
                        @foreach ($months as $month)
                            @php
                                $isActive = (bool) ($month['is_current'] ?? false)
                                    || \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::normalize($month['key'] ?? null) === $activeMonth;
                                $label = (string) ($month['month'] ?? '');
                                if ($label === '' && isset($month['label'])) {
                                    $label = (string) $month['label'];
                                }
                            @endphp
                            <th scope="col" @class(['is-active' => $isActive])>
                                {{ $label }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $domain = (string) ($row['domain'] ?? '');
                            $cells = is_array($row['months'] ?? null) ? $row['months'] : [];
                        @endphp
                        <tr>
                            <th scope="row" class="cp-list-monthly-planning__domain" title="{{ $domain }}">
                                {{ $domain }}
                            </th>
                            @foreach ($cells as $cell)
                                @php
                                    $isActive = (bool) ($cell['is_current'] ?? false)
                                        || \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::normalize($cell['planning_month'] ?? $cell['key'] ?? null) === $activeMonth;
                                @endphp
                                <td @class(['is-active' => $isActive])>
                                    {{ (int) ($cell['planned'] ?? 0) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
