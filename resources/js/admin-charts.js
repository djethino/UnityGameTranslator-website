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

        // 🔴 **The ceiling is only drawn when it fits the readings.**
        //
        // It used to be plotted unconditionally, as a flat line, so the headroom could be seen at
        // a glance. That works while the two are the same order of magnitude — and the ceiling is
        // deliberately roomy (1000 streams) while real use sits at a handful. Chart.js scales the
        // axis on every dataset, so one line at 1000 flattened the actual readings onto the
        // baseline: the chart showed a red line and, underneath it, nothing legible. The very
        // question it exists to answer — how does concurrency MOVE — had no answer left.
        //
        // So: the ceiling joins the plot once the peak reaches a fifth of it, where the comparison
        // is the interesting part. Below that, the axis follows the data and the headroom is said
        // in words under the title, which is where it reads better anyway. The cards above already
        // give the exact ratio ("Peak SSE streams  3 / 1000").
        const observedPeak = Math.max(0, ...data.peakSessions, ...data.peakStreams);
        const ceilingIsInRange = data.streamCeiling && observedPeak >= data.streamCeiling * 0.2;

        if (ceilingIsInRange) {
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
                    y: {
                        ...lineBarOptions.scales.y,
                        // Start at zero: a truncated axis would make a quiet day
                        // look like a saturated one
                        beginAtZero: true,
                        // ⚠ Room above the highest reading so the curve is not glued to the top,
                        // and a floor of 4 so a run of zeroes still draws a readable grid instead
                        // of one squashed band. Ignored by Chart.js when the ceiling dataset is
                        // present, which is exactly what should happen then.
                        suggestedMax: Math.max(4, Math.ceil(observedPeak * 1.4)),
                        // Half a session does not exist.
                        ticks: { ...lineBarOptions.scales.y.ticks, precision: 0 }
                    }
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
