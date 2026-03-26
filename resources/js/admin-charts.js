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
                        label: 'Unique Visitors',
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
