import { t } from './i18n.js';
import { clamp, round3, uid } from './util.js';

const STORAGE_KEY = 've.project.v1';
const SNAPSHOT_KEY = 've.snapshots.v1';
const MAX_HISTORY = 50;

const listeners = new Set();
const playheadListeners = new Set();
const selectionListeners = new Set();

let project = defaultProject();
let past = [];
let future = [];
let selection = { type: null, id: null };
let editSnapshot = null;
let editDirty = false;
let persistTimer = 0;

export function initProject() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
        project = defaultProject();
        return;
    }
    try {
        project = normalizeProject(JSON.parse(raw));
    } catch {
        project = defaultProject();
    }
}

export function getProject() {
    return project;
}

export function subscribe(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

export function onPlayhead(listener) {
    playheadListeners.add(listener);
    return () => playheadListeners.delete(listener);
}

export function onSelection(listener) {
    selectionListeners.add(listener);
    return () => selectionListeners.delete(listener);
}

export function getSelection() {
    return { ...selection };
}

export function getSelectedRecord() {
    const list = collection(selection.type);
    if (!list) return null;
    return list.find((item) => item.id === selection.id) || null;
}

export function setSelection(next) {
    if (!next || !next.id) {
        selection = { type: null, id: null };
    } else {
        selection = { type: next.type, id: next.id };
    }
    selectionListeners.forEach((listener) => listener());
}

export function canUndo() {
    return past.length > 0 || editDirty;
}

export function canRedo() {
    return future.length > 0;
}

export function beginEdit() {
    if (!editSnapshot) {
        editSnapshot = structuredClone(project);
        editDirty = false;
    }
}

export function markDirty() {
    editDirty = true;
}

export function endEdit() {
    if (!editSnapshot) {
        return;
    }
    const changed = editDirty && JSON.stringify(editSnapshot) !== JSON.stringify(project);
    const snapshot = editSnapshot;
    editSnapshot = null;
    editDirty = false;
    if (changed) {
        past.push(snapshot);
        if (past.length > MAX_HISTORY) past.shift();
        future = [];
    }
    persist();
    emit();
}

export function patchSelected(mutator) {
    const item = getSelectedRecord();
    if (!item) return;
    mutator(item);
    markDirty();
}

export function patchProject(mutator) {
    mutator(project);
    markDirty();
}

export function transact(mutator) {
    if (editSnapshot) {
        endEdit();
    }
    const before = structuredClone(project);
    mutator(project);
    if (JSON.stringify(before) === JSON.stringify(project)) {
        return;
    }
    past.push(before);
    if (past.length > MAX_HISTORY) past.shift();
    future = [];
    persist();
    emit();
}

export function undo() {
    if (editSnapshot) {
        endEdit();
    }
    const previous = past.pop();
    if (!previous) return;
    future.push(structuredClone(project));
    project = previous;
    persist();
    emit();
}

export function redo() {
    const next = future.pop();
    if (!next) return;
    past.push(structuredClone(project));
    project = next;
    persist();
    emit();
}

export function newProject() {
    past = [];
    future = [];
    editSnapshot = null;
    editDirty = false;
    selection = { type: null, id: null };
    project = defaultProject();
    persist();
    emit();
}

export function resetProject() {
    transact((draft) => {
        draft.playhead = 0;
        draft.clips = [];
        draft.images = [];
        draft.texts = [];
        draft.audios = [];
        draft.filters = defaultFilters();
        draft.muteOriginal = false;
    });
    setSelection(null);
}

export function replaceProject(data) {
    past = [];
    future = [];
    editSnapshot = null;
    editDirty = false;
    selection = { type: null, id: null };
    project = normalizeProject(data);
    persist();
    emit();
}

export function saveSnapshot() {
    const snapshots = readSnapshots();
    snapshots.unshift({
        id: uid(),
        name: project.name || t('untitled'),
        savedAt: new Date().toISOString(),
        project: structuredClone(project),
    });
    localStorage.setItem(SNAPSHOT_KEY, JSON.stringify(snapshots.slice(0, 20)));
    persist();
}

export function readSnapshots() {
    try {
        const parsed = JSON.parse(localStorage.getItem(SNAPSHOT_KEY) || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

export function deleteSnapshot(id) {
    const next = readSnapshots().filter((item) => item.id !== id);
    localStorage.setItem(SNAPSHOT_KEY, JSON.stringify(next));
}

export function loadSnapshot(id) {
    const found = readSnapshots().find((item) => item.id === id);
    if (!found) return false;
    replaceProject(found.project);
    return true;
}

export function downloadProjectFile() {
    const blob = new Blob([JSON.stringify(project, null, 2)], { type: 'application/json' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${(project.name || 'project').replace(/[^\w\- ]+/g, '') || 'project'}.veproj.json`;
    link.click();
    URL.revokeObjectURL(link.href);
}

export function setPlayhead(time) {
    project.playhead = round3(clamp(time, 0, timelineSpan(project)));
    schedulePersist();
    playheadListeners.forEach((listener) => listener(project.playhead));
}

export function videoSpan(clip) {
    const speed = Math.max(0.1, Number(clip.speed) || 1);
    return Math.max(0, (Number(clip.outPoint) - Number(clip.inPoint)) / speed);
}

export function audioSpan(audio) {
    return Math.max(0, Number(audio.outPoint) - Number(audio.inPoint));
}

export function contentEnd(source = project) {
    let end = 0;
    source.clips.forEach((clip) => {
        end = Math.max(end, Number(clip.timelineStart) + videoSpan(clip));
    });
    source.images.forEach((image) => {
        end = Math.max(end, Number(image.timelineStart) + Number(image.duration));
    });
    source.texts.forEach((text) => {
        end = Math.max(end, Number(text.timelineStart) + Number(text.duration));
    });
    source.audios.forEach((audio) => {
        end = Math.max(end, Number(audio.timelineStart) + audioSpan(audio));
    });
    return end;
}

export function timelineSpan(source = project) {
    return Math.max(30, contentEnd(source) + 4);
}

export function addVideoClip(media, atTime = null) {
    const duration = Math.max(0.1, Number(media.duration) || 0.1);
    let id = '';
    transact((draft) => {
        const end = draft.clips.reduce((max, clip) => Math.max(max, clip.timelineStart + videoSpan(clip)), 0);
        id = uid();
        draft.clips.push({
            id,
            mediaId: media.id,
            name: media.originalName,
            timelineStart: atTime === null ? round3(end) : round3(Math.max(0, atTime)),
            inPoint: 0,
            outPoint: round3(duration),
            sourceDuration: round3(duration),
            speed: 1,
            volume: 100,
            muted: false,
            x: 0,
            y: 0,
            scale: 1,
            rotation: 0,
            crop: { top: 0, right: 0, bottom: 0, left: 0 },
        });
    });
    setSelection({ type: 'clip', id });
}

export function addImageClip(media, atTime = null) {
    let id = '';
    transact((draft) => {
        id = uid();
        const natural = Math.max(16, Number(media.width) || 480);
        draft.images.push({
            id,
            mediaId: media.id,
            name: media.originalName,
            timelineStart: round3(Math.max(0, atTime === null ? draft.playhead : atTime)),
            duration: 5,
            x: 48,
            y: 48,
            width: Math.min(480, natural),
            scale: 1,
            opacity: 100,
            rotation: 0,
        });
    });
    setSelection({ type: 'image', id });
}

export function addTextClip(atTime = null) {
    let id = '';
    transact((draft) => {
        id = uid();
        draft.texts.push({
            id,
            content: t('yourText'),
            fontFamily: 'Arial',
            fontSize: 64,
            color: '#ffffff',
            bold: false,
            italic: false,
            align: 'center',
            x: Math.round(draft.width / 2),
            y: Math.round(draft.height / 2),
            timelineStart: round3(Math.max(0, atTime === null ? draft.playhead : atTime)),
            duration: 5,
            opacity: 100,
            rotation: 0,
        });
    });
    setSelection({ type: 'text', id });
}

export function addAudioClip(media, atTime = null) {
    const duration = Math.max(0.1, Number(media.duration) || 0.1);
    let id = '';
    transact((draft) => {
        id = uid();
        const track = Math.min(8, draft.audios.length + 1);
        draft.audios.push({
            id,
            mediaId: media.id,
            name: media.originalName,
            timelineStart: round3(Math.max(0, atTime === null ? draft.playhead : atTime)),
            inPoint: 0,
            outPoint: round3(duration),
            sourceDuration: round3(duration),
            volume: 80,
            track,
        });
    });
    setSelection({ type: 'audio', id });
}

export function removeSelected() {
    if (!selection.id) return false;
    const type = selection.type;
    const id = selection.id;
    transact((draft) => {
        const key = collectionKey(type);
        if (!key) return;
        draft[key] = draft[key].filter((item) => item.id !== id);
    });
    return true;
}

export function splitSelectedVideo() {
    const selected = getSelectedRecord();
    if (!selected || selection.type !== 'clip') {
        return 'err.splitSelect';
    }
    const span = videoSpan(selected);
    const relative = project.playhead - selected.timelineStart;
    if (relative <= 0.1 || relative >= span - 0.1) {
        return 'err.splitEdge';
    }
    const sourcePos = round3(selected.inPoint + relative * selected.speed);
    const newId = uid();
    const playhead = project.playhead;
    transact((draft) => {
        const index = draft.clips.findIndex((clip) => clip.id === selected.id);
        if (index < 0) return;
        const original = draft.clips[index];
        const first = { ...structuredClone(original), outPoint: sourcePos };
        const second = {
            ...structuredClone(original),
            id: newId,
            inPoint: sourcePos,
            timelineStart: round3(playhead),
        };
        draft.clips.splice(index, 1, first, second);
    });
    setSelection({ type: 'clip', id: newId });
    return '';
}

function emit() {
    if (selection.id && !getSelectedRecord()) {
        selection = { type: null, id: null };
    }
    listeners.forEach((listener) => listener());
    selectionListeners.forEach((listener) => listener());
}

function collection(type) {
    const key = collectionKey(type);
    return key ? project[key] : null;
}

function collectionKey(type) {
    return {
        clip: 'clips',
        image: 'images',
        text: 'texts',
        audio: 'audios',
    }[type] || null;
}

function persist() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(project));
}

function schedulePersist() {
    window.clearTimeout(persistTimer);
    persistTimer = window.setTimeout(persist, 400);
}

function defaultFilters() {
    return {
        brightness: 0,
        contrast: 0,
        saturation: 0,
        grayscale: 0,
        blur: 0,
        sepia: 0,
    };
}

function defaultProject() {
    return {
        version: 1,
        name: t('untitled'),
        fps: 30,
        width: 1920,
        height: 1080,
        playhead: 0,
        clips: [],
        images: [],
        texts: [],
        audios: [],
        filters: defaultFilters(),
        muteOriginal: false,
        exportMode: 'fast',
    };
}

function normalizeProject(data) {
    const project = defaultProject();
    if (!data || typeof data !== 'object') return project;
    project.name = String(data.name || project.name).slice(0, 80) || t('untitled');
    project.fps = [24, 25, 30, 60].includes(Number(data.fps)) ? Number(data.fps) : 30;
    const width = Number(data.width);
    const height = Number(data.height);
    if (width >= 160 && width <= 3840 && width % 2 === 0) project.width = width;
    if (height >= 160 && height <= 2160 && height % 2 === 0) project.height = height;
    project.playhead = round3(Math.max(0, Number(data.playhead) || 0));
    project.muteOriginal = Boolean(data.muteOriginal);
    project.exportMode = data.exportMode === 'high' ? 'high' : 'fast';
    const filters = data.filters || {};
    project.filters = {
        brightness: clamp(filters.brightness ?? 0, -100, 100),
        contrast: clamp(filters.contrast ?? 0, -100, 100),
        saturation: clamp(filters.saturation ?? 0, -100, 100),
        grayscale: clamp(filters.grayscale ?? 0, 0, 100),
        blur: clamp(filters.blur ?? 0, 0, 20),
        sepia: clamp(filters.sepia ?? 0, 0, 100),
    };
    project.clips = Array.isArray(data.clips) ? data.clips.slice(0, 40).map(normalizeClip).filter(Boolean) : [];
    project.images = Array.isArray(data.images) ? data.images.slice(0, 30).map(normalizeImage).filter(Boolean) : [];
    project.texts = Array.isArray(data.texts) ? data.texts.slice(0, 30).map(normalizeText).filter(Boolean) : [];
    project.audios = Array.isArray(data.audios) ? data.audios.slice(0, 20).map(normalizeAudio).filter(Boolean) : [];
    project.playhead = clamp(project.playhead, 0, timelineSpan(project));
    return project;
}

function freshId(value) {
    return typeof value === 'string' && /^[a-f0-9]{32}$/.test(value) ? value : uid();
}

function normalizeClip(clip) {
    if (!clip || typeof clip !== 'object' || typeof clip.mediaId !== 'string') return null;
    const speed = clamp(clip.speed ?? 1, 0.1, 8);
    const source = Math.max(0.1, Number(clip.sourceDuration || clip.outPoint) || 0.1);
    let inPoint = clamp(clip.inPoint ?? 0, 0, Math.max(0, source - 0.1 * speed));
    let outPoint = clamp(clip.outPoint ?? source, inPoint + 0.1 * speed, source);
    const crop = clip.crop || {};
    return {
        id: freshId(clip.id),
        mediaId: clip.mediaId,
        name: String(clip.name || 'Video').slice(0, 120),
        timelineStart: round3(Math.max(0, Number(clip.timelineStart) || 0)),
        inPoint: round3(inPoint),
        outPoint: round3(outPoint),
        sourceDuration: round3(source),
        speed: round3(speed),
        volume: clamp(clip.volume ?? 100, 0, 100),
        muted: Boolean(clip.muted),
        x: clamp(clip.x ?? 0, -4000, 4000),
        y: clamp(clip.y ?? 0, -4000, 4000),
        scale: clamp(clip.scale ?? 1, 0.05, 5),
        rotation: [0, 90, 180, 270].includes(Number(clip.rotation)) ? Number(clip.rotation) : 0,
        crop: {
            top: Math.max(0, Number(crop.top) || 0),
            right: Math.max(0, Number(crop.right) || 0),
            bottom: Math.max(0, Number(crop.bottom) || 0),
            left: Math.max(0, Number(crop.left) || 0),
        },
    };
}

function normalizeImage(image) {
    if (!image || typeof image !== 'object' || typeof image.mediaId !== 'string') return null;
    return {
        id: freshId(image.id),
        mediaId: image.mediaId,
        name: String(image.name || 'Image').slice(0, 120),
        timelineStart: round3(Math.max(0, Number(image.timelineStart) || 0)),
        duration: round3(clamp(image.duration ?? 5, 0.1, 3600)),
        x: clamp(image.x ?? 48, -4000, 4000),
        y: clamp(image.y ?? 48, -4000, 4000),
        width: clamp(image.width ?? 480, 16, 3840),
        scale: clamp(image.scale ?? 1, 0.05, 5),
        opacity: clamp(image.opacity ?? 100, 0, 100),
        rotation: clamp(image.rotation ?? 0, 0, 360),
    };
}

function normalizeText(text) {
    if (!text || typeof text !== 'object') return null;
    const content = String(text.content || '').replace(/[\r\n]+/g, ' ').trim();
    if (!content) return null;
    const color = /^#[0-9a-fA-F]{6}$/.test(text.color || '') ? text.color : '#ffffff';
    const fonts = ['Arial', 'Verdana', 'Georgia', 'Times New Roman', 'Courier New'];
    return {
        id: freshId(text.id),
        content: content.slice(0, 160),
        fontFamily: fonts.includes(text.fontFamily) ? text.fontFamily : 'Arial',
        fontSize: clamp(text.fontSize ?? 64, 8, 256),
        color,
        bold: Boolean(text.bold),
        italic: Boolean(text.italic),
        align: ['left', 'center', 'right'].includes(text.align) ? text.align : 'center',
        x: clamp(text.x ?? 960, -4000, 4000),
        y: clamp(text.y ?? 540, -4000, 4000),
        timelineStart: round3(Math.max(0, Number(text.timelineStart) || 0)),
        duration: round3(clamp(text.duration ?? 5, 0.1, 3600)),
        opacity: clamp(text.opacity ?? 100, 0, 100),
        rotation: clamp(text.rotation ?? 0, 0, 360),
    };
}

function normalizeAudio(audio) {
    if (!audio || typeof audio !== 'object' || typeof audio.mediaId !== 'string') return null;
    const source = Math.max(0.1, Number(audio.sourceDuration || audio.outPoint) || 0.1);
    const inPoint = clamp(audio.inPoint ?? 0, 0, Math.max(0, source - 0.1));
    const outPoint = clamp(audio.outPoint ?? source, inPoint + 0.1, source);
    return {
        id: freshId(audio.id),
        mediaId: audio.mediaId,
        name: String(audio.name || 'Audio').slice(0, 120),
        timelineStart: round3(Math.max(0, Number(audio.timelineStart) || 0)),
        inPoint: round3(inPoint),
        outPoint: round3(outPoint),
        sourceDuration: round3(source),
        volume: clamp(audio.volume ?? 80, 0, 100),
        track: clamp(Math.round(audio.track ?? 1), 1, 8),
    };
}
