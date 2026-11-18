import Swal from 'sweetalert2/dist/sweetalert2.esm.js';
import 'sweetalert2/dist/sweetalert2.min.css';

const icons = new Set(['success', 'error', 'warning', 'info', 'question']);
const defaults = {
    confirmButtonText: 'Aceptar',
    cancelButtonText: 'Cancelar',
    buttonsStyling: false,
    heightAuto: false,
    customClass: {
        popup: 'app-alert',
        confirmButton: 'app-alert-confirm',
        cancelButton: 'app-alert-cancel',
        actions: 'app-alert-actions',
    },
};
const seenFlash = new Set();
let confirming = false;

export function notificar({ text = '', type = 'success', toast = true } = {}) {
    return Swal.fire({
        ...defaults,
        text: String(text),
        icon: icons.has(type) ? type : 'info',
        toast,
        position: toast ? 'top-end' : 'center',
        showConfirmButton: !toast,
        showCloseButton: true,
        closeButtonAriaLabel: 'Cerrar alerta',
        timer: toast ? 4500 : undefined,
        timerProgressBar: toast,
        didOpen: toast ? (element) => {
            element.addEventListener('mouseenter', Swal.stopTimer);
            element.addEventListener('mouseleave', Swal.resumeTimer);
            element.addEventListener('focusin', Swal.stopTimer);
            element.addEventListener('focusout', Swal.resumeTimer);
        } : undefined,
    });
}

export function confirmar({ title = '¿Deseas continuar?', text = '', confirmText = 'Confirmar', danger = false } = {}) {
    return Swal.fire({
        ...defaults,
        titleText: String(title),
        text: String(text),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: String(confirmText),
        focusCancel: true,
        reverseButtons: true,
        customClass: {
            ...defaults.customClass,
            confirmButton: danger ? 'app-alert-confirm app-alert-danger' : 'app-alert-confirm',
        },
    });
}

async function confirmAction({ detail }) {
    if (confirming) return;
    const { componentId, action } = detail;
    if (typeof componentId !== 'string' || !/^[a-zA-Z][a-zA-Z0-9_]*$/.test(action)) return;

    confirming = true;
    try {
        const result = await confirmar(detail);
        if (!result.isConfirmed) return;
        const wire = window.Livewire?.find(componentId);
        if (!wire?.$el?.isConnected) return;
        await wire.$call(action);
    } catch {
        await notificar({ text: 'No se pudo completar la acción. Revisa la conexión e inténtalo de nuevo.', type: 'error', toast: false });
    } finally {
        confirming = false;
    }
}

function showFlash() {
    document.querySelectorAll('[data-app-alert]').forEach((element) => {
        const payload = JSON.parse(element.dataset.appAlert);
        element.remove();
        if (seenFlash.has(payload.id)) return;
        seenFlash.add(payload.id);
        void notificar(payload);
    });
}

// Vite ejecuta este módulo una vez; la navegación Livewire no añade listeners.
window.Alertas = Object.freeze({ notificar, confirmar });
window.addEventListener('app:alert', ({ detail }) => void notificar(detail));
window.addEventListener('app:confirm', confirmAction);
document.addEventListener('livewire:navigating', () => Swal.close());
document.addEventListener('livewire:navigated', showFlash);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', showFlash, { once: true });
} else {
    showFlash();
}
