document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('luziapi-monthly-sales-chart');

    if (!canvas || typeof window.Chart === 'undefined') {
        return;
    }

    const labels = JSON.parse(canvas.dataset.labels || '[]');
    const values = JSON.parse(canvas.dataset.values || '[]');
    const euroFormatter = new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR',
    });

    new window.Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Commandes validées',
                data: values,
                backgroundColor: '#d49a28',
                borderColor: '#a87313',
                borderWidth: 1,
                borderRadius: 5,
            }],
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => euroFormatter.format(context.parsed.y || 0),
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => euroFormatter.format(value),
                    },
                },
                x: { grid: { display: false } },
            },
        },
    });
});
