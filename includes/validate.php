<?php
declare(strict_types=1);

class ValidationException extends RuntimeException
{
}

function sanitize_project(array $input): array
{
    $source = $input;
    if (isset($input['project']) && is_array($input['project'])) {
        $source = $input['project'];
    }

    $name = trim((string) ($source['name'] ?? 'Untitled Project'));
    if ($name === '') {
        $name = 'Untitled Project';
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 80);
    } else {
        $name = substr($name, 0, 80);
    }

    $fps = (int) ($source['fps'] ?? COMPOSITION_FPS);
    if (!in_array($fps, [24, 25, 30, 60], true)) {
        throw new ValidationException('Invalid project data.');
    }

    $width = (int) ($source['width'] ?? COMPOSITION_WIDTH);
    $height = (int) ($source['height'] ?? COMPOSITION_HEIGHT);
    if ($width < 160 || $height < 160 || $width > 3840 || $height > 2160 || ($width % 2) !== 0 || ($height % 2) !== 0) {
        throw new ValidationException('Invalid project data.');
    }

    $mode = (string) ($source['exportMode'] ?? 'fast');
    if (!in_array($mode, ['fast', 'high'], true)) {
        $mode = 'fast';
    }

    $filtersIn = is_array($source['filters'] ?? null) ? $source['filters'] : [];
    $project = [
        'name' => $name,
        'fps' => $fps,
        'width' => $width,
        'height' => $height,
        'clips' => [],
        'images' => [],
        'texts' => [],
        'audios' => [],
        'filters' => [
            'brightness' => clamp_number($filtersIn['brightness'] ?? 0, -100, 100),
            'contrast' => clamp_number($filtersIn['contrast'] ?? 0, -100, 100),
            'saturation' => clamp_number($filtersIn['saturation'] ?? 0, -100, 100),
            'grayscale' => clamp_number($filtersIn['grayscale'] ?? 0, 0, 100),
            'blur' => clamp_number($filtersIn['blur'] ?? 0, 0, 20),
            'sepia' => clamp_number($filtersIn['sepia'] ?? 0, 0, 100),
        ],
        'muteOriginal' => (bool) ($source['muteOriginal'] ?? false),
        'exportMode' => $mode,
    ];

    $clips = is_array($source['clips'] ?? null) ? $source['clips'] : [];
    $images = is_array($source['images'] ?? null) ? $source['images'] : [];
    $texts = is_array($source['texts'] ?? null) ? $source['texts'] : [];
    $audios = is_array($source['audios'] ?? null) ? $source['audios'] : [];

    if (count($clips) > MAX_CLIPS || count($images) > MAX_IMAGES || count($texts) > MAX_TEXTS || count($audios) > MAX_AUDIOS) {
        throw new ValidationException('Invalid project data.');
    }

    $seen = [];
    foreach ($clips as $clip) {
        if (!is_array($clip)) {
            throw new ValidationException('Invalid project data.');
        }
        $project['clips'][] = sanitize_clip($clip, $seen);
    }
    foreach ($images as $image) {
        if (!is_array($image)) {
            throw new ValidationException('Invalid project data.');
        }
        $project['images'][] = sanitize_image($image, $seen);
    }
    foreach ($texts as $text) {
        if (!is_array($text)) {
            throw new ValidationException('Invalid project data.');
        }
        $project['texts'][] = sanitize_text($text, $seen);
    }
    foreach ($audios as $audio) {
        if (!is_array($audio)) {
            throw new ValidationException('Invalid project data.');
        }
        $project['audios'][] = sanitize_audio($audio, $seen);
    }

    if ($project['clips'] === [] && $project['images'] === [] && $project['texts'] === [] && $project['audios'] === []) {
        throw new ValidationException('Add media to the timeline before exporting.');
    }
    if ($project['clips'] === [] && $project['images'] === [] && $project['texts'] === []) {
        throw new ValidationException('Add media to the timeline before exporting.');
    }

    $duration = project_duration($project);
    if ($duration < MIN_CLIP_DURATION) {
        throw new ValidationException('Add media to the timeline before exporting.');
    }
    if ($duration > MAX_EXPORT_SECONDS) {
        throw new ValidationException('Project is too long to export.');
    }

    return $project;
}

function sanitize_clip(array $clip, array &$seen): array
{
    $id = require_element_id($clip, $seen);
    $mediaId = require_media_id($clip);
    $meta = require_media_kind($mediaId, 'video');
    $duration = (float) ($meta['duration'] ?? 0);
    if ($duration < MIN_CLIP_DURATION) {
        throw new ValidationException('Media is too short.');
    }

    $speed = normalize_speed($clip['speed'] ?? 1);
    $minSource = MIN_CLIP_DURATION * $speed;
    if ($duration < $minSource) {
        throw new ValidationException('Media is too short.');
    }

    $in = (float) ($clip['inPoint'] ?? 0);
    $out = (float) ($clip['outPoint'] ?? $duration);
    $in = max(0.0, min($in, $duration - $minSource));
    $out = max($in + $minSource, min($out, $duration));

    $rotation = (int) ($clip['rotation'] ?? 0);
    if (!in_array($rotation, [0, 90, 180, 270], true)) {
        throw new ValidationException('Invalid project data.');
    }

    $cropIn = is_array($clip['crop'] ?? null) ? $clip['crop'] : [];

    return [
        'id' => $id,
        'mediaId' => $mediaId,
        'name' => element_name($clip, (string) $meta['originalName']),
        'timelineStart' => clamp_number($clip['timelineStart'] ?? 0, 0, MAX_EXPORT_SECONDS),
        'inPoint' => round($in, 3),
        'outPoint' => round($out, 3),
        'speed' => $speed,
        'volume' => clamp_number($clip['volume'] ?? 100, 0, 100),
        'muted' => (bool) ($clip['muted'] ?? false),
        'x' => clamp_number($clip['x'] ?? 0, -4000, 4000),
        'y' => clamp_number($clip['y'] ?? 0, -4000, 4000),
        'scale' => clamp_number($clip['scale'] ?? 1, 0.05, 5),
        'rotation' => $rotation,
        'crop' => clamp_crop($cropIn, (int) ($meta['width'] ?? 0), (int) ($meta['height'] ?? 0)),
    ];
}

function sanitize_image(array $image, array &$seen): array
{
    $id = require_element_id($image, $seen);
    $mediaId = require_media_id($image);
    $meta = require_media_kind($mediaId, 'image');
    $duration = clamp_number($image['duration'] ?? 5, MIN_CLIP_DURATION, MAX_EXPORT_SECONDS);
    $rotation = clamp_number($image['rotation'] ?? 0, 0, 360);

    return [
        'id' => $id,
        'mediaId' => $mediaId,
        'name' => element_name($image, (string) $meta['originalName']),
        'timelineStart' => clamp_number($image['timelineStart'] ?? 0, 0, MAX_EXPORT_SECONDS),
        'duration' => round($duration, 3),
        'x' => clamp_number($image['x'] ?? 40, -4000, 4000),
        'y' => clamp_number($image['y'] ?? 40, -4000, 4000),
        'width' => clamp_number($image['width'] ?? 480, 16, 3840),
        'scale' => clamp_number($image['scale'] ?? 1, 0.05, 5),
        'opacity' => clamp_number($image['opacity'] ?? 100, 0, 100),
        'rotation' => round($rotation, 2),
    ];
}

function sanitize_text(array $text, array &$seen): array
{
    $id = require_element_id($text, $seen);
    $content = trim((string) ($text['content'] ?? ''));
    $content = preg_replace("/[\r\n]+/", ' ', $content) ?? '';
    if ($content === '') {
        throw new ValidationException('Invalid project data.');
    }
    if (function_exists('mb_substr')) {
        $content = mb_substr($content, 0, 160);
    } else {
        $content = substr($content, 0, 160);
    }

    $family = (string) ($text['fontFamily'] ?? 'Arial');
    if (!in_array($family, font_families(), true)) {
        $family = 'Arial';
    }
    $align = (string) ($text['align'] ?? 'center');
    if (!in_array($align, ['left', 'center', 'right'], true)) {
        $align = 'center';
    }
    $color = strtolower((string) ($text['color'] ?? '#ffffff'));
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
        throw new ValidationException('Invalid project data.');
    }

    return [
        'id' => $id,
        'content' => $content,
        'fontFamily' => $family,
        'fontSize' => clamp_number($text['fontSize'] ?? 64, 8, 256),
        'color' => $color,
        'bold' => (bool) ($text['bold'] ?? false),
        'italic' => (bool) ($text['italic'] ?? false),
        'align' => $align,
        'x' => clamp_number($text['x'] ?? COMPOSITION_WIDTH / 2, -4000, 4000),
        'y' => clamp_number($text['y'] ?? COMPOSITION_HEIGHT / 2, -4000, 4000),
        'timelineStart' => clamp_number($text['timelineStart'] ?? 0, 0, MAX_EXPORT_SECONDS),
        'duration' => clamp_number($text['duration'] ?? 5, MIN_CLIP_DURATION, MAX_EXPORT_SECONDS),
        'opacity' => clamp_number($text['opacity'] ?? 100, 0, 100),
        'rotation' => round(clamp_number($text['rotation'] ?? 0, 0, 360), 2),
    ];
}

function sanitize_audio(array $audio, array &$seen): array
{
    $id = require_element_id($audio, $seen);
    $mediaId = require_media_id($audio);
    $meta = require_media_kind($mediaId, 'audio');
    $duration = (float) ($meta['duration'] ?? 0);
    if ($duration < MIN_CLIP_DURATION) {
        throw new ValidationException('Media is too short.');
    }
    $in = max(0.0, min((float) ($audio['inPoint'] ?? 0), $duration - MIN_CLIP_DURATION));
    $out = max($in + MIN_CLIP_DURATION, min((float) ($audio['outPoint'] ?? $duration), $duration));
    $track = (int) ($audio['track'] ?? 1);
    if ($track < 1 || $track > 8) {
        $track = 1;
    }

    return [
        'id' => $id,
        'mediaId' => $mediaId,
        'name' => element_name($audio, (string) $meta['originalName']),
        'timelineStart' => clamp_number($audio['timelineStart'] ?? 0, 0, MAX_EXPORT_SECONDS),
        'inPoint' => round($in, 3),
        'outPoint' => round($out, 3),
        'volume' => clamp_number($audio['volume'] ?? 100, 0, 100),
        'track' => $track,
    ];
}

function require_element_id(array $item, array &$seen): string
{
    $id = (string) ($item['id'] ?? '');
    if (!is_valid_id($id) || isset($seen[$id])) {
        throw new ValidationException('Invalid project data.');
    }
    $seen[$id] = true;
    return $id;
}

function require_media_id(array $item): string
{
    $id = (string) ($item['mediaId'] ?? '');
    if (!is_valid_id($id)) {
        throw new ValidationException('Invalid project data.');
    }
    return $id;
}

function require_media_kind(string $id, string $kind): array
{
    $meta = load_meta($id);
    if ($meta === null || ($meta['kind'] ?? '') !== $kind) {
        throw new ValidationException('Missing media.');
    }
    try {
        $path = media_file($meta);
    } catch (Throwable) {
        throw new ValidationException('Missing media.');
    }
    if (!is_file($path)) {
        throw new ValidationException('Missing media.');
    }
    return $meta;
}

function element_name(array $item, string $fallback): string
{
    $name = trim((string) ($item['name'] ?? $fallback));
    if ($name === '') {
        $name = $fallback !== '' ? $fallback : 'Clip';
    }
    return safe_original_name($name);
}

function normalize_speed(mixed $value): float
{
    $speed = round((float) $value, 3);
    if ($speed < 0.1 || $speed > 8) {
        throw new ValidationException('Invalid playback speed.');
    }
    return $speed;
}

function clamp_number(mixed $value, float $min, float $max): float
{
    $number = (float) $value;
    if (!is_finite($number)) {
        throw new ValidationException('Invalid project data.');
    }
    return max($min, min($max, $number));
}

function clamp_crop(array $crop, int $width, int $height): array
{
    $top = max(0, (int) ($crop['top'] ?? 0));
    $right = max(0, (int) ($crop['right'] ?? 0));
    $bottom = max(0, (int) ($crop['bottom'] ?? 0));
    $left = max(0, (int) ($crop['left'] ?? 0));
    if ($width < 4 || $height < 4) {
        return ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0];
    }
    if ($left + $right > $width - 2) {
        $right = max(0, min($right, $width - 2));
        $left = max(0, min($left, $width - 2 - $right));
    }
    if ($top + $bottom > $height - 2) {
        $bottom = max(0, min($bottom, $height - 2));
        $top = max(0, min($top, $height - 2 - $bottom));
    }
    return ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left];
}

function project_duration(array $project): float
{
    $end = 0.0;
    foreach ($project['clips'] as $clip) {
        $speed = max(0.1, (float) $clip['speed']);
        $end = max($end, (float) $clip['timelineStart'] + ((float) $clip['outPoint'] - (float) $clip['inPoint']) / $speed);
    }
    foreach ($project['images'] as $image) {
        $end = max($end, (float) $image['timelineStart'] + (float) $image['duration']);
    }
    foreach ($project['texts'] as $text) {
        $end = max($end, (float) $text['timelineStart'] + (float) $text['duration']);
    }
    foreach ($project['audios'] as $audio) {
        $end = max($end, (float) $audio['timelineStart'] + ((float) $audio['outPoint'] - (float) $audio['inPoint']));
    }
    return $end;
}

/** @return list<string> */
function font_families(): array
{
    return ['Arial', 'Verdana', 'Georgia', 'Times New Roman', 'Courier New'];
}
