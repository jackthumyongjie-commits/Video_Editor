<?php
declare(strict_types=1);

/**
 * Central configuration for the Web-Based Video Editor.
 * Change limits and tool paths here. Do not hard-code these values elsewhere.
 */

const APP_NAME = 'Video Editor';
const APP_VERSION = '1.0.0';

/** Maximum upload sizes, in bytes. */
const MAX_VIDEO_SIZE = 512 * 1024 * 1024;
const MAX_IMAGE_SIZE = 25 * 1024 * 1024;
const MAX_AUDIO_SIZE = 80 * 1024 * 1024;

/**
 * Absolute paths to FFmpeg and FFprobe.
 * Leave empty to auto-detect bin/ffmpeg.exe, bin/ffprobe.exe, then the system PATH.
 */
const FFMPEG_PATH = '';
const FFPROBE_PATH = '';

/**
 * PHP CLI binary used to run export jobs in the background.
 * Leave empty to auto-detect XAMPP (C:\xampp\php\php.exe) or PHP_BINARY.
 */
const PHP_CLI_PATH = '';

const UPLOAD_DIRECTORY = 'uploads';
const EXPORT_DIRECTORY = 'exports';

const COMPOSITION_WIDTH = 1920;
const COMPOSITION_HEIGHT = 1080;
const COMPOSITION_FPS = 30;

const MIN_CLIP_DURATION = 0.1;
const MAX_EXPORT_SECONDS = 20 * 60;
const MAX_PROJECT_BYTES = 2 * 1024 * 1024;

const MAX_CLIPS = 40;
const MAX_IMAGES = 30;
const MAX_TEXTS = 30;
const MAX_AUDIOS = 20;

const EXPORT_JOB_TTL = 60 * 60 * 24;

function app_root(): string
{
    return dirname(__DIR__);
}

function upload_dir(): string
{
    return app_root() . DIRECTORY_SEPARATOR . UPLOAD_DIRECTORY;
}

function export_dir(): string
{
    return app_root() . DIRECTORY_SEPARATOR . EXPORT_DIRECTORY;
}

function log_dir(): string
{
    return app_root() . DIRECTORY_SEPARATOR . 'logs';
}

/** @return array<string, list<string>> */
function allowed_extensions(): array
{
    return [
        'video' => ['mp4', 'webm', 'mov', 'wmv'],
        'image' => ['jpg', 'jpeg', 'png', 'webp'],
        'audio' => ['mp3', 'wav', 'aac', 'm4a'],
    ];
}

/** @return array<string, int> */
function max_upload_sizes(): array
{
    return [
        'video' => MAX_VIDEO_SIZE,
        'image' => MAX_IMAGE_SIZE,
        'audio' => MAX_AUDIO_SIZE,
    ];
}
