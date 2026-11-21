
const graficos = new Map();
let version = 0;

const colores = [
    '#008CFF', // Azul intenso
    '#00C878', // Verde brillante
    '#FFAD0D', // Naranja
    '#8454FF', // Violeta
    '#FF4D72', // Rosa coral
    '#00C9D7'  // Turquesa
];

function moneda(valor) {
    return 'S/ ' + Number(valor).toLocaleString('es-PE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function configurarGrafico(tipo, serie) {
    const labels = serie.etiquetas || [];
    const valores = serie.valores || [];

    if (tipo === 'ventas') {
        return {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Cobros netos',
                    data: valores,
                    borderColor: colores[0],
                    backgroundColor: 'rgba(0,140,255,0.18)',
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: colores[0],
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: c => moneda(c.parsed.y)
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: valor => moneda(valor)
                        }
                    }
                }
            }
        };
    }

    if (tipo === 'productos') {
        return {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Unidades cobradas',
                    data: valores,
                    backgroundColor: labels.map(
                        (_, i) => colores[i % colores.length]
                    ),
                    borderRadius: 3,
                    maxBarThickness: 32
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: c => Number(c.parsed.x)
                                .toLocaleString('es-PE', {
                                    maximumFractionDigits: 4
                                }) + ' unidades'
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        };
    }

    if (tipo === 'medios') {
        return {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: valores,
                    backgroundColor: labels.map(
                        (_, i) => colores[i % colores.length]
                    ),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 12
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: c => c.label + ': ' +
                                moneda(c.parsed)
                        }
                    }
                }
            }
        };
    }

    return null;
}

async function mostrarDashboard() {
    const canvases = document.querySelectorAll(
        '[data-dashboard-chart]'
    );

    if (!canvases.length) return;

    const actual = ++version;

    try {
        const { default: Chart } = await import('./charts.js');

        if (actual !== version) return;

        for (const canvas of canvases) {
            if (!canvas.isConnected || graficos.has(canvas)) {
                continue;
            }

            const serie = JSON.parse(
                canvas.dataset.serie || '{}'
            );

            const configuracion = configurarGrafico(
                canvas.dataset.dashboardChart,
                serie
            );

            if (!configuracion) continue;

            const grafico = new Chart(
                canvas,
                configuracion
            );

            graficos.set(canvas, grafico);
        }

    } catch (error) {
        console.error(
            'Error al mostrar los gráficos del Dashboard.',
            error
        );
    }
}

function destruirDashboard() {
    version++;

    for (const grafico of graficos.values()) {
        grafico.destroy();
    }

    graficos.clear();
}

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        mostrarDashboard,
        { once: true }
    );
} else {
    void mostrarDashboard();
}

document.addEventListener(
    'livewire:navigated',
    mostrarDashboard
);

document.addEventListener(
    'livewire:navigating',
    destruirDashboard
);
