export function clamp(value, min, max) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return min;
    }
    return Math.min(max, Math.max(min, number));
}

export function round3(value) {
    return Math.round(Number(value) * 1000) / 1000;
}

export function uid() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function formatTime(seconds) {
    const safe = Math.max(0, Number(seconds) || 0);
    const hours = Math.floor(safe / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const secs = safe % 60;
    const whole = Math.floor(secs);
    const tenth = Math.floor((secs - whole) * 10);
    const body = `${String(minutes).padStart(2, '0')}:${String(whole).padStart(2, '0')}.${tenth}`;
    return hours > 0 ? `${hours}:${body}` : body;
}

export function formatBytes(bytes) {
    const size = Number(bytes) || 0;
    if (size < 1024) return `${size} B`;
    if (size < 1048576) return `${(size / 1024).toFixed(1)} KB`;
    return `${(size / 1048576).toFixed(1)} MB`;
}

export function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[char]));
}

export function toast(message, type = 'info') {
    const host = document.getElementById('toastHost');
    if (!host || typeof bootstrap === 'undefined') {
        return;
    }
    const toast = document.createElement('div');
    toast.className = `toast align-items-center border-0 text-bg-${type === 'danger' ? 'danger' : type === 'success' ? 'success' : 'dark'}`;
    toast.setAttribute('role', 'status');
    toast.innerHTML = `<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
    toast.querySelector('.toast-body').textContent = message;
    host.appendChild(toast);
    const instance = new bootstrap.Toast(toast, { delay: 3600 });
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    instance.show();
}

export function confirmAction(message) {
    const modalEl = document.getElementById('confirmModal');
    const text = document.getElementById('confirmMessage');
    const ok = document.getElementById('confirmOk');
    if (!modalEl || typeof bootstrap === 'undefined') {
        return Promise.resolve(window.confirm(message));
    }
    text.textContent = message;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    return new Promise((resolve) => {
        let settled = false;
        const finish = (value) => {
            if (settled) return;
            settled = true;
            resolve(value);
        };
        const onOk = () => {
            finish(true);
            modal.hide();
        };
        const onHide = () => finish(false);
        ok.addEventListener('click', onOk, { once: true });
        modalEl.addEventListener('hidden.bs.modal', onHide, { once: true });
        modal.show();
    });
}

export const FONT_FAMILIES = ['Arial', 'Verdana', 'Georgia', 'Times New Roman', 'Courier New'];
export const SPEED_PRESETS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2, 4];
