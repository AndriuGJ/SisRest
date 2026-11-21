
let grafico = null;
let version = 0;

async function mostrarDashboard() {
    const canvas = document.querySelector(
        '[data-dashboard-ventas]'
    );

    if (!canvas) return;

    if (grafico?.canvas === canvas) return;

    const actual = ++version;

    try {
        // Cargar Chart.js solamente cuando se necesita.
        const { default: Chart } = await import('./charts.js');

        if (actual !== version || !canvas.isConnected) {
            return;
        }

        grafico?.destroy();

        const serie = JSON.parse(
            canvas.dataset.serie || '{}'
        );

        grafico = new Chart(canvas, {
            type: 'line',

            data: {
                labels: serie.etiquetas || [],
                datasets: [{
                    label: 'Cobros netos',
                    data: serie.valores || [],
                    borderColor: '#2478A0',
                    backgroundColor: 'rgba(36, 120, 160, 0.10)',
                    borderWidth: 2,
                    pointRadius: 4,
                    tension: 0.3,
                    fill: true
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        callbacks: {
                            label(context) {
                                return 'S/ ' + Number(
                                    context.parsed.y
                                ).toLocaleString('es-PE', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                    }
                },

                scales: {
                    y: {
                        ticks: {
                            callback(value) {
                                return 'S/ ' + value;
                            }
                        }
                    }
                }
            }
        });

    } catch (error) {
        console.error(
            'No se pudo mostrar el gráfico del Dashboard.',
            error
        );
    }
}

function destruirDashboard() {
    version++;

    grafico?.destroy();
    grafico = null;
}

// Carga inicial.
if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        mostrarDashboard,
        { once: true }
    );
} else {
    void mostrarDashboard();
}

// Compatible con la navegación de Livewire.
document.addEventListener(
    'livewire:navigated',
    mostrarDashboard
);

document.addEventListener(
    'livewire:navigating',
    destruirDashboard
);
