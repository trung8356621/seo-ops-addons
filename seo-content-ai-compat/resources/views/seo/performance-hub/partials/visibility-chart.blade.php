@if (($chart['has_data'] ?? false) === true)
    <section class="performance-hub-panel">
        <div class="performance-hub-panel__head">
            <h2>{{ __('seo-content-ai::filament.performance_hub.chart_visibility') }}</h2>
            <p>{{ __('seo-content-ai::filament.performance_hub.chart_visibility_hint') }}</p>
        </div>

        <div class="performance-hub-chart performance-hub-chart--visibility" wire:ignore>
            <canvas id="performance-visibility-chart" height="120"></canvas>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const canvas = document.getElementById('performance-visibility-chart');
                if (! canvas || typeof window.Chart === 'undefined') {
                    return;
                }

                const labels = @json($chart['labels'] ?? []);
                const current = @json($chart['current'] ?? []);
                const previous = @json($chart['previous'] ?? []);

                new window.Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            {
                                label: @json(__('seo-content-ai::filament.performance_hub.chart_current_period')),
                                data: current,
                                borderColor: '#059669',
                                backgroundColor: 'rgba(5, 150, 105, 0.12)',
                                tension: 0.3,
                                fill: true,
                            },
                            {
                                label: @json(__('seo-content-ai::filament.performance_hub.chart_previous_period')),
                                data: previous,
                                borderColor: '#9ca3af',
                                backgroundColor: 'rgba(156, 163, 175, 0.08)',
                                tension: 0.3,
                                fill: true,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: { y: { beginAtZero: true } },
                    },
                });
            });
        </script>
    </section>
@endif
