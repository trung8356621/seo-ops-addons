import ApexCharts from 'apexcharts';

const CHART_ROOT_ID = 'performance-hub-gsc-chart';
const PAYLOAD_INPUT_ID = 'gsc-chart-payload';

let chartInstance = null;

function readPayload() {
    const input = document.getElementById(PAYLOAD_INPUT_ID);
    if (! input || ! input.value) {
        return null;
    }

    try {
        return JSON.parse(input.value);
    } catch {
        return null;
    }
}

function destroyChart() {
    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
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

    return {
        chart: {
            type: 'area',
            height: 280,
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: true, speed: 350, animateGradually: { enabled: false } },
            fontFamily: 'inherit',
        },
        colors: ['#059669', '#9ca3af'],
        stroke: { curve: 'smooth', width: [2, 2] },
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
        series: [
            {
                name: payload.current_label ?? 'Current period',
                data: payload.current ?? [],
            },
            {
                name: payload.previous_label ?? 'Previous period',
                data: payload.previous ?? [],
            },
        ],
        xaxis: {
            categories: payload.labels ?? [],
            labels: {
                style: { colors: '#6b7280', fontSize: '11px' },
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
            position: 'bottom',
            horizontalAlign: 'left',
            fontSize: '12px',
            markers: { radius: 12 },
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
        return;
    }

    destroyChart();

    if (! payload || payload.has_data !== true) {
        return;
    }

    chartInstance = new ApexCharts(root, buildOptions(payload));
    chartInstance.render();
}

function updateFromDom() {
    const payload = readPayload();
    if (payload) {
        renderChart(payload);
    }
}

function boot() {
    updateFromDom();

    if (window.Livewire) {
        window.Livewire.hook('commit', ({ succeed }) => {
            succeed(() => {
                window.requestAnimationFrame(updateFromDom);
            });
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

document.addEventListener('livewire:navigated', () => {
    window.requestAnimationFrame(updateFromDom);
});

window.PerformanceHubGscChart = {
    update: renderChart,
    destroy: destroyChart,
};
