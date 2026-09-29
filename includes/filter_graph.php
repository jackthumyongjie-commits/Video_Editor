<?php
declare(strict_types=1);

/**
 * Builds an FFmpeg filter graph from a sanitized project.
 * Numbers come from validated project data. Paths are internal files only.
 */

function build_export_command(array $project, string $workDir, string $output, string $progressFile): array
{
    if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
        throw new RuntimeException('Export failed.');
    }

    $ffmpeg = ffmpeg_binary();
    if ($ffmpeg === null || !ffmpeg_available()) {
        throw new RuntimeException('FFmpeg is unavailable.');
    }

    $duration = project_duration($project);
    if ($duration < MIN_CLIP_DURATION) {
        throw new RuntimeException('Add media to the timeline before exporting.');
    }

    $fps = (int) $project['fps'];
    $width = (int) $project['width'];
    $height = (int) $project['height'];
    [$outW, $outH] = export_pixel_size((string) $project['exportMode']);

    $inputArgs = [];
    $chains = [];
    $inputIndex = 0;

    $inputArgs[] = ['-f', 'lavfi', '-i', 'color=c=black:s=' . $width . 'x' . $height . ':r=' . $fps . ':d=' . ffnum($duration)];
    $inputIndex++;

    $clipInputs = [];
    foreach ($project['clips'] as $clip) {
        $meta = load_meta((string) $clip['mediaId']);
        if ($meta === null) {
            throw new RuntimeException('Missing media.');
        }
        $inputArgs[] = ['-i', media_file($meta)];
        $clipInputs[] = $inputIndex;
        $inputIndex++;
    }

    $imageInputs = [];
    foreach ($project['images'] as $image) {
        $meta = load_meta((string) $image['mediaId']);
        if ($meta === null) {
            throw new RuntimeException('Missing media.');
        }
        $inputArgs[] = ['-loop', '1', '-framerate', (string) $fps, '-t', ffnum($duration), '-i', media_file($meta)];
        $imageInputs[] = $inputIndex;
        $inputIndex++;
    }

    $audioInputs = [];
    foreach ($project['audios'] as $audio) {
        $meta = load_meta((string) $audio['mediaId']);
        if ($meta === null) {
            throw new RuntimeException('Missing media.');
        }
        $inputArgs[] = ['-i', media_file($meta)];
        $audioInputs[] = $inputIndex;
        $inputIndex++;
    }

    $chains[] = '[0:v]setsar=1,format=yuv420p[base]';
    $current = 'base';
    $step = 0;

    foreach ($project['clips'] as $i => $clip) {
        $meta = load_meta((string) $clip['mediaId']);
        if ($meta === null) {
            throw new RuntimeException('Missing media.');
        }
        $label = 'vc' . $i;
        $chains[] = '[' . $clipInputs[$i] . ':v]' . video_clip_filters($clip, $meta, $project) . '[' . $label . ']';
        $next = 'b' . $step;
        $start = (float) $clip['timelineStart'];
        $speed = max(0.1, (float) $clip['speed']);
        $end = $start + ((float) $clip['outPoint'] - (float) $clip['inPoint']) / $speed;
        $chains[] = '[' . $current . '][' . $label . ']' . overlay_centered((float) $clip['x'], (float) $clip['y'], $start, $end) . '[' . $next . ']';
        $current = $next;
        $step++;
    }

    foreach ($project['images'] as $i => $image) {
        $meta = load_meta((string) $image['mediaId']);
        if ($meta === null) {
            throw new RuntimeException('Missing media.');
        }
        $label = 'im' . $i;
        $placed = image_overlay_filters($image, $meta);
        $chains[] = '[' . $imageInputs[$i] . ':v]' . $placed['filters'] . '[' . $label . ']';
        $next = 'b' . $step;
        $start = (float) $image['timelineStart'];
        $end = $start + (float) $image['duration'];
        $chains[] = '[' . $current . '][' . $label . ']' . overlay_box($placed['centerX'], $placed['centerY'], $start, $end) . '[' . $next . ']';
        $current = $next;
        $step++;
    }

    $textIndex = 0;
    foreach ($project['texts'] as $text) {
        $start = (float) $text['timelineStart'];
        $end = $start + (float) $text['duration'];
        $font = resolve_font_file((string) $text['fontFamily'], (bool) $text['bold'], (bool) $text['italic']);
        $textPath = $workDir . DIRECTORY_SEPARATOR . 'text-' . $textIndex . '.txt';
        if (file_put_contents($textPath, (string) $text['content']) === false) {
            throw new RuntimeException('Export failed.');
        }
        $rotation = (float) $text['rotation'];
        $useRotate = $rotation > 0.05 && $rotation < 359.95;
        if (!$useRotate) {
            $next = 'tx' . $textIndex;
            $chains[] = '[' . $current . ']' . drawtext_filter($text, $font, $textPath, $start, $end) . '[' . $next . ']';
            $current = $next;
        } else {
            $label = 'tl' . $textIndex;
            $box = rotated_text_source($text, $font, $textPath, $fps, $duration);
            $chains[] = $box['source'] . '[' . $label . ']';
            $next = 'tx' . $textIndex;
            $chains[] = '[' . $current . '][' . $label . ']' . overlay_box($box['centerX'], $box['centerY'], $start, $end) . '[' . $next . ']';
            $current = $next;
        }
        $textIndex++;
    }

    $tail = final_video_filters($project, $outW, $outH);
    $chains[] = '[' . $current . ']' . implode(',', $tail) . '[vout]';

    $audioLabels = [];
    foreach ($project['clips'] as $i => $clip) {
        $meta = load_meta((string) $clip['mediaId']);
        if ($meta === null || empty($meta['hasAudio'])) {
            continue;
        }
        if (!empty($project['muteOriginal']) || !empty($clip['muted']) || (float) $clip['volume'] <= 0) {
            continue;
        }
        $label = 'va' . $i;
        $chains[] = '[' . $clipInputs[$i] . ':a]' . audio_span_filters(
            (float) $clip['inPoint'],
            (float) $clip['outPoint'],
            (float) $clip['speed'],
            (float) $clip['volume'],
            (float) $clip['timelineStart']
        ) . '[' . $label . ']';
        $audioLabels[] = $label;
    }

    foreach ($project['audios'] as $i => $audio) {
        if ((float) $audio['volume'] <= 0) {
            continue;
        }
        $label = 'aa' . $i;
        $chains[] = '[' . $audioInputs[$i] . ':a]' . audio_span_filters(
            (float) $audio['inPoint'],
            (float) $audio['outPoint'],
            1.0,
            (float) $audio['volume'],
            (float) $audio['timelineStart']
        ) . '[' . $label . ']';
        $audioLabels[] = $label;
    }

    if ($audioLabels === []) {
        $chains[] = 'anullsrc=r=48000:cl=stereo,atrim=start=0:end=' . ffnum($duration) . ',asetpts=PTS-STARTPTS[aout]';
    } elseif (count($audioLabels) === 1) {
        $chains[] = '[' . $audioLabels[0] . ']aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo[aout]';
    } else {
        $pads = '';
        foreach ($audioLabels as $label) {
            $pads .= '[' . $label . ']';
        }
        $count = count($audioLabels);
        if (amix_supports_normalize()) {
            $mix = 'amix=inputs=' . $count . ':duration=longest:dropout_transition=0:normalize=0';
        } else {
            $mix = 'amix=inputs=' . $count . ':duration=longest:dropout_transition=0,volume=' . $count;
        }
        $chains[] = $pads . $mix . ',aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo[aout]';
    }

    $scriptPath = $workDir . DIRECTORY_SEPARATOR . 'filter.txt';
    $script = implode(";\n", $chains) . "\n";
    if (file_put_contents($scriptPath, $script) === false) {
        throw new RuntimeException('Export failed.');
    }
    if (strlen($script) > 24000) {
        throw new RuntimeException('Project is too complex to export.');
    }

    $mode = (string) $project['exportMode'];
    $preset = $mode === 'high' ? 'medium' : 'veryfast';
    $crf = $mode === 'high' ? '18' : '26';
    $audioRate = $mode === 'high' ? '192k' : '128k';

    $args = [$ffmpeg, '-y', '-hide_banner', '-progress', $progressFile, '-nostats'];
    foreach ($inputArgs as $group) {
        foreach ($group as $part) {
            $args[] = $part;
        }
    }
    array_push(
        $args,
        '-filter_complex', $script,
        '-map', '[vout]',
        '-map', '[aout]',
        '-c:v', 'libx264',
        '-preset', $preset,
        '-crf', $crf,
        '-pix_fmt', 'yuv420p',
        '-r', (string) $fps,
        '-c:a', 'aac',
        '-b:a', $audioRate,
        '-ar', '48000',
        '-ac', '2',
        '-movflags', '+faststart',
        '-t', ffnum($duration),
        $output
    );

    return $args;
}

function video_clip_filters(array $clip, array $meta, array $project): string
{
    $speed = max(0.1, (float) $clip['speed']);
    $filters = [
        'trim=start=' . ffnum((float) $clip['inPoint']) . ':end=' . ffnum((float) $clip['outPoint']),
        'setpts=(PTS-STARTPTS)/' . ffnum($speed),
        'fps=' . (int) $project['fps'],
        'setsar=1',
    ];

    $crop = is_array($clip['crop'] ?? null) ? $clip['crop'] : ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0];
    $srcW = (int) ($meta['width'] ?? 0);
    $srcH = (int) ($meta['height'] ?? 0);
    $left = (int) $crop['left'];
    $top = (int) $crop['top'];
    $cropW = $srcW - $left - (int) $crop['right'];
    $cropH = $srcH - $top - (int) $crop['bottom'];
    if ($cropW % 2 !== 0) {
        $cropW--;
    }
    if ($cropH % 2 !== 0) {
        $cropH--;
    }
    if ($srcW > 0 && $srcH > 0 && $cropW >= 2 && $cropH >= 2 && ($left > 0 || $top > 0 || (int) $crop['right'] > 0 || (int) $crop['bottom'] > 0)) {
        $filters[] = 'crop=' . $cropW . ':' . $cropH . ':' . $left . ':' . $top;
    }

    $rotation = (int) $clip['rotation'];
    if ($rotation === 90) {
        $filters[] = 'transpose=1';
    } elseif ($rotation === 180) {
        $filters[] = 'transpose=1,transpose=1';
    } elseif ($rotation === 270) {
        $filters[] = 'transpose=2';
    }

    $scale = max(0.05, (float) $clip['scale']);
    $boxW = even_int((int) round((int) $project['width'] * $scale));
    $boxH = even_int((int) round((int) $project['height'] * $scale));
    $filters[] = 'scale=' . $boxW . ':' . $boxH . ':force_original_aspect_ratio=decrease:flags=bicubic';
    $filters[] = 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
    $filters[] = 'setsar=1';
    $filters[] = 'format=yuv420p';
    $filters[] = 'setpts=PTS+' . ffnum((float) $clip['timelineStart']) . '/TB';

    return implode(',', $filters);
}

/** @return array{filters: string, centerX: float, centerY: float} */
function image_overlay_filters(array $image, array $meta): array
{
    $srcW = max(1, (int) ($meta['width'] ?? 1));
    $srcH = max(1, (int) ($meta['height'] ?? 1));
    $displayW = even_int((int) round((float) $image['width'] * (float) $image['scale']));
    $displayH = even_int((int) round($displayW * ($srcH / $srcW)));
    $filters = [
        'scale=' . $displayW . ':-2',
        'format=rgba',
    ];
    $opacity = max(0.0, min(1.0, ((float) $image['opacity']) / 100));
    if ($opacity < 0.999) {
        $filters[] = 'colorchannelmixer=aa=' . sprintf('%.3f', $opacity);
    }
    $rotation = (float) $image['rotation'];
    if ($rotation > 0.05 && $rotation < 359.95) {
        $rad = sprintf('%.5f', deg2rad($rotation));
        $filters[] = "rotate={$rad}:ow='rotw({$rad})':oh='roth({$rad})':c=black@0.0";
    }
    return [
        'filters' => implode(',', $filters),
        'centerX' => (float) $image['x'] + ($displayW / 2),
        'centerY' => (float) $image['y'] + ($displayH / 2),
    ];
}

function drawtext_filter(array $text, string $font, string $textFile, float $start, float $end): string
{
    $align = (string) $text['align'];
    $x = ffnum((float) $text['x']);
    $y = ffnum((float) $text['y']);
    $xExpr = match ($align) {
        'left' => $x,
        'right' => $x . '-text_w',
        default => $x . '-text_w/2',
    };
    $alpha = sprintf('%.3f', max(0, min(1, ((float) $text['opacity']) / 100)));
    $color = ffmpeg_hex_color((string) $text['color']);

    return 'drawtext=fontfile=' . ffmpeg_filter_path($font)
        . ':textfile=' . ffmpeg_filter_path($textFile)
        . ':fontsize=' . (int) round((float) $text['fontSize'])
        . ':fontcolor=' . $color . '@' . $alpha
        . ":x={$xExpr}:y={$y}-text_h/2:"
        . enable_between($start, $end);
}

/** @return array{source: string, centerX: float, centerY: float} */
function rotated_text_source(array $text, string $font, string $textFile, int $fps, float $duration): array
{
    $len = function_exists('mb_strlen') ? mb_strlen((string) $text['content']) : strlen((string) $text['content']);
    $fontSize = (float) $text['fontSize'];
    $boxW = even_int((int) ceil(max($fontSize * 4, $fontSize * max(1, $len) * 1.3)));
    $boxH = even_int((int) ceil($fontSize * 3));
    $align = (string) $text['align'];
    $xExpr = match ($align) {
        'left' => 'w/2',
        'right' => 'w/2-text_w',
        default => '(w-text_w)/2',
    };
    $alpha = sprintf('%.3f', max(0, min(1, ((float) $text['opacity']) / 100)));
    $rad = sprintf('%.5f', deg2rad((float) $text['rotation']));
    $source = 'color=c=black@0.0:s=' . $boxW . 'x' . $boxH . ':r=' . $fps . ':d=' . ffnum($duration)
        . ',format=rgba,drawtext=fontfile=' . ffmpeg_filter_path($font)
        . ':textfile=' . ffmpeg_filter_path($textFile)
        . ':fontsize=' . (int) round($fontSize)
        . ':fontcolor=' . ffmpeg_hex_color((string) $text['color']) . '@' . $alpha
        . ":x={$xExpr}:y=(h-text_h)/2"
        . ",rotate={$rad}:ow='rotw({$rad})':oh='roth({$rad})':c=black@0.0";

    return [
        'source' => $source,
        'centerX' => (float) $text['x'],
        'centerY' => (float) $text['y'],
    ];
}

function audio_span_filters(float $in, float $out, float $speed, float $volume, float $timelineStart): string
{
    $filters = [
        'atrim=start=' . ffnum($in) . ':end=' . ffnum($out),
        'asetpts=PTS-STARTPTS',
    ];
    $tempo = atempo_chain($speed);
    if ($tempo !== '') {
        $filters[] = $tempo;
    }
    $filters[] = 'aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo';
    $filters[] = 'volume=' . sprintf('%.4f', max(0, min(1, $volume / 100)));
    $delay = (int) round(max(0, $timelineStart) * 1000);
    if ($delay > 0) {
        $filters[] = 'adelay=' . $delay . '|' . $delay;
    }
    return implode(',', $filters);
}

function atempo_chain(float $speed): string
{
    if (abs($speed - 1.0) < 0.001) {
        return '';
    }
    $parts = [];
    $remaining = $speed;
    $guard = 0;
    while ($remaining > 2.0 && $guard < 8) {
        $parts[] = 'atempo=2.0';
        $remaining /= 2.0;
        $guard++;
    }
    $guard = 0;
    while ($remaining < 0.5 && $guard < 8) {
        $parts[] = 'atempo=0.5';
        $remaining /= 0.5;
        $guard++;
    }
    $parts[] = 'atempo=' . sprintf('%.4f', max(0.5, min(2.0, $remaining)));
    return implode(',', $parts);
}

/** @return list<string> */
function final_video_filters(array $project, int $outW, int $outH): array
{
    $filters = [];
    $f = $project['filters'];
    $brightness = ((float) $f['brightness']) / 100;
    $contrast = max(0.0, 1 + ((float) $f['contrast']) / 100);
    $saturation = max(0.0, 1 + ((float) $f['saturation']) / 100);
    if (abs($brightness) > 0.0001 || abs($contrast - 1) > 0.0001 || abs($saturation - 1) > 0.0001) {
        $filters[] = 'eq=brightness=' . sprintf('%.4f', $brightness)
            . ':contrast=' . sprintf('%.4f', $contrast)
            . ':saturation=' . sprintf('%.4f', $saturation);
    }

    $gray = ((float) $f['grayscale']) / 100;
    if ($gray > 0.001) {
        $filters[] = colorchannelmixer_mix($gray, [
            [0.2126, 0.7152, 0.0722],
            [0.2126, 0.7152, 0.0722],
            [0.2126, 0.7152, 0.0722],
        ]);
    }
    $sepia = ((float) $f['sepia']) / 100;
    if ($sepia > 0.001) {
        $filters[] = colorchannelmixer_mix($sepia, [
            [0.393, 0.769, 0.189],
            [0.349, 0.686, 0.168],
            [0.272, 0.534, 0.131],
        ]);
    }
    $blur = (float) $f['blur'];
    if ($blur > 0.05) {
        $filters[] = 'gblur=sigma=' . sprintf('%.3f', $blur);
    }

    $filters[] = 'scale=' . $outW . ':' . $outH . ':force_original_aspect_ratio=decrease:flags=bicubic';
    $filters[] = 'pad=' . $outW . ':' . $outH . ':(ow-iw)/2:(oh-ih)/2:color=black';
    $filters[] = 'setsar=1';
    $filters[] = 'format=yuv420p';
    return $filters;
}

function colorchannelmixer_mix(float $amount, array $target): string
{
    $identity = [
        [1.0, 0.0, 0.0],
        [0.0, 1.0, 0.0],
        [0.0, 0.0, 1.0],
    ];
    $keys = [
        ['rr', 'rg', 'rb'],
        ['gr', 'gg', 'gb'],
        ['br', 'bg', 'bb'],
    ];
    $parts = [];
    for ($row = 0; $row < 3; $row++) {
        for ($col = 0; $col < 3; $col++) {
            $value = $identity[$row][$col] * (1 - $amount) + $target[$row][$col] * $amount;
            $parts[] = $keys[$row][$col] . '=' . sprintf('%.4f', $value);
        }
    }
    return 'colorchannelmixer=' . implode(':', $parts);
}

function overlay_centered(float $offsetX, float $offsetY, float $start, float $end): string
{
    return "overlay=x='(W-w)/2+" . ffnum($offsetX) . "':y='(H-h)/2+" . ffnum($offsetY) . "':eof_action=pass:" . enable_between($start, $end);
}

function overlay_box(float $centerX, float $centerY, float $start, float $end): string
{
    return "overlay=x='" . ffnum($centerX) . "-w/2':y='" . ffnum($centerY) . "-h/2':eof_action=pass:" . enable_between($start, $end);
}

function enable_between(float $start, float $end): string
{
    return "enable='between(t\\," . ffnum($start) . "\\," . ffnum($end) . ")'";
}

function ffmpeg_hex_color(string $hex): string
{
    $hex = strtolower($hex);
    if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) {
        return '0xFFFFFF';
    }
    return '0x' . substr($hex, 1);
}

function even_int(int $value): int
{
    $value = max(2, $value);
    if ($value % 2 !== 0) {
        $value++;
    }
    return $value;
}
