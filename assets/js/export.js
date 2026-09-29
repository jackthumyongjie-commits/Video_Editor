import { onLanguage, t, translateMessage } from './i18n.js';
import { projectUsesMissingMedia } from './media.js';
import { contentEnd, getProject } from './project.js';
import { formatTime, toast } from './util.js';

let pollTimer = 0;
let activeJob = '';
let lastProgress = null;

export function initExport() {
    onLanguage(() => {
        if (lastProgress) setProgress(lastProgress.progress, lastProgress.status, lastProgress.elapsed, lastProgress.error);
    });
    document.getElementById('btnExport').addEventListener('click', startExport);
    document.getElementById('exportDownload').addEventListener('click', () => {
        const link = document.getElementById('exportDownload');
        if (!link.dataset.href) return;
        const anchor = document.createElement('a');
        anchor.href = link.dataset.href;
        anchor.download = '';
        anchor.click();
    });
}

async function startExport() {
    const project = getProject();
    if (contentEnd(project) <= 0) {
        toast(t('exportNeedMedia'), 'danger');
        return;
    }
    const missing = projectUsesMissingMedia();
    if (missing.length > 0) {
        toast(t('exportMissing'), 'danger');
        return;
    }
    const button = document.getElementById('btnExport');
    button.disabled = true;
    showModal();
    setProgress(0, 'queued', 0, '');
    try {
        const response = await fetch('api/export.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project }),
        });
        const data = await response.json();
        if (!response.ok || !data.ok || !data.job) {
            throw new Error(data.message || 'Export failed.');
        }
        activeJob = data.job;
        poll();
    } catch (error) {
        setProgress(0, 'error', 0, error.message || 'Export failed.');
        button.disabled = false;
    }
}

async function poll() {
    window.clearTimeout(pollTimer);
    if (!activeJob) return;
    try {
        const response = await fetch(`api/export_status.php?job=${encodeURIComponent(activeJob)}`);
        const data = await response.json();
        if (response.status === 404) {
            throw new Error(data.message || 'Export job not found.');
        }
        const status = data.status || 'error';
        setProgress(Number(data.progress) || 0, status, Number(data.elapsed) || 0, data.error || data.message || '');
        if (status === 'done') {
            const download = document.getElementById('exportDownload');
            download.hidden = false;
            download.dataset.href = data.download || '';
            document.getElementById('btnExport').disabled = false;
            toast(t('exportDone'), 'success');
            return;
        }
        if (status === 'error') {
            document.getElementById('btnExport').disabled = false;
            return;
        }
        pollTimer = window.setTimeout(poll, 1000);
    } catch (error) {
        setProgress(0, 'error', 0, error.message || 'Export failed.');
        document.getElementById('btnExport').disabled = false;
    }
}

function showModal() {
    document.getElementById('exportDownload').hidden = true;
    document.getElementById('exportDownload').dataset.href = '';
    document.getElementById('exportError').textContent = '';
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('exportModal'));
    modal.show();
}

function setProgress(progress, status, elapsed, error) {
    lastProgress = { progress, status, elapsed, error };
    const safe = Math.max(0, Math.min(100, progress));
    const bar = document.getElementById('exportProgressBar');
    bar.style.width = `${safe}%`;
    bar.textContent = `${Math.round(safe)}%`;
    bar.classList.toggle('progress-bar-striped', status === 'running' || status === 'queued');
    bar.classList.toggle('progress-bar-animated', status === 'running');
    const labels = {
        queued: t('queued'),
        running: t('rendering'),
        done: t('complete'),
        error: t('failed'),
    };
    document.getElementById('exportStatusText').textContent = labels[status] || status;
    document.getElementById('exportElapsed').textContent = formatTime(elapsed).replace(/\.\d$/, '');
    const errorBox = document.getElementById('exportError');
    errorBox.textContent = status === 'error' ? (translateMessage(error) || t('err.export')) : '';
}
