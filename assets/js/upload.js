import { t, translateMessage } from './i18n.js';
import { loadLibrary } from './media.js';
import { formatBytes, toast } from './util.js';

let rules = {
    video: 512 * 1024 * 1024,
    image: 25 * 1024 * 1024,
    audio: 80 * 1024 * 1024,
};
let extensions = {
    video: ['mp4', 'webm', 'mov', 'wmv'],
    image: ['jpg', 'jpeg', 'png', 'webp'],
    audio: ['mp3', 'wav', 'aac', 'm4a'],
};

export function setUploadRules(health) {
    if (health?.limits) rules = health.limits;
    if (health?.extensions) extensions = health.extensions;
}

export function initUpload() {
    const input = document.getElementById('fileInput');
    const browse = document.getElementById('btnBrowse');
    const dropzone = document.getElementById('dropzone');
    browse.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
        uploadFiles(input.files);
        input.value = '';
    });
    ['dragenter', 'dragover'].forEach((name) => {
        dropzone.addEventListener(name, (event) => {
            event.preventDefault();
            dropzone.classList.add('dragover');
        });
    });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        dropzone.classList.remove('dragover');
        uploadFiles(event.dataTransfer.files);
    });
    window.addEventListener('dragover', (event) => event.preventDefault());
    window.addEventListener('drop', (event) => event.preventDefault());
}

async function uploadFiles(fileList) {
    const files = Array.from(fileList || []);
    if (files.length === 0) return;
    for (const file of files) {
        const error = validateFile(file);
        if (error) {
            toast(t(error), 'danger');
            continue;
        }
        try {
            await uploadOne(file);
        } catch (error) {
            toast(translateMessage(error.message) || t('err.upload'), 'danger');
        }
    }
    try {
        await loadLibrary();
    } catch (error) {
        toast(translateMessage(error.message) || t('err.refresh'), 'danger');
    }
}

function validateFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    const kind = Object.keys(extensions).find((key) => extensions[key].includes(ext));
    if (!kind) return 'err.unsupported';
    if (file.size <= 0 || file.size > rules[kind]) return 'err.tooLarge';
    return '';
}

function uploadOne(file) {
    return new Promise((resolve, reject) => {
        const body = new FormData();
        body.append('file', file);
        const request = new XMLHttpRequest();
        const wrap = document.getElementById('uploadProgressWrap');
        const bar = document.getElementById('uploadProgressBar');
        const label = document.getElementById('uploadProgressLabel');
        wrap.hidden = false;
        label.textContent = t('uploadingFile', { name: file.name });
        bar.style.width = '0%';
        bar.textContent = '0%';
        request.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) return;
            const percent = Math.round((event.loaded / event.total) * 100);
            bar.style.width = `${percent}%`;
            bar.textContent = `${percent}%`;
        });
        request.addEventListener('load', () => {
            wrap.hidden = true;
            let data = {};
            try {
                data = JSON.parse(request.responseText || '{}');
            } catch {
                reject(new Error('Upload failed.'));
                return;
            }
            if (request.status >= 200 && request.status < 300 && data.ok) {
                toast(translateMessage(data.message) || t('err.uploadOk'), 'success');
                resolve(data.media);
                return;
            }
            reject(new Error(data.message || 'Upload failed.'));
        });
        request.addEventListener('error', () => {
            wrap.hidden = true;
            reject(new Error('Upload failed.'));
        });
        request.open('POST', 'api/upload.php');
        request.send(body);
    });
}

export function limitLabel() {
    return t('limitLabel', {
        video: formatBytes(rules.video),
        image: formatBytes(rules.image),
        audio: formatBytes(rules.audio),
    });
}
