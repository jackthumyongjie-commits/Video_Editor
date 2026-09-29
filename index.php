<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = rtrim($basePath, '/');
if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
}
?><!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect rx='6' width='32' height='32' fill='%235b8cff'/%3E%3Cpath d='M8 10h6l2-2h8v16H8z' fill='none' stroke='white' stroke-width='2'/%3E%3C/svg%3E">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/assets/css/editor.css" rel="stylesheet">
</head>
<body>
<div class="app">
    <header class="topbar">
        <div class="brand">
            <span class="logo" aria-hidden="true"><i class="fa-solid fa-clapperboard"></i></span>
            <div>
                <strong data-i18n="appName">Video Editor</strong>
                <small data-i18n="appSubtitle">Web App</small>
            </div>
        </div>
        <label class="project-name">
            <span class="visually-hidden" data-i18n="projectName">Project name</span>
            <input id="projectName" type="text" maxlength="80" value="Untitled Project" data-i18n-aria="projectName" aria-label="Project name">
        </label>
        <div class="top-actions">
            <div class="toolbar-group" data-i18n-aria="history" aria-label="History">
                <button type="button" id="btnUndo" class="btn btn-icon" data-i18n-title="undo" title="Undo (Ctrl+Z)" disabled><i class="fa-solid fa-rotate-left"></i></button>
                <button type="button" id="btnRedo" class="btn btn-icon" data-i18n-title="redo" title="Redo (Ctrl+Y)" disabled><i class="fa-solid fa-rotate-right"></i></button>
            </div>
            <div class="toolbar-group" data-i18n-aria="projectGroup" aria-label="Project">
                <button type="button" id="btnNew" class="btn btn-soft" data-i18n-title="newTitle" title="New project"><i class="fa-solid fa-file"></i><span data-i18n="new">New</span></button>
                <button type="button" id="btnSave" class="btn btn-soft" data-i18n-title="saveTitle" title="Save in this browser (Ctrl+S)"><i class="fa-solid fa-floppy-disk"></i><span data-i18n="save">Save</span></button>
                <button type="button" id="btnLoad" class="btn btn-soft" data-i18n-title="loadTitle" title="Load project"><i class="fa-solid fa-folder-open"></i><span data-i18n="load">Load</span></button>
                <button type="button" id="btnReset" class="btn btn-soft" data-i18n-title="resetTitle" title="Reset timeline"><i class="fa-solid fa-eraser"></i><span data-i18n="reset">Reset</span></button>
            </div>
        </div>
        <div class="export-group">
            <label class="quality">
                <span class="visually-hidden" data-i18n="exportQuality">Export quality</span>
                <select id="exportMode" data-i18n-aria="exportQuality" aria-label="Export quality">
                    <option value="fast" data-i18n="fast">Fast 720p</option>
                    <option value="high" data-i18n="high">High Quality 1080p</option>
                </select>
            </label>
            <button type="button" id="btnExport" class="btn btn-export" data-i18n-title="exportMp4" title="Export MP4"><i class="fa-solid fa-file-export"></i><span data-i18n="exportMp4">Export MP4</span></button>
            <span id="ffmpegStatus" class="status-pill" data-i18n="ffmpegChecking" data-i18n-title="ffmpegStatus" title="FFmpeg status">FFmpeg: Checking</span>
            <div class="lang-switch" data-i18n-aria="language" role="group" aria-label="Language">
                <button type="button" class="lang-btn" data-lang="zh">中文</button>
                <button type="button" class="lang-btn" data-lang="en">EN</button>
            </div>
            <button type="button" class="btn btn-icon" data-i18n-title="help" title="Help" data-bs-toggle="modal" data-bs-target="#helpModal"><i class="fa-solid fa-circle-question"></i></button>
        </div>
    </header>

    <main class="workspace">
        <section class="panel library" data-i18n-aria="mediaLibrary" aria-label="Media library">
            <div class="panel-head">
                <h1 data-i18n="media">Media</h1>
                <button type="button" id="btnAddText" class="btn btn-sm btn-soft" data-i18n-title="addTextTitle" title="Add text at the playhead"><i class="fa-solid fa-font"></i> <span data-i18n="addText">Text</span></button>
            </div>
            <div id="dropzone" class="dropzone">
                <button type="button" id="btnBrowse" class="btn upload-launch">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <span data-i18n="uploadFiles">Upload files</span>
                </button>
                <input id="fileInput" type="file" hidden multiple accept=".mp4,.webm,.mov,.wmv,.jpg,.jpeg,.png,.webp,.mp3,.wav,.aac,.m4a,video/*,image/*,audio/*">
            </div>
            <div id="uploadProgressWrap" class="upload-progress" hidden>
                <span id="uploadProgressLabel" data-i18n="uploading">Uploading</span>
                <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                    <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated">0%</div>
                </div>
            </div>
            <div class="filter-tabs" role="tablist">
                <button type="button" data-filter="all" class="active" data-i18n="all">All</button>
                <button type="button" data-filter="video" data-i18n="videos">Videos</button>
                <button type="button" data-filter="image" data-i18n="images">Images</button>
                <button type="button" data-filter="audio" data-i18n="audioTab">Audio</button>
            </div>
            <div id="libraryList" class="library-list"></div>
        </section>

        <section class="panel preview-panel" data-i18n-aria="previewRegion" aria-label="Preview">
            <div class="panel-head">
                <h1 data-i18n="preview">Preview</h1>
                <span class="hint" data-i18n="previewHint">Approximate preview. Export uses FFmpeg.</span>
            </div>
            <div id="previewStage" class="preview-stage">
                <canvas id="previewCanvas" width="1920" height="1080"></canvas>
                <div id="previewEmpty" class="preview-empty" data-i18n="previewEmpty">Add media to the timeline to preview the composition.</div>
                <div id="previewBadge" class="preview-badge">1920×1080 · 30 fps</div>
            </div>
            <div class="transport">
                <div class="transport-buttons">
                    <button type="button" id="btnGoStart" class="btn btn-icon" data-i18n-title="goStart" title="Go to beginning"><i class="fa-solid fa-backward-step"></i></button>
                    <button type="button" id="btnPlay" class="btn btn-icon play" data-i18n-title="play" title="Play (Space)"><i class="fa-solid fa-play"></i></button>
                    <button type="button" id="btnPause" class="btn btn-icon" data-i18n-title="pause" title="Pause"><i class="fa-solid fa-pause"></i></button>
                    <button type="button" id="btnStop" class="btn btn-icon" data-i18n-title="stop" title="Stop"><i class="fa-solid fa-stop"></i></button>
                    <button type="button" id="btnGoEnd" class="btn btn-icon" data-i18n-title="goEnd" title="Go to end"><i class="fa-solid fa-forward-step"></i></button>
                    <button type="button" id="btnMutePreview" class="btn btn-icon" data-i18n-title="mute" title="Mute preview"><i class="fa-solid fa-volume-high"></i></button>
                </div>
                <span id="timeCurrent" class="time">00:00.0</span>
                <input id="seekBar" type="range" min="0" max="1000" value="0" data-i18n-aria="seek" aria-label="Seek">
                <span id="timeTotal" class="time">00:00.0</span>
            </div>
            <div id="mediaPool" hidden></div>
        </section>

        <aside class="panel inspector" data-i18n-aria="inspectorRegion" aria-label="Inspector">
            <div class="panel-head">
                <h1 data-i18n="inspector">Inspector</h1>
            </div>
            <div id="inspectorBody" class="inspector-body"></div>
        </aside>
    </main>

    <section class="panel timeline-panel" data-i18n-aria="timelineRegion" aria-label="Timeline">
        <div class="timeline-toolbar">
            <div class="timeline-title">
                <strong data-i18n="timeline">Timeline</strong>
                <span id="projectDuration" class="duration-badge">Empty</span>
                <span id="selectionLabel" class="hint selection-label">No selection</span>
            </div>
            <div class="timeline-tools">
                <div class="toolbar-group">
                    <button type="button" id="btnSplit" class="btn btn-sm btn-soft" data-i18n-title="splitTitle" title="Split video at playhead (S)"><i class="fa-solid fa-scissors"></i><span data-i18n="split">Split</span></button>
                    <button type="button" id="btnDeleteClip" class="btn btn-sm btn-soft" data-i18n-title="deleteItem" title="Delete selected item (Delete)"><i class="fa-solid fa-trash"></i></button>
                    <button type="button" id="btnSnap" class="btn btn-sm btn-soft" data-i18n-title="snap" title="Snap while dragging"><i class="fa-solid fa-magnet"></i></button>
                </div>
            </div>
            <label class="zoom"><span data-i18n="zoom">Zoom</span> <input id="zoomSlider" type="range" min="24" max="200" value="72" data-i18n-aria="zoomAria" aria-label="Timeline zoom"> <span id="zoomLabel">72 px/s</span></label>
        </div>
        <div class="timeline-main">
            <div class="track-labels">
                <div class="label-spacer"></div>
                <div class="track-label video"><i class="fa-solid fa-film"></i><span data-i18n="trackVideo">Video</span></div>
                <div class="track-label image"><i class="fa-solid fa-image"></i><span data-i18n="trackImage">Image</span></div>
                <div class="track-label text"><i class="fa-solid fa-font"></i><span data-i18n="trackText">Text</span></div>
                <div class="track-label audio"><i class="fa-solid fa-music"></i><span data-i18n="trackAudio">Audio</span></div>
            </div>
            <div id="timelineScroll" class="timeline-scroll">
                <div id="timelineSheet" class="timeline-sheet">
                    <div id="ruler" class="ruler"></div>
                    <div class="track" id="trackClip" data-track="clip"></div>
                    <div class="track" id="trackImage" data-track="image"></div>
                    <div class="track" id="trackText" data-track="text"></div>
                    <div class="track" id="trackAudio" data-track="audio"></div>
                    <div id="playhead" class="playhead"><div class="playhead-head"></div></div>
                </div>
            </div>
        </div>
    </section>
    <button type="button" id="sheetBackdrop" class="sheet-backdrop" hidden data-i18n-aria="close" aria-label="Close"></button>
    <nav class="mobile-dock" data-i18n-aria="dockNav" aria-label="Editor sections">
        <button type="button" data-sheet="media" aria-pressed="false">
            <i class="fa-solid fa-photo-film"></i>
            <span data-i18n="dockMedia">Media</span>
        </button>
        <button type="button" data-sheet="inspector" aria-pressed="false">
            <i class="fa-solid fa-sliders"></i>
            <span data-i18n="dockInspector">Adjust</span>
        </button>
    </nav>
</div>

<div id="toastHost" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" data-i18n="confirmTitle">Please confirm</h2><button type="button" class="btn-close" data-bs-dismiss="modal" data-i18n-aria="close" aria-label="Close"></button></div>
            <div class="modal-body"><p id="confirmMessage" class="mb-0"></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmOk" data-i18n="continue">Continue</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="loadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" data-i18n="loadProject">Load project</h2><button type="button" class="btn-close" data-bs-dismiss="modal" data-i18n-aria="close" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <button type="button" id="btnImportProject" class="btn btn-primary"><i class="fa-solid fa-file-import"></i> <span data-i18n="importJson">Import JSON</span></button>
                    <button type="button" id="btnDownloadProject" class="btn btn-outline-light"><i class="fa-solid fa-download"></i> <span data-i18n="downloadProject">Download current project</span></button>
                </div>
                <div id="snapshotList"></div>
                <input id="projectFileInput" type="file" accept="application/json,.json" hidden>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" data-i18n="exportTitle">Export MP4</h2><button type="button" class="btn-close" data-bs-dismiss="modal" data-i18n-aria="close" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="d-flex justify-content-between mb-2">
                    <strong id="exportStatusText" data-i18n="queued">Queued</strong>
                    <span class="hint"><span data-i18n="elapsed">Elapsed</span> <span id="exportElapsed">00:00</span></span>
                </div>
                <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                    <div id="exportProgressBar" class="progress-bar progress-bar-striped" style="width:0%">0%</div>
                </div>
                <p id="exportError" class="export-error"></p>
                <p class="hint mb-0" data-i18n="exportKeepOpen">Rendering continues on the server. Keep this dialog open to watch progress and download the file.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal" data-i18n="close">Close</button>
                <button type="button" id="exportDownload" class="btn btn-success" hidden><i class="fa-solid fa-download"></i> <span data-i18n="downloadMp4">Download MP4</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" data-i18n="helpTitle">How to edit</h2><button type="button" class="btn-close" data-bs-dismiss="modal" data-i18n-aria="close" aria-label="Close"></button></div>
            <div class="modal-body">
                <ol class="help-list">
                    <li data-i18n-html="help1">Upload video, images, or audio, then choose <strong>Add to Timeline</strong> or drag onto a track.</li>
                    <li data-i18n-html="help2">Drag a clip to move it. Drag its edges to trim. Press <strong>S</strong> to split a video at the playhead.</li>
                    <li data-i18n-html="help3">Select an item and edit speed, volume, crop, text, or position in the inspector.</li>
                    <li data-i18n-html="help4">Drag images and text directly on the preview. Use the corner handle to resize an image.</li>
                    <li data-i18n-html="help5">Choose Fast 720p or High Quality 1080p, then <strong>Export MP4</strong>.</li>
                </ol>
                <p class="mb-1"><strong data-i18n="shortcuts">Shortcuts</strong></p>
                <p class="hint mb-0" data-i18n="shortcutLine">Space play/pause · Home/End jump · Delete remove · Ctrl+Z undo · Ctrl+Y redo · Ctrl+S save · S split</p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script type="module" src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/app.js"></script>
</body>
</html>
