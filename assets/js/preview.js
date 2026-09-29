import { onLanguage, t } from './i18n.js';
import { getMedia, visualUrl } from './media.js';
import {
    beginEdit,
    contentEnd,
    endEdit,
    getProject,
    getSelectedRecord,
    getSelection,
    markDirty,
    onPlayhead,
    patchSelected,
    setPlayhead,
    subscribe,
    setSelection,
    videoSpan,
} from './project.js';

const imageCache = new Map();
const videoPool = new Map();
const audioPool = new Map();
const buffer = document.createElement('canvas');
const bufferCtx = buffer.getContext('2d');

let playing = false;
let playAnchor = 0;
let playOrigin = 0;
let previewMuted = false;
let rafId = 0;
let drag = null;

export function initPreview() {
    const canvas = document.getElementById('previewCanvas');
    document.getElementById('btnPlay').addEventListener('click', play);
    document.getElementById('btnPause').addEventListener('click', pause);
    document.getElementById('btnStop').addEventListener('click', stop);
    document.getElementById('btnGoStart').addEventListener('click', () => {
        pause();
        setPlayhead(0);
        syncMedia(true);
    });
    document.getElementById('btnGoEnd').addEventListener('click', () => {
        pause();
        setPlayhead(contentEnd(getProject()));
        syncMedia(true);
    });
    document.getElementById('btnMutePreview').addEventListener('click', () => {
        previewMuted = !previewMuted;
        updateMuteButton();
    });
    const seek = document.getElementById('seekBar');
    seek.addEventListener('input', () => {
        const duration = contentEnd(getProject());
        const time = duration <= 0 ? 0 : (Number(seek.value) / 1000) * duration;
        setPlayhead(time);
        if (playing) {
            playAnchor = performance.now();
            playOrigin = getProject().playhead;
        }
        syncMedia(true);
    });
    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerup', onPointerUp);
    canvas.addEventListener('pointercancel', onPointerUp);
    window.addEventListener('resize', fitStage);
    onPlayhead(updateTransport);
    subscribe(updateTransport);
    onLanguage(() => {
        updateMuteButton();
        updateTransport();
        draw();
    });
    updateMuteButton();
    fitStage();
    updateTransport();
    rafId = requestAnimationFrame(loop);
}

export function play() {
    const end = contentEnd(getProject());
    if (end <= 0) return;
    if (getProject().playhead >= end - 0.01) {
        setPlayhead(0);
    }
    playing = true;
    playAnchor = performance.now();
    playOrigin = getProject().playhead;
    document.getElementById('btnPlay').classList.add('active');
    syncMedia(true);
}

export function pause() {
    playing = false;
    document.getElementById('btnPlay').classList.remove('active');
    syncMedia(true);
}

export function stop() {
    pause();
    setPlayhead(0);
    syncMedia(true);
}

export function togglePlay() {
    if (playing) pause();
    else play();
}

export function isPlaying() {
    return playing;
}

function loop(now) {
    if (playing) {
        const end = contentEnd(getProject());
        const next = playOrigin + (now - playAnchor) / 1000;
        if (end <= 0 || next >= end) {
            setPlayhead(Math.max(0, end));
            pause();
        } else {
            setPlayhead(next);
        }
    }
    fitStage();
    draw();
    syncMedia(false);
    rafId = requestAnimationFrame(loop);
}

function updateTransport() {
    const project = getProject();
    const duration = contentEnd(project);
    document.getElementById('timeCurrent').textContent = formatClock(project.playhead);
    document.getElementById('timeTotal').textContent = formatClock(duration);
    const seek = document.getElementById('seekBar');
    seek.disabled = duration <= 0;
    seek.max = 1000;
    const ratio = duration <= 0 ? 0 : project.playhead / duration;
    if (document.activeElement !== seek) {
        seek.value = String(Math.round(Math.min(1, Math.max(0, ratio)) * 1000));
    }
    document.getElementById('previewBadge').textContent = t('previewBadge', {
        w: project.width,
        h: project.height,
        fps: project.fps,
    });
    document.getElementById('previewEmpty').hidden = duration > 0;
}

function formatClock(seconds) {
    const safe = Math.max(0, Number(seconds) || 0);
    const minutes = Math.floor(safe / 60);
    const secs = Math.floor(safe % 60);
    const tenths = Math.floor((safe - Math.floor(safe)) * 10);
    return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}.${tenths}`;
}

function fitStage() {
    const stage = document.getElementById('previewStage');
    const canvas = document.getElementById('previewCanvas');
    const project = getProject();
    if (!stage || !canvas) return;
    const ratio = project.width / project.height;
    const style = getComputedStyle(stage);
    const maxW = stage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
    const maxH = stage.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);
    let width = maxW;
    let height = width / ratio;
    if (height > maxH) {
        height = maxH;
        width = height * ratio;
    }
    canvas.style.width = `${Math.max(1, width)}px`;
    canvas.style.height = `${Math.max(1, height)}px`;
    if (canvas.width !== project.width) canvas.width = project.width;
    if (canvas.height !== project.height) canvas.height = project.height;
}

function draw() {
    const canvas = document.getElementById('previewCanvas');
    const ctx = canvas.getContext('2d');
    const project = getProject();
    buffer.width = project.width;
    buffer.height = project.height;
    bufferCtx.clearRect(0, 0, buffer.width, buffer.height);
    bufferCtx.fillStyle = '#000';
    bufferCtx.fillRect(0, 0, buffer.width, buffer.height);

    project.clips.forEach((clip) => {
        if (!covers(clip.timelineStart, videoSpan(clip), project.playhead)) return;
        const media = getMedia(clip.mediaId);
        const element = videoPool.get(clip.id);
        if (!media || !element || element.readyState < 2) {
            drawMissing(clip, media);
            return;
        }
        drawVideo(clip, media, element);
    });
    project.images.forEach((image) => {
        if (!covers(image.timelineStart, image.duration, project.playhead)) return;
        drawImageItem(image);
    });
    project.texts.forEach((text) => {
        if (!covers(text.timelineStart, text.duration, project.playhead)) return;
        drawTextItem(text);
    });

    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.filter = cssFilter(project.filters);
    ctx.drawImage(buffer, 0, 0);
    ctx.filter = 'none';
    drawSelection(ctx);
}

function drawMissing(clip) {
    const rect = videoRect(clip, { width: getProject().width, height: getProject().height });
    bufferCtx.save();
    bufferCtx.fillStyle = '#1c2433';
    bufferCtx.fillRect(rect.x, rect.y, rect.w, rect.h);
    bufferCtx.fillStyle = '#d5dbea';
    bufferCtx.font = '28px Segoe UI, sans-serif';
    bufferCtx.textAlign = 'center';
    bufferCtx.fillText(t('previewUnavailable'), rect.x + rect.w / 2, rect.y + rect.h / 2);
    bufferCtx.restore();
}

function drawVideo(clip, media, element) {
    const rect = videoRect(clip, media);
    const crop = clip.crop || { top: 0, right: 0, bottom: 0, left: 0 };
    const srcW = media.width || element.videoWidth;
    const srcH = media.height || element.videoHeight;
    const sx = crop.left;
    const sy = crop.top;
    const sw = Math.max(1, srcW - crop.left - crop.right);
    const sh = Math.max(1, srcH - crop.top - crop.bottom);
    bufferCtx.save();
    bufferCtx.translate(rect.x + rect.w / 2, rect.y + rect.h / 2);
    bufferCtx.rotate((clip.rotation * Math.PI) / 180);
    if (clip.rotation === 90 || clip.rotation === 270) {
        bufferCtx.drawImage(element, sx, sy, sw, sh, -rect.h / 2, -rect.w / 2, rect.h, rect.w);
    } else {
        bufferCtx.drawImage(element, sx, sy, sw, sh, -rect.w / 2, -rect.h / 2, rect.w, rect.h);
    }
    bufferCtx.restore();
}

function videoRect(clip, media) {
    const project = getProject();
    let sw = media.width || project.width;
    let sh = media.height || project.height;
    const crop = clip.crop || { top: 0, right: 0, bottom: 0, left: 0 };
    sw = Math.max(2, sw - (crop.left || 0) - (crop.right || 0));
    sh = Math.max(2, sh - (crop.top || 0) - (crop.bottom || 0));
    if (clip.rotation === 90 || clip.rotation === 270) {
        [sw, sh] = [sh, sw];
    }
    const scale = Number(clip.scale) || 1;
    const fit = Math.min((project.width * scale) / sw, (project.height * scale) / sh);
    const w = sw * fit;
    const h = sh * fit;
    return {
        x: (project.width - w) / 2 + (Number(clip.x) || 0),
        y: (project.height - h) / 2 + (Number(clip.y) || 0),
        w,
        h,
    };
}

function drawImageItem(item) {
    const media = getMedia(item.mediaId);
    if (!media) return;
    const image = cachedImage(media.url);
    if (!image.complete || !image.naturalWidth) return;
    const dw = item.width * item.scale;
    const dh = dw * (image.naturalHeight / image.naturalWidth);
    bufferCtx.save();
    bufferCtx.translate(item.x + dw / 2, item.y + dh / 2);
    bufferCtx.rotate((item.rotation * Math.PI) / 180);
    bufferCtx.globalAlpha = Math.max(0, Math.min(1, item.opacity / 100));
    bufferCtx.drawImage(image, -dw / 2, -dh / 2, dw, dh);
    bufferCtx.restore();
}

function drawTextItem(text) {
    bufferCtx.save();
    bufferCtx.translate(text.x, text.y);
    bufferCtx.rotate((text.rotation * Math.PI) / 180);
    bufferCtx.globalAlpha = Math.max(0, Math.min(1, text.opacity / 100));
    bufferCtx.font = `${text.italic ? 'italic ' : ''}${text.bold ? 'bold ' : ''}${text.fontSize}px ${text.fontFamily}`;
    bufferCtx.fillStyle = text.color;
    bufferCtx.textAlign = text.align;
    bufferCtx.textBaseline = 'middle';
    bufferCtx.fillText(text.content, 0, 0);
    bufferCtx.restore();
}

function drawSelection(ctx) {
    const selected = getSelectedRecord();
    const selection = getSelection();
    if (!selected) return;
    const project = getProject();
    if (selection.type === 'clip' && !covers(selected.timelineStart, videoSpan(selected), project.playhead)) return;
    if ((selection.type === 'image' || selection.type === 'text') && !covers(selected.timelineStart, selected.duration, project.playhead)) return;
    ctx.save();
    ctx.strokeStyle = '#8eb6ff';
    ctx.lineWidth = 3;
    ctx.setLineDash([10, 6]);
    if (selection.type === 'clip') {
        const rect = videoRect(selected, getMedia(selected.mediaId) || {});
        ctx.strokeRect(rect.x, rect.y, rect.w, rect.h);
    } else if (selection.type === 'image') {
        const media = getMedia(selected.mediaId);
        const image = media ? cachedImage(media.url) : null;
        const dw = selected.width * selected.scale;
        const dh = image && image.naturalWidth ? dw * (image.naturalHeight / image.naturalWidth) : dw;
        ctx.strokeRect(selected.x, selected.y, dw, dh);
        ctx.setLineDash([]);
        ctx.fillStyle = '#8eb6ff';
        ctx.fillRect(selected.x + dw - 8, selected.y + dh - 8, 16, 16);
    } else if (selection.type === 'text') {
        const bounds = textBounds(selected);
        ctx.strokeRect(bounds.x, bounds.y, bounds.w, bounds.h);
    }
    ctx.restore();
}

function cssFilter(filters) {
    const brightness = 1 + (Number(filters.brightness) || 0) / 100;
    const contrast = Math.max(0, 1 + (Number(filters.contrast) || 0) / 100);
    const saturation = Math.max(0, 1 + (Number(filters.saturation) || 0) / 100);
    return `brightness(${brightness}) contrast(${contrast}) saturate(${saturation}) grayscale(${(filters.grayscale || 0) / 100}) blur(${filters.blur || 0}px) sepia(${(filters.sepia || 0) / 100})`;
}

function covers(start, duration, time) {
    return time >= start - 0.0001 && time < start + duration - 0.0001;
}

function cachedImage(url) {
    if (!imageCache.has(url)) {
        const image = new Image();
        image.src = url;
        imageCache.set(url, image);
    }
    return imageCache.get(url);
}

function syncMedia(forceSeek) {
    const project = getProject();
    const activeVideos = new Set();
    project.clips.forEach((clip) => {
        const media = getMedia(clip.mediaId);
        const url = visualUrl(media);
        if (!url) return;
        const element = ensureVideo(clip, url);
        activeVideos.add(clip.id);
        const active = covers(clip.timelineStart, videoSpan(clip), project.playhead);
        const expected = clip.inPoint + (project.playhead - clip.timelineStart) * clip.speed;
        const audible = !previewMuted && !project.muteOriginal && !clip.muted;
        safeVolume(element, audible ? clip.volume / 100 : 0);
        safeRate(element, clip.speed);
        if (!active) {
            if (!element.paused) element.pause();
            return;
        }
        if (forceSeek || Math.abs((element.currentTime || 0) - expected) > 0.35) {
            seekTo(element, expected);
        }
        if (playing && element.paused) element.play().catch(() => {});
        if (!playing && !element.paused) element.pause();
    });
    videoPool.forEach((element, id) => {
        if (!activeVideos.has(id)) {
            element.pause();
            element.remove();
            videoPool.delete(id);
        }
    });

    const activeAudio = new Set();
    project.audios.forEach((audio) => {
        const media = getMedia(audio.mediaId);
        if (!media) return;
        const element = ensureAudio(audio, media.url);
        activeAudio.add(audio.id);
        const span = Math.max(0, audio.outPoint - audio.inPoint);
        const active = covers(audio.timelineStart, span, project.playhead);
        const expected = audio.inPoint + (project.playhead - audio.timelineStart);
        safeVolume(element, previewMuted ? 0 : audio.volume / 100);
        if (!active) {
            if (!element.paused) element.pause();
            return;
        }
        if (forceSeek || Math.abs((element.currentTime || 0) - expected) > 0.35) {
            seekTo(element, expected);
        }
        if (playing && element.paused) element.play().catch(() => {});
        if (!playing && !element.paused) element.pause();
    });
    audioPool.forEach((element, id) => {
        if (!activeAudio.has(id)) {
            element.pause();
            element.remove();
            audioPool.delete(id);
        }
    });
}

function ensureVideo(clip, url) {
    let element = videoPool.get(clip.id);
    if (!element) {
        element = document.createElement('video');
        element.playsInline = true;
        element.preload = 'auto';
        document.getElementById('mediaPool').appendChild(element);
        videoPool.set(clip.id, element);
    }
    if (element.dataset.src !== url) {
        element.dataset.src = url;
        element.src = url;
    }
    return element;
}

function ensureAudio(audio, url) {
    let element = audioPool.get(audio.id);
    if (!element) {
        element = document.createElement('audio');
        element.preload = 'auto';
        document.getElementById('mediaPool').appendChild(element);
        audioPool.set(audio.id, element);
    }
    if (element.dataset.src !== url) {
        element.dataset.src = url;
        element.src = url;
    }
    return element;
}

function safeVolume(element, volume) {
    const value = Math.max(0, Math.min(1, volume));
    try {
        element.volume = value;
        element.muted = value === 0;
    } catch {
        /* The element may not be ready yet. */
    }
}

function safeRate(element, speed) {
    try {
        element.playbackRate = speed;
    } catch {
        try {
            element.playbackRate = Math.min(4, Math.max(0.25, speed));
        } catch {
            /* Ignore unsupported rates. */
        }
    }
}

function seekTo(element, time) {
    if (!Number.isFinite(time) || time < 0) return;
    try {
        if (Math.abs((element.currentTime || 0) - time) > 0.05) {
            element.currentTime = time;
        }
    } catch {
        /* Seeking before metadata is available throws in some browsers. */
    }
}

function updateMuteButton() {
    const button = document.getElementById('btnMutePreview');
    button.innerHTML = previewMuted
        ? '<i class="fa-solid fa-volume-xmark"></i>'
        : '<i class="fa-solid fa-volume-high"></i>';
    button.title = previewMuted ? t('unmute') : t('mute');
    button.classList.toggle('active', previewMuted);
}

function eventToProject(event) {
    const canvas = document.getElementById('previewCanvas');
    const rect = canvas.getBoundingClientRect();
    const project = getProject();
    return {
        x: ((event.clientX - rect.left) / rect.width) * project.width,
        y: ((event.clientY - rect.top) / rect.height) * project.height,
    };
}

function onPointerDown(event) {
    const point = eventToProject(event);
    const hit = hitTest(point);
    if (!hit) {
        setSelection(null);
        return;
    }
    setSelection({ type: hit.type, id: hit.item.id });
    beginEdit();
    drag = {
        type: hit.type,
        id: hit.item.id,
        mode: hit.resize ? 'resize' : 'move',
        origin: point,
        item: {
            x: hit.item.x,
            y: hit.item.y,
            width: hit.item.width,
            scale: hit.item.scale,
        },
    };
    event.currentTarget.setPointerCapture(event.pointerId);
}

function onPointerMove(event) {
    if (!drag) return;
    const point = eventToProject(event);
    const dx = point.x - drag.origin.x;
    const dy = point.y - drag.origin.y;
    if (Math.abs(dx) + Math.abs(dy) < 1) return;
    patchSelected((item) => {
        if (drag.mode === 'resize' && drag.type === 'image') {
            const width = Math.max(16, drag.item.width + dx / Math.max(0.05, drag.item.scale || 1));
            item.width = Math.min(3840, width);
            return;
        }
        item.x = drag.item.x + dx;
        item.y = drag.item.y + dy;
    });
}

function onPointerUp() {
    if (!drag) return;
    drag = null;
    endEdit();
}

function hitTest(point) {
    const project = getProject();
    for (let index = project.texts.length - 1; index >= 0; index -= 1) {
        const text = project.texts[index];
        if (!covers(text.timelineStart, text.duration, project.playhead)) continue;
        const local = unrotate(point, text.x, text.y, text.rotation);
        const bounds = textBounds(text);
        const localBounds = {
            x: bounds.x - text.x + text.x,
            y: bounds.y,
            w: bounds.w,
            h: bounds.h,
        };
        const test = {
            x: text.x + (local.x - text.x),
            y: text.y + (local.y - text.y),
        };
        if (test.x >= bounds.x && test.x <= bounds.x + bounds.w && test.y >= bounds.y && test.y <= bounds.y + bounds.h) {
            return { type: 'text', item: text, resize: false };
        }
        void localBounds;
    }
    for (let index = project.images.length - 1; index >= 0; index -= 1) {
        const image = project.images[index];
        if (!covers(image.timelineStart, image.duration, project.playhead)) continue;
        const media = getMedia(image.mediaId);
        const element = media ? cachedImage(media.url) : null;
        const dw = image.width * image.scale;
        const dh = element && element.naturalWidth ? dw * (element.naturalHeight / element.naturalWidth) : dw;
        const centerX = image.x + dw / 2;
        const centerY = image.y + dh / 2;
        const local = unrotate(point, centerX, centerY, image.rotation);
        if (local.x >= image.x && local.x <= image.x + dw && local.y >= image.y && local.y <= image.y + dh) {
            const resize = local.x >= image.x + dw - 18 && local.y >= image.y + dh - 18;
            return { type: 'image', item: image, resize };
        }
    }
    for (let index = project.clips.length - 1; index >= 0; index -= 1) {
        const clip = project.clips[index];
        if (!covers(clip.timelineStart, videoSpan(clip), project.playhead)) continue;
        const rect = videoRect(clip, getMedia(clip.mediaId) || {});
        if (point.x >= rect.x && point.x <= rect.x + rect.w && point.y >= rect.y && point.y <= rect.y + rect.h) {
            return { type: 'clip', item: clip, resize: false };
        }
    }
    return null;
}

function textBounds(text) {
    bufferCtx.font = `${text.italic ? 'italic ' : ''}${text.bold ? 'bold ' : ''}${text.fontSize}px ${text.fontFamily}`;
    const width = Math.max(20, bufferCtx.measureText(text.content || ' ').width);
    const height = text.fontSize * 1.2;
    let x = text.x;
    if (text.align === 'center') x = text.x - width / 2;
    if (text.align === 'right') x = text.x - width;
    return { x, y: text.y - height / 2, w: width, h: height };
}

function unrotate(point, centerX, centerY, degrees) {
    const angle = (-degrees * Math.PI) / 180;
    const dx = point.x - centerX;
    const dy = point.y - centerY;
    return {
        x: centerX + dx * Math.cos(angle) - dy * Math.sin(angle),
        y: centerY + dx * Math.sin(angle) + dy * Math.cos(angle),
    };
}

void rafId;
