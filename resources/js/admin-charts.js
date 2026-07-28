import Chart from 'chart.js/auto';

const data = window.__analyticsData;
if (!data) {
    console.error('Analytics data not found');
} else {
    const colors = {
        purple: 'rgb(168, 85, 247)',
        blue: 'rgb(59, 130, 246)',
        green: 'rgb(34, 197, 94)',
        yellow: 'rgb(234, 179, 8)',
        red: 'rgb(239, 68, 68)',
        orange: 'rgb(249, 115, 22)',
        cyan: 'rgb(6, 182, 212)',
    };

    const lineBarOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: { color: '#9ca3af' }
            }
        },
        scales: {
            x: {
                ticks: { color: '#9ca3af' },
                grid: { color: 'rgba(75, 85, 99, 0.3)' }
            },
            y: {
                ticks: { color: '#9ca3af' },
                grid: { color: 'rgba(75, 85, 99, 0.3)' }
            }
        }
    };

    const doughnutOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: '#9ca3af' }
            }
        }
    };

    // Traffic Chart
    if (data.hasTrafficData) {
        new Chart(document.getElementById('trafficChart'), {
            type: 'line',
            data: {
                labels: data.chartLabels,
                datasets: [
                    {
                        label: 'Page Views',
                        data: data.chartPageViews,
                        borderColor: colors.purple,
                        backgroundColor: 'rgba(168, 85, 247, 0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        // Sum of each day's unique count, not unique over the
                        // range — the label says so, as does the card above
                        label: 'Daily Visitors',
                        data: data.chartVisitors,
                        borderColor: colors.blue,
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: lineBarOptions
        });
    }

    // Downloads Chart
    if (data.hasDownloadData) {
        new Chart(document.getElementById('downloadsChart'), {
            type: 'bar',
            data: {
                labels: data.chartLabels,
                datasets: [{
                    label: 'Downloads',
                    data: data.chartDownloads,
                    backgroundColor: colors.green,
                }]
            },
            options: lineBarOptions
        });
    }

    // Concurrency Chart — daily peaks with the ceiling drawn in.
    // The ceiling matters more than the peaks: the question this answers is
    // "how much headroom is left", not "how many people edited".
    if (data.hasConcurrencyData) {
        const datasets = [
            {
                label: 'Peak sessions',
                data: data.peakSessions,
                borderColor: colors.cyan,
                backgroundColor: 'rgba(6, 182, 212, 0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'Peak SSE streams',
                data: data.peakStreams,
                borderColor: colors.purple,
                backgroundColor: 'rgba(168, 85, 247, 0.1)',
                fill: true,
                tension: 0.3
            }
        ];

        if (data.streamCeiling) {
            datasets.push({
                label: `Stream ceiling (${data.streamCeiling})`,
                data: data.chartLabels.map(() => data.streamCeiling),
                borderColor: colors.red,
                borderDash: [6, 4],
                borderWidth: 1.5,
                pointRadius: 0,
                fill: false
            });
        }

        new Chart(document.getElementById('concurrencyChart'), {
            type: 'line',
            data: { labels: data.chartLabels, datasets },
            options: {
                ...lineBarOptions,
                scales: {
                    ...lineBarOptions.scales,
                    // Start at zero: a truncated axis would make a quiet day
                    // look like a saturated one
                    y: { ...lineBarOptions.scales.y, beginAtZero: true }
                }
            }
        });
    }

    // Devices Chart
    if (data.hasDeviceData) {
        new Chart(document.getElementById('devicesChart'), {
            type: 'doughnut',
            data: {
                labels: ['Desktop', 'Mobile', 'Tablet'],
                datasets: [{
                    data: [data.devices.desktop, data.devices.mobile, data.devices.tablet],
                    backgroundColor: [colors.blue, colors.green, colors.orange]
                }]
            },
            options: doughnutOptions
        });
    }

    // Browsers Chart
    if (data.hasBrowserData) {
        new Chart(document.getElementById('browsersChart'), {
            type: 'doughnut',
            data: {
                labels: data.browserLabels,
                datasets: [{
                    data: data.browserValues,
                    backgroundColor: [
                        colors.blue, colors.orange, colors.green,
                        colors.red, colors.purple, colors.cyan
                    ]
                }]
            },
            options: doughnutOptions
        });
    }
}
