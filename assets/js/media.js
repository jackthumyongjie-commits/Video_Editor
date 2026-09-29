import {
    addAudioClip,
    addImageClip,
    addTextClip,
    addVideoClip,
    getProject,
    transact,
} from './project.js';
import { onLanguage, t, translateMessage } from './i18n.js';
import { confirmAction, escapeHtml, formatBytes, formatTime, toast } from './util.js';

let library = [];
let filter = 'all';

export function getMedia(id) {
    return library.find((item) => item.id === id) || null;
}

export function visualUrl(media) {
    if (!media) return null;
    if (media.kind === 'image') return media.url;
    if (media.previewAvailable && media.previewUrl) return media.previewUrl;
    if (media.browserPlayable) return media.url;
    return null;
}

export async function loadLibrary() {
    const response = await fetch('api/media.php');
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'Could not load media.');
    }
    library = Array.isArray(data.media) ? data.media : [];
    renderLibrary();
}

export function initMedia() {
    document.querySelectorAll('[data-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            filter = button.dataset.filter || 'all';
            document.querySelectorAll('[data-filter]').forEach((item) => {
                item.classList.toggle('active', item === button);
            });
            renderLibrary();
        });
    });
    document.getElementById('btnAddText').addEventListener('click', () => addTextClip());
    document.getElementById('libraryList').addEventListener('click', onLibraryClick);
    document.getElementById('libraryList').addEventListener('dragstart', onDragStart);
    onLanguage(renderLibrary);
}

async function onLibraryClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const card = button.closest('[data-id]');
    const media = getMedia(card?.dataset.id || '');
    if (!media) return;
    if (button.dataset.action === 'add') {
        addMediaToTimeline(media);
        toast(t('added', { name: media.originalName }), 'success');
    }
    if (button.dataset.action === 'delete') {
        await deleteMedia(media);
    }
}

function onDragStart(event) {
    const card = event.target.closest('[data-id]');
    if (!card) return;
    event.dataTransfer.setData('application/x-media-id', card.dataset.id);
    event.dataTransfer.effectAllowed = 'copy';
}

export function addMediaToTimeline(media, atTime = null) {
    if (media.kind === 'video') addVideoClip(media, atTime);
    if (media.kind === 'image') addImageClip(media, atTime);
    if (media.kind === 'audio') addAudioClip(media, atTime);
}

async function deleteMedia(media) {
    const accepted = await confirmAction(t('deleteConfirm', { name: media.originalName }));
    if (!accepted) return;
    const response = await fetch('api/media.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: media.id }),
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
        toast(translateMessage(data.message) || t('err.deleteMedia'), 'danger');
        return;
    }
    transact((draft) => {
        draft.clips = draft.clips.filter((clip) => clip.mediaId !== media.id);
        draft.images = draft.images.filter((image) => image.mediaId !== media.id);
        draft.audios = draft.audios.filter((audio) => audio.mediaId !== media.id);
    });
    await loadLibrary();
    toast(t('deleted'), 'success');
}

export function renderLibrary() {
    const list = document.getElementById('libraryList');
    if (!list) return;
    const visible = library.filter((item) => filter === 'all' || item.kind === filter);
    if (visible.length === 0) {
        list.innerHTML = `<div class="empty-library"><i class="fa-solid fa-photo-film"></i><p>${library.length === 0 ? escapeHtml(t('emptyLibrary')) : escapeHtml(t('emptyCategory'))}</p></div>`;
        return;
    }
    list.innerHTML = visible.map((item) => mediaCard(item)).join('');
}

function mediaCard(item) {
    const thumb = item.thumbUrl
        ? `<img src="${escapeHtml(item.thumbUrl)}" alt="">`
        : `<i class="fa-solid ${item.kind === 'audio' ? 'fa-music' : item.kind === 'image' ? 'fa-image' : 'fa-film'}"></i>`;
    const meta = item.kind === 'image'
        ? `${item.width}×${item.height}`
        : formatTime(item.duration);
    const warn = item.kind === 'video' && !item.browserPlayable && !item.previewAvailable
        ? `<span class="media-warn" title="${escapeHtml(t('noPreviewTitle'))}">${escapeHtml(t('noPreview'))}</span>`
        : '';
    return `<article class="media-card kind-${escapeHtml(item.kind)}" draggable="true" data-id="${escapeHtml(item.id)}">
        <div class="media-thumb">${thumb}</div>
        <div class="media-copy">
            <strong title="${escapeHtml(item.originalName)}">${escapeHtml(item.originalName)}</strong>
            <small>${escapeHtml(meta)} · ${escapeHtml(formatBytes(item.size))} ${warn}</small>
            <div class="media-actions">
                <button type="button" class="btn btn-sm btn-primary" data-action="add"><i class="fa-solid fa-plus"></i> ${escapeHtml(t('addToTimeline'))}</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-action="delete" title="${escapeHtml(t('deleteMedia'))}"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
    </article>`;
}

export function projectUsesMissingMedia() {
    const project = getProject();
    const missing = [];
    const check = (item) => {
        if (!getMedia(item.mediaId)) missing.push(item.name || 'Media');
    };
    project.clips.forEach(check);
    project.images.forEach(check);
    project.audios.forEach(check);
    return missing;
}
