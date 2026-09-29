import { getMedia } from './media.js';
import {
    audioSpan,
    beginEdit,
    endEdit,
    getProject,
    getSelectedRecord,
    getSelection,
    onSelection,
    patchProject,
    patchSelected,
    transact,
    videoSpan,
} from './project.js';
import { t } from './i18n.js';
import { escapeHtml, FONT_FAMILIES, formatTime, SPEED_PRESETS } from './util.js';

let editing = false;

export function initInspector() {
    const body = document.getElementById('inspectorBody');
    body.addEventListener('focusin', (event) => {
        if (event.target.matches('input, select, textarea')) {
            editing = true;
            beginEdit();
        }
    });
    body.addEventListener('focusout', () => {
        window.setTimeout(() => {
            if (!body.contains(document.activeElement)) {
                editing = false;
                endEdit();
            }
        }, 0);
    });
    body.addEventListener('input', onInput);
    body.addEventListener('change', onChange);
    onSelection(renderInspector);
}

export function renderInspector() {
    if (editing && document.getElementById('inspectorBody').contains(document.activeElement)) {
        return;
    }
    editing = false;
    const project = getProject();
    const selected = getSelectedRecord();
    const selection = getSelection();
    const host = document.getElementById('inspectorBody');
    let html = '';
    if (!selected) {
        html += projectSettings(project);
    } else if (selection.type === 'clip') {
        html += videoInspector(selected);
    } else if (selection.type === 'image') {
        html += imageInspector(selected);
    } else if (selection.type === 'text') {
        html += textInspector(selected);
    } else if (selection.type === 'audio') {
        html += audioInspector(selected);
    }
    html += filterInspector(project);
    host.innerHTML = html;
}

function onInput(event) {
    const input = event.target;
    if (!input.dataset || !input.dataset.key) return;
    writeValue(input);
}

function onChange(event) {
    const input = event.target;
    if (!input.dataset || (!input.dataset.key && !input.dataset.action)) return;
    if (input.dataset.action === 'reset-filters') return;
    writeValue(input);
    if (input.dataset.key === 'resolution' || input.dataset.key === 'fps' || input.dataset.key === 'muteOriginal') {
        editing = false;
        endEdit();
    }
}

function writeValue(input) {
    const key = input.dataset.key;
    if (!key) return;
    if (key === 'resolution') {
        const [width, height] = input.value.split('x').map(Number);
        patchProject((project) => {
            project.width = width;
            project.height = height;
        });
        return;
    }
    if (key === 'fps') {
        patchProject((project) => {
            project.fps = Number(input.value);
        });
        return;
    }
    if (key === 'muteOriginal') {
        patchProject((project) => {
            project.muteOriginal = input.checked;
        });
        return;
    }
    if (key.startsWith('filter.')) {
        const name = key.slice(7);
        patchProject((project) => {
            project.filters[name] = numberValue(input);
        });
        const output = document.querySelector(`[data-value="${key}"]`);
        if (output) output.textContent = formatFilter(name, numberValue(input));
        return;
    }
    patchSelected((item) => {
        if (key.startsWith('crop.')) {
            item.crop[key.slice(5)] = Math.max(0, Math.round(numberValue(input)));
            return;
        }
        if (input.type === 'checkbox') {
            item[key] = input.checked;
            return;
        }
        if (key === 'content') {
            item.content = input.value.replace(/[\r\n]+/g, ' ').slice(0, 160);
            return;
        }
        if (key === 'color' || key === 'fontFamily' || key === 'align' || key === 'name') {
            item[key] = input.value;
            return;
        }
        if (key === 'speed') {
            if (input.value === 'custom') return;
            item.speed = clampSpeed(numberValue(input));
            return;
        }
        if (key === 'endTime') {
            item.duration = Math.max(0.1, numberValue(input) - Number(item.timelineStart || 0));
            return;
        }
        if (key === 'rotation' && getSelection().type === 'clip') {
            item.rotation = [0, 90, 180, 270].includes(Number(input.value)) ? Number(input.value) : 0;
            return;
        }
        item[key] = numberValue(input);
        clampTiming(item);
    });
}

function clampTiming(item) {
    const selection = getSelection();
    if (selection.type === 'clip' || selection.type === 'audio') {
        const media = getMedia(item.mediaId);
        const source = Math.max(0.1, Number(item.sourceDuration || media?.duration || item.outPoint) || 0.1);
        const speed = selection.type === 'clip' ? Math.max(0.1, Number(item.speed) || 1) : 1;
        const minSource = 0.1 * speed;
        item.inPoint = clamp(item.inPoint, 0, Math.max(0, source - minSource));
        item.outPoint = clamp(item.outPoint, item.inPoint + minSource, source);
        item.timelineStart = Math.max(0, Number(item.timelineStart) || 0);
        item.sourceDuration = source;
    }
    if (selection.type === 'image' || selection.type === 'text') {
        item.duration = clamp(item.duration, 0.1, 3600);
        item.timelineStart = Math.max(0, Number(item.timelineStart) || 0);
    }
}

function projectSettings(project) {
    const resolution = `${project.width}x${project.height}`;
    return `<section class="inspector-section">
        <h2>${t('project')}</h2>
        <label class="field"><span>${t('resolution')}</span>
            <select data-key="resolution">
                ${option('1920x1080', '1920 × 1080', resolution)}
                ${option('1280x720', '1280 × 720', resolution)}
                ${option('1080x1920', '1080 × 1920', resolution)}
                ${option('720x1280', '720 × 1280', resolution)}
            </select>
        </label>
        <label class="field"><span>${t('frameRate')}</span>
            <select data-key="fps">
                ${[24, 25, 30, 60].map((fps) => option(String(fps), t('fpsOption', { n: fps }), String(project.fps))).join('')}
            </select>
        </label>
        <p class="hint">${t('inspectorHint')}</p>
    </section>`;
}

function videoInspector(clip) {
    const media = getMedia(clip.mediaId);
    const source = Number(clip.sourceDuration || media?.duration || clip.outPoint) || 0;
    const speed = Number(clip.speed) || 1;
    const preset = SPEED_PRESETS.includes(speed) ? String(speed) : 'custom';
    return `<section class="inspector-section">
        <h2>${t('videoClip')}</h2>
        ${textField(t('clipName'), 'name', clip.name)}
        <div class="stat-grid">
            <div><span>${t('timelineStat')}</span><strong>${formatTime(clip.timelineStart)}</strong></div>
            <div><span>${t('duration')}</span><strong>${formatTime(videoSpan(clip))}</strong></div>
            <div><span>${t('source')}</span><strong>${formatTime(source)}</strong></div>
        </div>
        ${numberField(t('startPosition'), 'timelineStart', clip.timelineStart, '0', '3600', '0.1')}
        ${numberField(t('inPoint'), 'inPoint', clip.inPoint, '0', String(source), '0.01')}
        ${numberField(t('outPoint'), 'outPoint', clip.outPoint, '0.1', String(source), '0.01')}
        <label class="field"><span>${t('speed')}</span>
            <select data-key="speed">
                ${SPEED_PRESETS.map((value) => option(String(value), `${value}x`, preset)).join('')}
                ${option('custom', t('custom'), preset)}
            </select>
        </label>
        ${numberField(t('customSpeed'), 'speed', speed, '0.1', '8', '0.05')}
        ${rangeField(t('volume'), 'volume', clip.volume, 0, 100, 1, '%')}
        ${checkField(t('muteClip'), 'muted', clip.muted)}
        <h3>${t('transform')}</h3>
        ${numberField(t('xOffset'), 'x', clip.x, '-4000', '4000', '1')}
        ${numberField(t('yOffset'), 'y', clip.y, '-4000', '4000', '1')}
        ${numberField(t('scale'), 'scale', clip.scale, '0.05', '5', '0.05')}
        <label class="field"><span>${t('rotation')}</span>
            <select data-key="rotation">
                ${[0, 90, 180, 270].map((value) => option(String(value), `${value}°`, String(clip.rotation))).join('')}
            </select>
        </label>
        <h3>${t('crop')}</h3>
        <div class="crop-grid">
            ${numberField(t('top'), 'crop.top', clip.crop.top, '0', '4000', '1')}
            ${numberField(t('right'), 'crop.right', clip.crop.right, '0', '4000', '1')}
            ${numberField(t('bottom'), 'crop.bottom', clip.crop.bottom, '0', '4000', '1')}
            ${numberField(t('left'), 'crop.left', clip.crop.left, '0', '4000', '1')}
        </div>
        ${media ? '' : `<p class="hint warn">${escapeHtml(t('missingSource'))}</p>`}
    </section>`;
}

function imageInspector(image) {
    return `<section class="inspector-section">
        <h2>${t('imageOverlay')}</h2>
        ${textField(t('name'), 'name', image.name)}
        ${numberField(t('x'), 'x', image.x, '-4000', '4000', '1')}
        ${numberField(t('y'), 'y', image.y, '-4000', '4000', '1')}
        ${numberField(t('width'), 'width', image.width, '16', '3840', '1')}
        ${numberField(t('scale'), 'scale', image.scale, '0.05', '5', '0.05')}
        ${numberField(t('startTime'), 'timelineStart', image.timelineStart, '0', '3600', '0.1')}
        ${numberField(t('endTime'), 'endTime', Number(image.timelineStart) + Number(image.duration), '0.1', '7200', '0.1')}
        ${rangeField(t('opacity'), 'opacity', image.opacity, 0, 100, 1, '%')}
        ${numberField(t('rotation'), 'rotation', image.rotation, '0', '360', '1')}
        <p class="hint">${t('imageHint')}</p>
    </section>`;
}

function textInspector(text) {
    return `<section class="inspector-section">
        <h2>${t('textOverlay')}</h2>
        ${textField(t('text'), 'content', text.content)}
        <label class="field"><span>${t('font')}</span>
            <select data-key="fontFamily">
                ${FONT_FAMILIES.map((font) => option(font, font, text.fontFamily)).join('')}
            </select>
        </label>
        ${numberField(t('fontSize'), 'fontSize', text.fontSize, '8', '256', '1')}
        <label class="field"><span>${t('fontColor')}</span><input data-key="color" type="color" value="${escapeHtml(text.color)}"></label>
        ${checkField(t('bold'), 'bold', text.bold)}
        ${checkField(t('italic'), 'italic', text.italic)}
        <label class="field"><span>${t('alignment')}</span>
            <select data-key="align">
                ${option('left', t('alignLeft'), text.align)}
                ${option('center', t('alignCenter'), text.align)}
                ${option('right', t('alignRight'), text.align)}
            </select>
        </label>
        ${numberField(t('x'), 'x', text.x, '-4000', '4000', '1')}
        ${numberField(t('y'), 'y', text.y, '-4000', '4000', '1')}
        ${numberField(t('startTime'), 'timelineStart', text.timelineStart, '0', '3600', '0.1')}
        ${numberField(t('endTime'), 'endTime', Number(text.timelineStart) + Number(text.duration), '0.1', '7200', '0.1')}
        ${rangeField(t('opacity'), 'opacity', text.opacity, 0, 100, 1, '%')}
        ${numberField(t('rotation'), 'rotation', text.rotation, '0', '360', '1')}
    </section>`;
}

function audioInspector(audio) {
    const media = getMedia(audio.mediaId);
    const source = Number(audio.sourceDuration || media?.duration || audio.outPoint) || 0;
    return `<section class="inspector-section">
        <h2>${t('audio')}</h2>
        ${textField(t('name'), 'name', audio.name)}
        <div class="stat-grid">
            <div><span>${t('timelineStat')}</span><strong>${formatTime(audio.timelineStart)}</strong></div>
            <div><span>${t('duration')}</span><strong>${formatTime(audioSpan(audio))}</strong></div>
            <div><span>${t('source')}</span><strong>${formatTime(source)}</strong></div>
        </div>
        ${numberField(t('startTime'), 'timelineStart', audio.timelineStart, '0', '3600', '0.1')}
        ${numberField(t('inPoint'), 'inPoint', audio.inPoint, '0', String(source), '0.01')}
        ${numberField(t('outPoint'), 'outPoint', audio.outPoint, '0.1', String(source), '0.01')}
        ${rangeField(t('volume'), 'volume', audio.volume, 0, 100, 1, '%')}
        ${numberField(t('trackNumber'), 'track', audio.track, '1', '8', '1')}
    </section>`;
}

function filterInspector(project) {
    const filters = project.filters;
    return `<section class="inspector-section">
        <h2>${t('filters')}</h2>
        ${checkField(t('muteOriginal'), 'muteOriginal', project.muteOriginal)}
        ${rangeField(t('brightness'), 'filter.brightness', filters.brightness, -100, 100, 1, '')}
        ${rangeField(t('contrast'), 'filter.contrast', filters.contrast, -100, 100, 1, '')}
        ${rangeField(t('saturation'), 'filter.saturation', filters.saturation, -100, 100, 1, '')}
        ${rangeField(t('grayscale'), 'filter.grayscale', filters.grayscale, 0, 100, 1, '')}
        ${rangeField(t('blur'), 'filter.blur', filters.blur, 0, 20, 0.1, '')}
        ${rangeField(t('sepia'), 'filter.sepia', filters.sepia, 0, 100, 1, '')}
        <button type="button" class="btn btn-sm btn-outline-light" id="btnResetFilters">${t('resetFilters')}</button>
    </section>`;
}

function textField(label, key, value) {
    return `<label class="field"><span>${label}</span><input data-key="${key}" type="text" value="${escapeHtml(value)}" maxlength="160"></label>`;
}

function numberField(label, key, value, min, max, step, displayLabel = '') {
    return `<label class="field"><span>${displayLabel || label}</span><input data-key="${key}" type="number" value="${escapeHtml(value)}" min="${min}" max="${max}" step="${step}"></label>`;
}

function rangeField(label, key, value, min, max, step, suffix) {
    return `<label class="field"><span>${label} <em data-value="${key}">${formatFilter(key.replace('filter.', ''), value)}${suffix}</em></span>
        <input data-key="${key}" type="range" min="${min}" max="${max}" step="${step}" value="${escapeHtml(value)}"></label>`;
}

function checkField(label, key, checked) {
    return `<label class="check"><input data-key="${key}" type="checkbox" ${checked ? 'checked' : ''}> ${label}</label>`;
}

function option(value, label, current) {
    return `<option value="${escapeHtml(value)}" ${String(value) === String(current) ? 'selected' : ''}>${escapeHtml(label)}</option>`;
}

function numberValue(input) {
    return Number(input.value);
}

function clamp(value, min, max) {
    const number = Number(value);
    if (!Number.isFinite(number)) return min;
    return Math.min(max, Math.max(min, number));
}

function clampSpeed(value) {
    return Math.round(clamp(value, 0.1, 8) * 1000) / 1000;
}

function formatFilter(name, value) {
    const number = Number(value) || 0;
    if (name === 'blur') return number.toFixed(1);
    return String(Math.round(number));
}

document.addEventListener('click', (event) => {
    if (event.target.closest && event.target.closest('#btnResetFilters')) {
        transact((project) => {
            project.filters = { brightness: 0, contrast: 0, saturation: 0, grayscale: 0, blur: 0, sepia: 0 };
        });
    }
});
