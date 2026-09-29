import { initExport } from './export.js';
import { getLanguage, initI18n, isDefaultProjectName, onLanguage, setLanguage, t, translateMessage } from './i18n.js';
import { initInspector, renderInspector } from './inspector.js';
import { initMedia, loadLibrary, renderLibrary } from './media.js';
import { initPreview, pause, togglePlay } from './preview.js';
import {
    canRedo,
    canUndo,
    contentEnd,
    deleteSnapshot,
    downloadProjectFile,
    getProject,
    initProject,
    loadSnapshot,
    newProject,
    onSelection,
    readSnapshots,
    redo,
    removeSelected,
    replaceProject,
    resetProject,
    saveSnapshot,
    splitSelectedVideo,
    subscribe,
    undo,
} from './project.js';
import { initTimeline, renderTimeline } from './timeline.js';
import { initUpload, limitLabel, setUploadRules } from './upload.js';
import { confirmAction, toast } from './util.js';

let healthState = null;

document.addEventListener('DOMContentLoaded', () => {
    initI18n();
    initProject();
    initMedia();
    initUpload();
    initPreview();
    initTimeline();
    initInspector();
    initExport();
    bindChrome();
    bindMobileSheets();
    subscribe(refresh);
    onSelection(updateSelectionLabel);
    onLanguage(applyLocalized);
    document.querySelectorAll('[data-lang]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.lang !== getLanguage()) setLanguage(button.dataset.lang);
        });
    });
    const current = getProject();
    if (isDefaultProjectName(current.name) && current.name !== t('untitled')) {
        current.name = t('untitled');
        localStorage.setItem('ve.project.v1', JSON.stringify(current));
    }
    refresh();
    loadLibrary().catch((error) => toast(translateMessage(error.message) || t('err.loadMedia'), 'danger'));
    checkHealth();
});

function bindMobileSheets() {
    const dock = document.querySelector('.mobile-dock');
    const backdrop = document.getElementById('sheetBackdrop');
    if (!dock || !backdrop) return;
    const apply = (name) => {
        if (name) document.body.dataset.sheet = name;
        else delete document.body.dataset.sheet;
        dock.querySelectorAll('[data-sheet]').forEach((button) => {
            const on = button.dataset.sheet === name;
            button.classList.toggle('active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        backdrop.hidden = !name;
    };
    dock.addEventListener('click', (event) => {
        const button = event.target.closest('[data-sheet]');
        if (!button) return;
        apply(document.body.dataset.sheet === button.dataset.sheet ? '' : button.dataset.sheet);
    });
    backdrop.addEventListener('click', () => apply(''));
}

function bindChrome() {
    const name = document.getElementById('projectName');
    name.addEventListener('change', () => {
        const value = name.value.trim().slice(0, 80) || t('untitled');
        replaceName(value);
    });
    document.getElementById('btnUndo').addEventListener('click', undo);
    document.getElementById('btnRedo').addEventListener('click', redo);
    document.getElementById('btnNew').addEventListener('click', async () => {
        if (await confirmAction(t('newConfirm'))) {
            newProject();
            toast(t('newDone'), 'success');
        }
    });
    document.getElementById('btnReset').addEventListener('click', async () => {
        if (await confirmAction(t('resetConfirm'))) {
            resetProject();
            toast(t('resetDone'), 'success');
        }
    });
    document.getElementById('btnSave').addEventListener('click', () => {
        saveSnapshot();
        renderSnapshots();
        toast(t('saved'), 'success');
    });
    document.getElementById('btnDownloadProject').addEventListener('click', downloadProjectFile);
    document.getElementById('btnLoad').addEventListener('click', () => {
        renderSnapshots();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('loadModal')).show();
    });
    document.getElementById('btnImportProject').addEventListener('click', () => {
        document.getElementById('projectFileInput').click();
    });
    document.getElementById('projectFileInput').addEventListener('change', async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        try {
            const data = JSON.parse(await file.text());
            replaceProject(data.project && typeof data.project === 'object' ? data.project : data);
            toast(t('loaded'), 'success');
            bootstrap.Modal.getInstance(document.getElementById('loadModal'))?.hide();
        } catch {
            toast(t('err.invalid'), 'danger');
        }
    });
    document.getElementById('snapshotList').addEventListener('click', (event) => {
        const button = event.target.closest('[data-snapshot]');
        if (!button) return;
        const id = button.dataset.snapshot;
        if (button.dataset.action === 'load' && loadSnapshot(id)) {
            toast(t('loaded'), 'success');
            bootstrap.Modal.getInstance(document.getElementById('loadModal'))?.hide();
        }
        if (button.dataset.action === 'delete') {
            deleteSnapshot(id);
            renderSnapshots();
        }
    });
    document.getElementById('exportMode').addEventListener('change', (event) => {
        const mode = event.target.value === 'high' ? 'high' : 'fast';
        const project = getProject();
        if (project.exportMode === mode) return;
        project.exportMode = mode;
        saveSnapshotQuiet();
    });
    document.getElementById('btnSplit').addEventListener('click', () => {
        const message = splitSelectedVideo();
        if (message) toast(t(message), 'danger');
    });
    document.getElementById('btnDeleteClip').addEventListener('click', () => {
        if (!removeSelected()) toast(t('selectToDelete'), 'danger');
    });
    window.addEventListener('keydown', onKeyDown);
    setLimitHint(t('checkingLimits'));
}

function applyLocalized() {
    const project = getProject();
    if (isDefaultProjectName(project.name) && project.name !== t('untitled')) {
        project.name = t('untitled');
        localStorage.setItem('ve.project.v1', JSON.stringify(project));
    }
    refresh();
    renderLibrary();
    renderSnapshots();
    setLimitHint(healthState ? limitLabel() : t('checkingLimits'));
    paintHealth();
}

function replaceName(value) {
    const project = getProject();
    project.name = value;
    document.getElementById('projectName').value = value;
    localStorage.setItem('ve.project.v1', JSON.stringify(project));
}

function saveSnapshotQuiet() {
    localStorage.setItem('ve.project.v1', JSON.stringify(getProject()));
    refresh();
}

function refresh() {
    const project = getProject();
    const name = document.getElementById('projectName');
    if (document.activeElement !== name) name.value = project.name;
    const mode = document.getElementById('exportMode');
    if (document.activeElement !== mode) mode.value = project.exportMode;
    document.getElementById('btnUndo').disabled = !canUndo();
    document.getElementById('btnRedo').disabled = !canRedo();
    renderTimeline();
    renderInspector();
    updateSelectionLabel();
    document.getElementById('projectDuration').textContent = contentEnd(project) > 0
        ? t('seconds', { n: contentEnd(project).toFixed(1) })
        : t('empty');
}

function updateSelectionLabel() {
    const label = document.getElementById('selectionLabel');
    const selected = document.querySelector('.clip.selected');
    label.textContent = selected ? (selected.getAttribute('title') || t('selected')) : t('noSelection');
}

function renderSnapshots() {
    const host = document.getElementById('snapshotList');
    const snapshots = readSnapshots();
    if (snapshots.length === 0) {
        host.innerHTML = `<p class="hint">${escape(t('noSnapshots'))}</p>`;
        return;
    }
    host.innerHTML = snapshots.map((item) => {
        const when = new Date(item.savedAt).toLocaleString();
        return `<div class="snapshot-row">
            <div><strong>${escape(item.name || t('untitled'))}</strong><small>${escape(when)}</small></div>
            <div class="snapshot-actions">
                <button type="button" class="btn btn-sm btn-primary" data-snapshot="${escape(item.id)}" data-action="load">${escape(t('loadAction'))}</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-snapshot="${escape(item.id)}" data-action="delete">${escape(t('deleteAction'))}</button>
            </div>
        </div>`;
    }).join('');
}

function escape(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[char]));
}

function onKeyDown(event) {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName || '');
    const key = event.key.toLowerCase();
    if ((event.ctrlKey || event.metaKey) && key === 's') {
        event.preventDefault();
        saveSnapshot();
        toast(t('saved'), 'success');
        return;
    }
    if ((event.ctrlKey || event.metaKey) && key === 'z') {
        event.preventDefault();
        if (event.shiftKey) redo();
        else undo();
        return;
    }
    if ((event.ctrlKey || event.metaKey) && key === 'y') {
        event.preventDefault();
        redo();
        return;
    }
    if (typing || document.activeElement?.tagName === 'BUTTON') return;
    if (event.key === ' ') {
        event.preventDefault();
        togglePlay();
    } else if (event.key === 'Home') {
        pause();
        document.getElementById('btnGoStart').click();
    } else if (event.key === 'End') {
        pause();
        document.getElementById('btnGoEnd').click();
    } else if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        removeSelected();
    } else if (key === 's') {
        const message = splitSelectedVideo();
        if (message) toast(t(message), 'danger');
    }
}

async function checkHealth() {
    const badge = document.getElementById('ffmpegStatus');
    try {
        const response = await fetch('api/health.php');
        const data = await response.json();
        healthState = data;
        setUploadRules(data);
        paintHealth();
        if (!data.ffmpeg || !data.ffprobe) {
            toast(t('ffmpegToast'), 'danger');
        } else if (!data.writable) {
            toast(t('err.writable'), 'danger');
        }
    } catch {
        healthState = { ffmpeg: false, ffprobe: false, writable: false };
        paintHealth();
    }
}

function setLimitHint(text) {
    const zone = document.getElementById('dropzone');
    if (zone) zone.title = text;
}

function paintHealth() {
    const badge = document.getElementById('ffmpegStatus');
    const data = healthState || {};
    const ready = Boolean(data.ffmpeg && data.ffprobe && data.writable);
    badge.textContent = ready ? t('ffmpegReady') : (healthState ? t('ffmpegMissing') : t('ffmpegChecking'));
    badge.classList.toggle('ready', ready);
    badge.classList.toggle('missing', Boolean(healthState) && !ready);
    setLimitHint(healthState ? limitLabel() : t('checkingLimits'));
}
