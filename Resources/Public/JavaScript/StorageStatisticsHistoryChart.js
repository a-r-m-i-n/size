(function () {
    'use strict';

    const canvas = document.querySelector('[data-size-history-chart]');
    if (!canvas || typeof window.Chart !== 'function') {
        return;
    }

    const dataElement = canvas.parentElement?.querySelector('.size-storage-history-chart-data');
    if (!dataElement?.textContent) {
        return;
    }

    let chartPayload;
    try {
        chartPayload = JSON.parse(dataElement.textContent);
    } catch (_error) {
        return;
    }

    const labels = Array.isArray(chartPayload.labels) ? chartPayload.labels : [];
    const datasets = Array.isArray(chartPayload.datasets) ? chartPayload.datasets : [];
    if (labels.length === 0 || datasets.length === 0) {
        return;
    }

    const totals = Array.isArray(chartPayload.totals) ? chartPayload.totals : [];
    const formattedTotals = Array.isArray(chartPayload.formattedTotals) ? chartPayload.formattedTotals : [];
    const formattedValues = chartPayload.formattedValues && typeof chartPayload.formattedValues === 'object'
        ? chartPayload.formattedValues
        : {};
    const limit = chartPayload.limit && typeof chartPayload.limit === 'object' ? chartPayload.limit : {};
    const locale = document.documentElement.lang || undefined;
    const numberFormatter = new Intl.NumberFormat(locale, {maximumFractionDigits: 1});
    const computedStyles = window.getComputedStyle(document.querySelector('.size-storage-module') || document.documentElement);

    const chartDatasets = datasets.map((dataset, index) => ({
        type: 'bar',
        label: dataset.label,
        data: Array.isArray(dataset.data) ? dataset.data : [],
        backgroundColor: resolveColor(dataset.backgroundColor),
        borderColor: resolveColor(dataset.backgroundColor),
        borderSkipped: false,
        borderRadius: index === datasets.length - 1 ? {topLeft: 8, topRight: 8} : 0,
        barPercentage: 0.76,
        categoryPercentage: 0.72,
        stack: 'storage',
    }));

    if (limit.enabled === true && Number.isFinite(limit.bytes)) {
        chartDatasets.push({
            type: 'line',
            label: limit.datasetLabel || 'Limit',
            data: labels.map(() => limit.bytes),
            borderColor: '#c25d13',
            borderWidth: 2,
            borderDash: [6, 4],
            pointRadius: 0,
            pointHoverRadius: 0,
            tension: 0,
            fill: false,
            order: -10,
            yAxisID: 'yLimit',
        });
    }

    const chart = new window.Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: chartDatasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            animation: {
                duration: 280,
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        boxHeight: 12,
                        usePointStyle: true,
                        padding: 18,
                        filter(legendItem, legendData) {
                            const dataset = legendData.datasets[legendItem.datasetIndex];
                            return dataset?.type !== 'line';
                        },
                    },
                },
                tooltip: {
                    filter(tooltipItem) {
                        return tooltipItem.dataset.type !== 'line';
                    },
                    callbacks: {
                        label(tooltipItem) {
                            const datasetIdentifier = datasets[tooltipItem.datasetIndex]?.identifier;
                            const currentValue = tooltipItem.raw;
                            const totalValue = totals[tooltipItem.dataIndex] || 0;
                            const share = totalValue > 0 ? (Number(currentValue) / totalValue * 100) : 0;
                            const fallbackLabel = formatBytesCompact(Number(currentValue));
                            const formattedValue = typeof formattedValues[datasetIdentifier]?.[tooltipItem.dataIndex] === 'string'
                                ? formattedValues[datasetIdentifier][tooltipItem.dataIndex]
                                : fallbackLabel;

                            return `${tooltipItem.dataset.label}: ${formattedValue} (${numberFormatter.format(share)}%)`;
                        },
                        footer(tooltipItems) {
                            const index = tooltipItems[0]?.dataIndex;
                            if (typeof index !== 'number') {
                                return '';
                            }

                            const lines = [`Total: ${formattedTotals[index] || formatBytesCompact(totals[index] || 0)}`];
                            if (limit.enabled === true && typeof limit.label === 'string' && limit.label !== '') {
                                lines.push(`${limit.datasetLabel || 'Limit'}: ${limit.label}`);
                            }

                            return lines;
                        },
                    },
                },
            },
            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false,
                    },
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback(value) {
                            return formatBytesCompact(Number(value));
                        },
                    },
                    grid: {
                        color: 'rgba(103, 114, 125, 0.18)',
                    },
                },
                yLimit: {
                    display: false,
                    beginAtZero: true,
                    min: 0,
                },
            },
        },
    });

    if (typeof ResizeObserver === 'function') {
        const observer = new ResizeObserver(() => chart.resize());
        observer.observe(canvas);
    }

    function formatBytesCompact(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '0 B';
        }

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let value = bytes;
        let unitIndex = 0;

        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex += 1;
        }

        const digits = value >= 100 || unitIndex === 0 ? 0 : 1;

        return `${value.toFixed(digits)} ${units[unitIndex]}`;
    }

    function resolveColor(colorValue) {
        if (typeof colorValue !== 'string') {
            return '#68727d';
        }

        const match = colorValue.match(/^var\((--[^)]+)\)$/);
        if (!match) {
            return colorValue;
        }

        const resolvedColor = computedStyles.getPropertyValue(match[1]).trim();

        return resolvedColor || '#68727d';
    }
})();
