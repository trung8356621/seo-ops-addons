import ApexCharts from 'apexcharts';

const CHART_ROOT_ID = 'performance-hub-gsc-chart';
const PAYLOAD_ID = 'gsc-chart-payload';
const ERROR_ID = 'gsc-chart-error';
const REFRESH_EVENT = 'performance-hub-gsc-chart-refresh';

let chartInstance = null;
let bootstrapped = false;
let pendingRefresh = null;
let lastPayloadSignature = '';

function showError(message) {
    const el = document.getElementById(ERROR_ID);
    if (! el) {
        return;
    }

    el.hidden = message === '';
    el.textContent = message;
}

function readPayload() {
    const node = document.getElementById(PAYLOAD_ID)
        || document.querySelector('[data-gsc-chart-payload]');

    if (! node) {
        return null;
    }

    const raw = (node.value || node.textContent || '').trim();
    if (raw === '') {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        showError('Chart payload JSON không hợp lệ.');
        console.error('[GSC chart] payload parse failed', error);

        return null;
    }
}

function payloadSignature(payload) {
    if (! payload) {
        return '';
    }

    return [
        payload.metric ?? '',
        payload.current_start ?? '',
        payload.current_end ?? '',
        Array.isArray(payload.current) ? payload.current.join(',') : '',
        Array.isArray(payload.labels) ? payload.labels.length : 0,
    ].join('|');
}

function destroyChart() {
    if (chartInstance) {
        try {
            chartInstance.destroy();
        } catch {
            // ignore stale ApexCharts destroy errors after Livewire morph
        }
        chartInstance = null;
    }

    const root = document.getElementById(CHART_ROOT_ID);
    if (root) {
        root.innerHTML = '';
    }
}

function formatValue(metric, value) {
    if (metric === 'ctr') {
        return `${Number(value).toFixed(2)}%`;
    }

    if (metric === 'position') {
        return Number(value).toFixed(1);
    }

    return Number(value).toLocaleString();
}

function buildOptions(payload) {
    const metric = payload.metric ?? 'clicks';
    const isLowerBetter = payload.is_lower_better === true;
    const isMonthly = payload.mode === 'monthly'
        || (Array.isArray(payload.previous) && payload.previous.length === 0);

    const series = [{
        name: payload.current_label ?? 'Current period',
        data: payload.current ?? [],
    }];

    if (! isMonthly) {
        series.push({
            name: payload.previous_label ?? 'Previous period',
            data: payload.previous ?? [],
        });
    }

    return {
        chart: {
            type: 'area',
            height: 280,
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: false },
            fontFamily: 'inherit',
            redrawOnParentResize: true,
            redrawOnWindowResize: true,
        },
        colors: isMonthly ? ['#059669'] : ['#059669', '#9ca3af'],
        stroke: { curve: 'smooth', width: isMonthly ? [2] : [2, 2] },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0.15,
                opacityFrom: 0.18,
                opacityTo: 0.02,
                stops: [0, 90, 100],
            },
        },
        dataLabels: { enabled: false },
        series,
        xaxis: {
            categories: payload.labels ?? [],
            labels: {
                style: { colors: '#6b7280', fontSize: '11px' },
                rotate: -45,
                hideOverlappingLabels: true,
                datetimeUTC: false,
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            reversed: isLowerBetter,
            labels: {
                style: { colors: '#6b7280', fontSize: '11px' },
                formatter: (value) => formatValue(metric, value),
            },
        },
        grid: {
            borderColor: '#e5e7eb',
            strokeDashArray: 4,
            padding: { left: 8, right: 8 },
        },
        legend: {
            show: series.length > 1,
            position: 'bottom',
            horizontalAlign: 'left',
            fontSize: '12px',
        },
        tooltip: {
            shared: true,
            intersect: false,
            y: {
                formatter: (value) => formatValue(metric, value),
            },
        },
        markers: {
            size: 0,
            hover: { size: 4 },
        },
    };
}

function renderChart(payload) {
    const root = document.getElementById(CHART_ROOT_ID);
    if (! root) {
        destroyChart();
        showError('');

        return;
    }

    if (! payload || payload.has_data !== true) {
        destroyChart();
        showError('');

        return;
    }

    const signature = payloadSignature(payload);
    const alreadyPainted = root.querySelector('.apexcharts-canvas, svg') !== null
        && signature === lastPayloadSignature
        && chartInstance !== null;

    if (alreadyPainted) {
        showError('');

        return;
    }

    destroyChart();
    showError('');

    try {
        chartInstance = new ApexCharts(root, buildOptions(payload));
        chartInstance.render()
            .then(() => {
                lastPayloadSignature = signature;
                window.requestAnimationFrame(() => {
                    try {
                        chartInstance?.resize?.();
                    } catch {
                        // ignore
                    }
                });

                if (! root.querySelector('.apexcharts-canvas, svg')) {
                    showError('Không render được biểu đồ GSC (ApexCharts trống).');
                }
            })
            .catch((error) => {
                console.error('[GSC chart] render failed', error);
                showError('Lỗi render biểu đồ GSC. Mở Console để xem chi tiết.');
            });
    } catch (error) {
        console.error('[GSC chart] init failed', error);
        showError('Không khởi tạo được biểu đồ GSC.');
    }
}

function updateFromDom() {
    const payload = readPayload();
    if (payload) {
        renderChart(payload);
    } else {
        destroyChart();
    }
}

function scheduleRefresh(delayMs = 0) {
    if (pendingRefresh !== null) {
        window.clearTimeout(pendingRefresh);
    }

    pendingRefresh = window.setTimeout(() => {
        pendingRefresh = null;
        window.requestAnimationFrame(updateFromDom);
    }, delayMs);
}

function bindLivewireHooks() {
    if (! window.Livewire || bootstrapped) {
        return;
    }

    bootstrapped = true;

    window.Livewire.hook('morph.updated', () => {
        lastPayloadSignature = '';
        scheduleRefresh(50);
    });

    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            lastPayloadSignature = '';
            scheduleRefresh(80);
        });
    });

    if (typeof window.Livewire.on === 'function') {
        window.Livewire.on(REFRESH_EVENT, () => {
            lastPayloadSignature = '';
            scheduleRefresh(80);
        });
    }
}

function observePayload() {
    const node = document.getElementById(PAYLOAD_ID);
    if (! node || typeof MutationObserver === 'undefined') {
        return;
    }

    const observer = new MutationObserver(() => {
        lastPayloadSignature = '';
        scheduleRefresh(30);
    });

    observer.observe(node, {
        characterData: true,
        childList: true,
        subtree: true,
        attributes: true,
    });
}

function boot() {
    updateFromDom();
    bindLivewireHooks();
    observePayload();

    // Late Livewire/Filament hydration.
    scheduleRefresh(120);
    scheduleRefresh(400);

    document.addEventListener(REFRESH_EVENT, () => {
        lastPayloadSignature = '';
        scheduleRefresh(0);
    });
    document.addEventListener('livewire:init', bindLivewireHooks);
    document.addEventListener('livewire:navigated', () => {
        lastPayloadSignature = '';
        scheduleRefresh(0);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

window.PerformanceHubGscChart = {
    update: renderChart,
    destroy: destroyChart,
    refresh: updateFromDom,
};
