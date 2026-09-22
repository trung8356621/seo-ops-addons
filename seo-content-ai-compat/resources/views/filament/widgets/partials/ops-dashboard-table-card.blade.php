@php
    $rows = is_array($rows ?? null) ? $rows : [];
    $headers = is_array($headers ?? null) ? $headers : [];
    $rowType = (string) ($rowType ?? 'pending');
@endphp

<section @class(['ops-landing-card', 'ops-landing-card--compact-empty' => $rows === []])>
    <div class="ops-landing-card__head">
        <h3 class="ops-landing-card__title">{{ $title ?? '' }}</h3>
        @if (filled($viewAllUrl ?? null))
            <a href="{{ $viewAllUrl }}" class="ops-landing-card__link">
                {{ __('seo-content-ai::filament.dashboard.ops_view_all') }} →
            </a>
        @endif
    </div>

    @if ($rows === [])
        <p class="ops-landing-card__empty">{{ $empty ?? '' }}</p>
    @else
        <div class="ops-landing-table-wrap">
            <table class="ops-landing-table">
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['index'] ?? '' }}</td>
                            <td>
                                @if (filled($row['url'] ?? null))
                                    <a href="{{ $row['url'] }}" class="ops-landing-table__title">{{ $row['title'] ?? '—' }}</a>
                                @else
                                    <span class="ops-landing-table__title">{{ $row['title'] ?? '—' }}</span>
                                @endif
                            </td>
                            @if ($rowType === 'reviewed')
                                <td>{{ $row['reviewer'] ?? '—' }}</td>
                                <td>{{ $row['reviewed_at'] ?? '—' }}</td>
                            @else
                                <td>{{ $row['project'] ?? '—' }}</td>
                                <td>{{ $row['updated_at'] ?? '—' }}</td>
                            @endif
                            <td>
                                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-badge', [
                                    'status' => $row['status'] ?? ($rowType === 'reviewed' ? 'reviewed' : 'pending_review'),
                                ])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
