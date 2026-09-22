@php
    $rows = is_array($rows ?? null) ? $rows : [];
    $headers = is_array($headers ?? null) ? $headers : [];
    $rowType = (string) ($rowType ?? 'pending');
@endphp

<div style="border:1px solid #e5e7eb;border-radius:0.75rem;background:#fff;padding:1rem 1.1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:0.75rem">
        <h3 style="margin:0;font-size:1rem;font-weight:600;color:#111827">{{ $title ?? '' }}</h3>
        @if (filled($viewAllUrl ?? null))
            <a href="{{ $viewAllUrl }}" style="font-size:0.75rem;font-weight:600;color:#2563eb;text-decoration:none">
                {{ __('seo-content-ai::filament.dashboard.ops_view_all') }}
            </a>
        @endif
    </div>

    @if ($rows === [])
        <p style="margin:0;font-size:0.875rem;color:#6b7280">{{ $empty ?? '' }}</p>
    @else
        <div style="overflow-x:auto">
            <table style="width:100%;min-width:32rem;border-collapse:collapse;font-size:0.875rem">
                <thead>
                    <tr style="border-bottom:1px solid #f3f4f6;text-align:left;font-size:0.75rem;font-weight:500;color:#6b7280">
                        @foreach ($headers as $header)
                            <th style="padding:0.5rem {{ $loop->first ? '0.5rem 0.5rem 0' : ($loop->last ? '0 0.5rem 0.5rem' : '0.5rem') }}">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="border-bottom:1px solid #f9fafb">
                            <td style="padding:0.65rem 0.5rem 0.65rem 0;color:#6b7280">{{ $row['index'] ?? '' }}</td>
                            <td style="padding:0.65rem 0.5rem">
                                @if (filled($row['url'] ?? null))
                                    <a href="{{ $row['url'] }}" style="font-weight:600;color:#2563eb;text-decoration:none">{{ $row['title'] ?? '—' }}</a>
                                @else
                                    <span style="font-weight:600;color:#111827">{{ $row['title'] ?? '—' }}</span>
                                @endif
                            </td>
                            @if ($rowType === 'reviewed')
                                <td style="padding:0.65rem 0.5rem;color:#4b5563">{{ $row['reviewer'] ?? '—' }}</td>
                                <td style="padding:0.65rem 0.5rem;color:#4b5563">{{ $row['reviewed_at'] ?? '—' }}</td>
                            @else
                                <td style="padding:0.65rem 0.5rem;color:#4b5563">{{ $row['project'] ?? '—' }}</td>
                                <td style="padding:0.65rem 0.5rem;color:#4b5563">{{ $row['updated_at'] ?? '—' }}</td>
                            @endif
                            <td style="padding:0.65rem 0 0.65rem 0.5rem">
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
</div>
