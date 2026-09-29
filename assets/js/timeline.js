import { getMedia, addMediaToTimeline } from './media.js';
import {
    audioSpan,
    beginEdit,
    endEdit,
    getProject,
    getSelection,
    markDirty,
    onPlayhead,
    onSelection,
    setPlayhead,
    setSelection,
    timelineSpan,
    videoSpan,
} from './project.js';
import { onLanguage, t } from './i18n.js';
import { escapeHtml, formatTime, toast } from './util.js';

let pixelsPerSecond = Number(localStorage.getItem('ve.zoom') || 72);
let snapEnabled = true;
let drag = null;
let touchSeek = null;

export function initTimeline() {
    const zoom = document.getElementById('zoomSlider');
    zoom.value = String(pixelsPerSecond);
    zoom.addEventListener('input', () => {
        pixelsPerSecond = Number(zoom.value);
        localStorage.setItem('ve.zoom', String(pixelsPerSecond));
        document.getElementById('zoomLabel').textContent = t('pxPerSec', { n: pixelsPerSecond });
        renderTimeline();
    });
    document.getElementById('btnSnap').addEventListener('click', () => {
        snapEnabled = !snapEnabled;
        document.getElementById('btnSnap').classList.toggle('active', snapEnabled);
    });
    document.getElementById('btnSnap').classList.add('active');
    document.getElementById('timelineScroll').addEventListener('pointerdown', onPointerDown);
    document.getElementById('timelineScroll').addEventListener('pointermove', onPointerMove);
    document.getElementById('timelineScroll').addEventListener('pointerup', onPointerUp);
    document.getElementById('timelineScroll').addEventListener('pointercancel', onPointerUp);
    document.querySelectorAll('.track').forEach((track) => {
        track.addEventListener('dragover', (event) => event.preventDefault());
        track.addEventListener('drop', onDrop);
    });
    onPlayhead((time) => {
        positionPlayhead(time);
        if (isPlaybackRunning()) followPlayhead();
    });
    onSelection(updateSelectedClasses);
    document.getElementById('zoomLabel').textContent = t('pxPerSec', { n: pixelsPerSecond });
    onLanguage(() => {
        document.getElementById('zoomLabel').textContent = t('pxPerSec', { n: pixelsPerSecond });
    });
    renderTimeline();
}

function isPlaybackRunning() {
    return document.getElementById('btnPlay').classList.contains('active');
}

export function renderTimeline() {
    const project = getProject();
    const span = timelineSpan(project);
    const width = Math.max(span * pixelsPerSecond, document.getElementById('timelineScroll').clientWidth);
    const sheet = document.getElementById('timelineSheet');
    sheet.style.width = `${width}px`;
    renderRuler(span, width);
    fillTrack('trackClip', project.clips, 'clip', (clip) => ({
        start: clip.timelineStart,
        duration: videoSpan(clip),
        label: clip.name,
    }));
    fillTrack('trackImage', project.images, 'image', (item) => ({
        start: item.timelineStart,
        duration: item.duration,
        label: item.name,
    }));
    fillTrack('trackText', project.texts, 'text', (item) => ({
        start: item.timelineStart,
        duration: item.duration,
        label: item.content,
    }));
    fillTrack('trackAudio', project.audios, 'audio', (item) => ({
        start: item.timelineStart,
        duration: audioSpan(item),
        label: item.name,
    }));
    positionPlayhead(project.playhead);
    updateSelectedClasses();
}

function renderRuler(span, width) {
    const ruler = document.getElementById('ruler');
    ruler.style.width = `${width}px`;
    const step = pixelsPerSecond >= 120 ? 1 : pixelsPerSecond >= 60 ? 2 : pixelsPerSecond >= 30 ? 5 : 10;
    let html = '';
    for (let second = 0; second <= span + step; second += step) {
        html += `<span class="tick" style="left:${second * pixelsPerSecond}px">${formatTime(second).replace(/\.\d$/, '')}</span>`;
    }
    ruler.innerHTML = html;
}

function fillTrack(id, items, type, describe) {
    const track = document.getElementById(id);
    track.innerHTML = items.map((item) => {
        const box = describe(item);
        const missing = type !== 'text' && !getMedia(item.mediaId);
        const left = Math.max(0, box.start) * pixelsPerSecond;
        const width = Math.max(8, box.duration * pixelsPerSecond);
        return `<div class="clip clip-${type}${missing ? ' missing' : ''}" data-type="${type}" data-id="${escapeHtml(item.id)}" style="left:${left}px;width:${width}px" title="${escapeHtml(box.label)}">
            <span>${escapeHtml(box.label)}</span>
        </div>`;
    }).join('');
}

function updateSelectedClasses() {
    const selected = getSelection();
    document.querySelectorAll('.clip').forEach((element) => {
        element.classList.toggle('selected', element.dataset.id === selected.id && element.dataset.type === selected.type);
    });
}

function positionPlayhead(time) {
    document.getElementById('playhead').style.left = `${time * pixelsPerSecond}px`;
}

function followPlayhead() {
    const scroll = document.getElementById('timelineScroll');
    const x = getProject().playhead * pixelsPerSecond;
    const viewLeft = scroll.scrollLeft;
    const viewRight = viewLeft + scroll.clientWidth;
    if (x < viewLeft + 48 || x > viewRight - 48) {
        scroll.scrollLeft = Math.max(0, x - scroll.clientWidth * 0.35);
    }
}

function onDrop(event) {
    event.preventDefault();
    const id = event.dataTransfer.getData('application/x-media-id');
    const media = getMedia(id);
    if (!media) return;
    const expected = { video: 'clip', image: 'image', audio: 'audio' }[media.kind];
    if (expected !== event.currentTarget.dataset.track) {
        toast(t('dropTrack'), 'danger');
        return;
    }
    addMediaToTimeline(media, timeAt(event.clientX));
}

function onPointerDown(event) {
    if (event.target.closest('.clip')) {
        event.preventDefault();
        startClipDrag(event);
        return;
    }
    const scrub = event.target.closest('.playhead-head') || event.target.closest('#ruler');
    const track = event.target.closest('.track');
    if (!scrub && !track) return;
    if (event.pointerType === 'touch' && track && !scrub) {
        touchSeek = { x: event.clientX, y: event.clientY };
        return;
    }
    event.preventDefault();
    seekFromPointer(event);
    drag = { mode: 'playhead' };
    event.currentTarget.setPointerCapture(event.pointerId);
}

function startClipDrag(event) {
    const element = event.target.closest('.clip');
    const type = element.dataset.type;
    const id = element.dataset.id;
    const record = findRecord(type, id);
    if (!record) return;
    setSelection({ type, id });
    const rect = element.getBoundingClientRect();
    const edge = event.pointerType === 'touch' ? 22 : 8;
    let mode = 'move';
    if (event.clientX - rect.left <= edge) mode = 'trim-start';
    else if (rect.right - event.clientX <= edge) mode = 'trim-end';
    beginEdit();
    drag = {
        mode,
        type,
        id,
        startX: event.clientX,
        origin: structuredClone(record),
    };
    event.stopPropagation();
    event.currentTarget.setPointerCapture(event.pointerId);
}

function onPointerMove(event) {
    if (!drag) return;
    if (drag.mode === 'playhead') {
        seekFromPointer(event);
        return;
    }
    const dt = (event.clientX - drag.startX) / pixelsPerSecond;
    const record = findRecord(drag.type, drag.id);
    if (!record) return;
    applyDrag(record, drag.origin, dt, drag.mode, drag.type);
    markDirty();
    moveElement(drag.type, drag.id, record);
}

function onPointerUp(event) {
    if (touchSeek && !drag) {
        const dx = Math.abs(event.clientX - touchSeek.x);
        const dy = Math.abs(event.clientY - touchSeek.y);
        touchSeek = null;
        if (dx < 8 && dy < 8) seekFromPointer(event);
    }
    touchSeek = null;
    if (!drag) return;
    const wasClip = drag.mode !== 'playhead';
    drag = null;
    if (wasClip) endEdit();
}

function moveElement(type, id, record) {
    const element = document.querySelector(`.clip[data-type="${type}"][data-id="${id}"]`);
    if (!element) return;
    const duration = type === 'clip' ? videoSpan(record) : type === 'audio' ? audioSpan(record) : record.duration;
    element.style.left = `${Math.max(0, record.timelineStart) * pixelsPerSecond}px`;
    element.style.width = `${Math.max(8, duration * pixelsPerSecond)}px`;
}

function applyDrag(item, origin, dt, mode, type) {
    if (mode === 'move') {
        item.timelineStart = snap(Math.max(0, origin.timelineStart + dt));
        return;
    }
    if (type === 'clip') {
        trimSource(item, origin, dt, mode, Math.max(0.1, Number(origin.speed) || 1), sourceOf(origin));
        return;
    }
    if (type === 'audio') {
        trimSource(item, origin, dt, mode, 1, sourceOf(origin));
        return;
    }
    if (mode === 'trim-end') {
        item.duration = roundTime(Math.max(0.1, origin.duration + dt));
        item.timelineStart = origin.timelineStart;
        return;
    }
    let start = origin.timelineStart + dt;
    let duration = origin.duration - dt;
    if (duration < 0.1) {
        start = origin.timelineStart + origin.duration - 0.1;
        duration = 0.1;
    }
    if (start < 0) {
        duration += start;
        start = 0;
    }
    item.timelineStart = roundTime(Math.max(0, start));
    item.duration = roundTime(Math.max(0.1, duration));
}

function trimSource(item, origin, dt, mode, speed, sourceDuration) {
    const minSource = 0.1 * speed;
    if (mode === 'trim-end') {
        const out = clampTime(origin.outPoint + dt * speed, origin.inPoint + minSource, sourceDuration);
        item.outPoint = roundTime(out);
        item.inPoint = origin.inPoint;
        item.timelineStart = origin.timelineStart;
        return;
    }
    let newIn = origin.inPoint + dt * speed;
    let newStart = origin.timelineStart + dt;
    if (newIn < 0) {
        newStart += (0 - newIn) / speed;
        newIn = 0;
    }
    if (newStart < 0) {
        newIn += (0 - newStart) * speed;
        newStart = 0;
    }
    if (origin.outPoint - newIn < minSource) {
        newIn = origin.outPoint - minSource;
        newStart = origin.timelineStart + (newIn - origin.inPoint) / speed;
    }
    newIn = clampTime(newIn, 0, Math.max(0, sourceDuration - minSource));
    item.inPoint = roundTime(newIn);
    item.outPoint = origin.outPoint;
    item.timelineStart = roundTime(Math.max(0, newStart));
}

function sourceOf(item) {
    const media = getMedia(item.mediaId);
    return Math.max(0.1, Number(item.sourceDuration || media?.duration || item.outPoint) || 0.1);
}

function seekFromPointer(event) {
    setPlayhead(timeAt(event.clientX));
}

function timeAt(clientX) {
    const sheet = document.getElementById('timelineSheet');
    const rect = sheet.getBoundingClientRect();
    return Math.max(0, (clientX - rect.left) / pixelsPerSecond);
}

function snap(time) {
    if (!snapEnabled) return roundTime(time);
    const threshold = 8 / pixelsPerSecond;
    const project = getProject();
    const targets = [0, project.playhead];
    project.clips.forEach((clip) => {
        targets.push(clip.timelineStart, clip.timelineStart + videoSpan(clip));
    });
    let best = time;
    targets.forEach((target) => {
        if (Math.abs(time - target) <= threshold) best = target;
    });
    return roundTime(Math.max(0, best));
}

function findRecord(type, id) {
    const project = getProject();
    const list = { clip: project.clips, image: project.images, text: project.texts, audio: project.audios }[type] || [];
    return list.find((item) => item.id === id) || null;
}

function roundTime(value) {
    return Math.round(value * 1000) / 1000;
}

function clampTime(value, min, max) {
    return Math.min(max, Math.max(min, value));
}
