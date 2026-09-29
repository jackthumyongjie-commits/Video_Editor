using System.Globalization;
using System.Text;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace VideoEditor;

internal sealed class FilterGraphBuilder
{
    private readonly MediaLibrary _media;
    private readonly FfmpegTools _tools;

    public FilterGraphBuilder(MediaLibrary media, FfmpegTools tools)
    {
        _media = media;
        _tools = tools;
    }

    public List<string> Build(JsonObject project, string workDir, string output, string progressFile)
    {
        Directory.CreateDirectory(workDir);
        if (!_tools.FfmpegAvailable || _tools.FfmpegPath == null)
        {
            throw new AppException("FFmpeg is unavailable.", 503);
        }

        var duration = ProjectValidator.Duration(project);
        if (duration < MediaLibrary.MinClipDuration)
        {
            throw new AppException("Add media to the timeline before exporting.");
        }

        var fps = JsonUtil.Int(project["fps"]);
        var width = JsonUtil.Int(project["width"]);
        var height = JsonUtil.Int(project["height"]);
        var mode = JsonUtil.Str(project["exportMode"], "fast");
        var outW = mode == "high" ? 1920 : 1280;
        var outH = mode == "high" ? 1080 : 720;

        var inputGroups = new List<List<string>>();
        var chains = new List<string>();
        var inputIndex = 0;
        inputGroups.Add(["-f", "lavfi", "-i", "color=c=black:s=" + width + "x" + height + ":r=" + fps + ":d=" + JsonUtil.Ff(duration)]);
        inputIndex++;

        var clipInputs = new List<int>();
        foreach (var node in JsonUtil.Arr(project["clips"]))
        {
            var clip = (JsonObject)node!;
            inputGroups.Add(["-i", RequireFile(JsonUtil.Str(clip["mediaId"]))]);
            clipInputs.Add(inputIndex++);
        }
        var imageInputs = new List<int>();
        foreach (var node in JsonUtil.Arr(project["images"]))
        {
            var image = (JsonObject)node!;
            inputGroups.Add(["-loop", "1", "-framerate", fps.ToString(CultureInfo.InvariantCulture), "-t", JsonUtil.Ff(duration), "-i", RequireFile(JsonUtil.Str(image["mediaId"]))]);
            imageInputs.Add(inputIndex++);
        }
        var audioInputs = new List<int>();
        foreach (var node in JsonUtil.Arr(project["audios"]))
        {
            var audio = (JsonObject)node!;
            inputGroups.Add(["-i", RequireFile(JsonUtil.Str(audio["mediaId"]))]);
            audioInputs.Add(inputIndex++);
        }

        chains.Add("[0:v]setsar=1,format=yuv420p[base]");
        var current = "base";
        var step = 0;
        var clipIndex = 0;
        foreach (var node in JsonUtil.Arr(project["clips"]))
        {
            var clip = (JsonObject)node!;
            var meta = RequireMeta(JsonUtil.Str(clip["mediaId"]));
            var label = "vc" + clipIndex;
            chains.Add("[" + clipInputs[clipIndex] + ":v]" + VideoClipFilters(clip, meta, project) + "[" + label + "]");
            var next = "b" + step;
            var start = JsonUtil.Num(clip["timelineStart"]);
            var speed = Math.Max(0.1, JsonUtil.Num(clip["speed"], 1));
            var end = start + (JsonUtil.Num(clip["outPoint"]) - JsonUtil.Num(clip["inPoint"])) / speed;
            chains.Add("[" + current + "][" + label + "]" + OverlayCentered(JsonUtil.Num(clip["x"]), JsonUtil.Num(clip["y"]), start, end) + "[" + next + "]");
            current = next;
            step++;
            clipIndex++;
        }

        var imageIndex = 0;
        foreach (var node in JsonUtil.Arr(project["images"]))
        {
            var image = (JsonObject)node!;
            var meta = RequireMeta(JsonUtil.Str(image["mediaId"]));
            var label = "im" + imageIndex;
            var placed = ImageOverlayFilters(image, meta);
            chains.Add("[" + imageInputs[imageIndex] + ":v]" + placed.Filters + "[" + label + "]");
            var next = "b" + step;
            var start = JsonUtil.Num(image["timelineStart"]);
            var end = start + JsonUtil.Num(image["duration"]);
            chains.Add("[" + current + "][" + label + "]" + OverlayBox(placed.CenterX, placed.CenterY, start, end) + "[" + next + "]");
            current = next;
            step++;
            imageIndex++;
        }

        var textIndex = 0;
        foreach (var node in JsonUtil.Arr(project["texts"]))
        {
            var text = (JsonObject)node!;
            var start = JsonUtil.Num(text["timelineStart"]);
            var end = start + JsonUtil.Num(text["duration"]);
            var font = ResolveFont(JsonUtil.Str(text["fontFamily"]), JsonUtil.Bool(text["bold"]), JsonUtil.Bool(text["italic"]));
            var textPath = Path.Combine(workDir, "text-" + textIndex + ".txt");
            File.WriteAllText(textPath, JsonUtil.Str(text["content"]), new UTF8Encoding(false));
            var rotation = JsonUtil.Num(text["rotation"]);
            var useRotate = rotation > 0.05 && rotation < 359.95;
            if (!useRotate)
            {
                var next = "tx" + textIndex;
                chains.Add("[" + current + "]" + DrawText(text, font, textPath, start, end) + "[" + next + "]");
                current = next;
            }
            else
            {
                var label = "tl" + textIndex;
                var box = RotatedText(text, font, textPath, fps, duration);
                chains.Add(box.Source + "[" + label + "]");
                var next = "tx" + textIndex;
                chains.Add("[" + current + "][" + label + "]" + OverlayBox(box.CenterX, box.CenterY, start, end) + "[" + next + "]");
                current = next;
            }
            textIndex++;
        }

        chains.Add("[" + current + "]" + string.Join(",", FinalVideoFilters(project, outW, outH)) + "[vout]");

        var audioLabels = new List<string>();
        clipIndex = 0;
        foreach (var node in JsonUtil.Arr(project["clips"]))
        {
            var clip = (JsonObject)node!;
            var meta = RequireMeta(JsonUtil.Str(clip["mediaId"]));
            if (JsonUtil.Bool(meta["hasAudio"]) && !JsonUtil.Bool(project["muteOriginal"]) && !JsonUtil.Bool(clip["muted"]) && JsonUtil.Num(clip["volume"]) > 0)
            {
                var label = "va" + clipIndex;
                chains.Add("[" + clipInputs[clipIndex] + ":a]" + AudioSpan(JsonUtil.Num(clip["inPoint"]), JsonUtil.Num(clip["outPoint"]), JsonUtil.Num(clip["speed"]), JsonUtil.Num(clip["volume"]), JsonUtil.Num(clip["timelineStart"])) + "[" + label + "]");
                audioLabels.Add(label);
            }
            clipIndex++;
        }
        var audioIndex = 0;
        foreach (var node in JsonUtil.Arr(project["audios"]))
        {
            var audio = (JsonObject)node!;
            if (JsonUtil.Num(audio["volume"]) > 0)
            {
                var label = "aa" + audioIndex;
                chains.Add("[" + audioInputs[audioIndex] + ":a]" + AudioSpan(JsonUtil.Num(audio["inPoint"]), JsonUtil.Num(audio["outPoint"]), 1, JsonUtil.Num(audio["volume"]), JsonUtil.Num(audio["timelineStart"])) + "[" + label + "]");
                audioLabels.Add(label);
            }
            audioIndex++;
        }

        if (audioLabels.Count == 0)
        {
            chains.Add("anullsrc=r=48000:cl=stereo,atrim=start=0:end=" + JsonUtil.Ff(duration) + ",asetpts=PTS-STARTPTS[aout]");
        }
        else if (audioLabels.Count == 1)
        {
            chains.Add("[" + audioLabels[0] + "]aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo[aout]");
        }
        else
        {
            var pads = string.Concat(audioLabels.Select(label => "[" + label + "]"));
            var mix = _tools.AmixSupportsNormalize
                ? "amix=inputs=" + audioLabels.Count + ":duration=longest:dropout_transition=0:normalize=0"
                : "amix=inputs=" + audioLabels.Count + ":duration=longest:dropout_transition=0,volume=" + audioLabels.Count;
            chains.Add(pads + mix + ",aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo[aout]");
        }

        var script = string.Join(";\n", chains) + "\n";
        File.WriteAllText(Path.Combine(workDir, "filter.txt"), script, new UTF8Encoding(false));
        if (Encoding.UTF8.GetByteCount(script) > 24000)
        {
            throw new AppException("Project is too complex to export.");
        }

        var preset = mode == "high" ? "medium" : "veryfast";
        var crf = mode == "high" ? "18" : "26";
        var audioRate = mode == "high" ? "192k" : "128k";
        var args = new List<string> { _tools.FfmpegPath, "-y", "-hide_banner", "-progress", progressFile, "-nostats" };
        foreach (var group in inputGroups)
        {
            args.AddRange(group);
        }
        args.AddRange([
            "-filter_complex", script,
            "-map", "[vout]",
            "-map", "[aout]",
            "-c:v", "libx264",
            "-preset", preset,
            "-crf", crf,
            "-pix_fmt", "yuv420p",
            "-r", fps.ToString(CultureInfo.InvariantCulture),
            "-c:a", "aac",
            "-b:a", audioRate,
            "-ar", "48000",
            "-ac", "2",
            "-movflags", "+faststart",
            "-t", JsonUtil.Ff(duration),
            output,
        ]);
        return args;
    }

    private string RequireFile(string id)
    {
        var meta = RequireMeta(id);
        var path = _media.MediaFile(meta);
        if (!File.Exists(path))
        {
            throw new AppException("Missing media.");
        }
        return path;
    }

    private JsonObject RequireMeta(string id) => _media.LoadMeta(id) ?? throw new AppException("Missing media.");

    private static string VideoClipFilters(JsonObject clip, JsonObject meta, JsonObject project)
    {
        var speed = Math.Max(0.1, JsonUtil.Num(clip["speed"], 1));
        var filters = new List<string>
        {
            "trim=start=" + JsonUtil.Ff(JsonUtil.Num(clip["inPoint"])) + ":end=" + JsonUtil.Ff(JsonUtil.Num(clip["outPoint"])),
            "setpts=(PTS-STARTPTS)/" + JsonUtil.Ff(speed),
            "fps=" + JsonUtil.Int(project["fps"]),
            "setsar=1",
        };
        var crop = JsonUtil.Obj(clip["crop"]);
        var srcW = JsonUtil.Int(meta["width"]);
        var srcH = JsonUtil.Int(meta["height"]);
        var left = JsonUtil.Int(crop["left"]);
        var top = JsonUtil.Int(crop["top"]);
        var right = JsonUtil.Int(crop["right"]);
        var bottom = JsonUtil.Int(crop["bottom"]);
        var cropW = srcW - left - right;
        var cropH = srcH - top - bottom;
        if (cropW % 2 != 0) cropW--;
        if (cropH % 2 != 0) cropH--;
        if (srcW > 0 && srcH > 0 && cropW >= 2 && cropH >= 2 && (left > 0 || top > 0 || right > 0 || bottom > 0))
        {
            filters.Add("crop=" + cropW + ":" + cropH + ":" + left + ":" + top);
        }
        var rotation = JsonUtil.Int(clip["rotation"]);
        if (rotation == 90) filters.Add("transpose=1");
        else if (rotation == 180) filters.Add("transpose=1,transpose=1");
        else if (rotation == 270) filters.Add("transpose=2");

        var scale = Math.Max(0.05, JsonUtil.Num(clip["scale"], 1));
        var boxW = Even((int)Math.Round(JsonUtil.Int(project["width"]) * scale));
        var boxH = Even((int)Math.Round(JsonUtil.Int(project["height"]) * scale));
        filters.Add("scale=" + boxW + ":" + boxH + ":force_original_aspect_ratio=decrease:flags=bicubic");
        filters.Add("scale=trunc(iw/2)*2:trunc(ih/2)*2");
        filters.Add("setsar=1");
        filters.Add("format=yuv420p");
        filters.Add("setpts=PTS+" + JsonUtil.Ff(JsonUtil.Num(clip["timelineStart"])) + "/TB");
        return string.Join(",", filters);
    }

    private static (string Filters, double CenterX, double CenterY) ImageOverlayFilters(JsonObject image, JsonObject meta)
    {
        var srcW = Math.Max(1, JsonUtil.Int(meta["width"], 1));
        var srcH = Math.Max(1, JsonUtil.Int(meta["height"], 1));
        var displayW = Even((int)Math.Round(JsonUtil.Num(image["width"]) * JsonUtil.Num(image["scale"], 1)));
        var displayH = Even((int)Math.Round(displayW * (srcH / (double)srcW)));
        var filters = new List<string> { "scale=" + displayW + ":-2", "format=rgba" };
        var opacity = Math.Clamp(JsonUtil.Num(image["opacity"]) / 100, 0, 1);
        if (opacity < 0.999)
        {
            filters.Add("colorchannelmixer=aa=" + opacity.ToString("0.000", CultureInfo.InvariantCulture));
        }
        var rotation = JsonUtil.Num(image["rotation"]);
        if (rotation > 0.05 && rotation < 359.95)
        {
            var rad = (rotation * Math.PI / 180).ToString("0.00000", CultureInfo.InvariantCulture);
            filters.Add("rotate=" + rad + ":ow='rotw(" + rad + ")':oh='roth(" + rad + ")':c=black@0.0");
        }
        return (string.Join(",", filters), JsonUtil.Num(image["x"]) + displayW / 2, JsonUtil.Num(image["y"]) + displayH / 2);
    }

    private static string DrawText(JsonObject text, string font, string textFile, double start, double end)
    {
        var align = JsonUtil.Str(text["align"]);
        var x = JsonUtil.Ff(JsonUtil.Num(text["x"]));
        var y = JsonUtil.Ff(JsonUtil.Num(text["y"]));
        var xExpr = align switch
        {
            "left" => x,
            "right" => x + "-text_w",
            _ => x + "-text_w/2",
        };
        var alpha = Math.Clamp(JsonUtil.Num(text["opacity"]) / 100, 0, 1).ToString("0.000", CultureInfo.InvariantCulture);
        return "drawtext=fontfile=" + FilterPath(font)
            + ":textfile=" + FilterPath(textFile)
            + ":fontsize=" + (int)Math.Round(JsonUtil.Num(text["fontSize"]))
            + ":fontcolor=" + HexColor(JsonUtil.Str(text["color"])) + "@" + alpha
            + ":x=" + xExpr + ":y=" + y + "-text_h/2:"
            + EnableBetween(start, end);
    }

    private static (string Source, double CenterX, double CenterY) RotatedText(JsonObject text, string font, string textFile, int fps, double duration)
    {
        var content = JsonUtil.Str(text["content"]);
        var len = content.EnumerateRunes().Count();
        var fontSize = JsonUtil.Num(text["fontSize"]);
        var boxW = Even((int)Math.Ceiling(Math.Max(fontSize * 4, fontSize * Math.Max(1, len) * 1.3)));
        var boxH = Even((int)Math.Ceiling(fontSize * 3));
        var align = JsonUtil.Str(text["align"]);
        var xExpr = align switch
        {
            "left" => "w/2",
            "right" => "w/2-text_w",
            _ => "(w-text_w)/2",
        };
        var alpha = Math.Clamp(JsonUtil.Num(text["opacity"]) / 100, 0, 1).ToString("0.000", CultureInfo.InvariantCulture);
        var rad = (JsonUtil.Num(text["rotation"]) * Math.PI / 180).ToString("0.00000", CultureInfo.InvariantCulture);
        var source = "color=c=black@0.0:s=" + boxW + "x" + boxH + ":r=" + fps + ":d=" + JsonUtil.Ff(duration)
            + ",format=rgba,drawtext=fontfile=" + FilterPath(font)
            + ":textfile=" + FilterPath(textFile)
            + ":fontsize=" + (int)Math.Round(fontSize)
            + ":fontcolor=" + HexColor(JsonUtil.Str(text["color"])) + "@" + alpha
            + ":x=" + xExpr + ":y=(h-text_h)/2"
            + ",rotate=" + rad + ":ow='rotw(" + rad + ")':oh='roth(" + rad + ")':c=black@0.0";
        return (source, JsonUtil.Num(text["x"]), JsonUtil.Num(text["y"]));
    }

    private static string AudioSpan(double inPoint, double outPoint, double speed, double volume, double timelineStart)
    {
        var filters = new List<string>
        {
            "atrim=start=" + JsonUtil.Ff(inPoint) + ":end=" + JsonUtil.Ff(outPoint),
            "asetpts=PTS-STARTPTS",
        };
        var tempo = Atempo(speed);
        if (tempo.Length > 0)
        {
            filters.Add(tempo);
        }
        filters.Add("aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo");
        filters.Add("volume=" + Math.Clamp(volume / 100, 0, 1).ToString("0.0000", CultureInfo.InvariantCulture));
        var delay = (int)Math.Round(Math.Max(0, timelineStart) * 1000);
        if (delay > 0)
        {
            filters.Add("adelay=" + delay + "|" + delay);
        }
        return string.Join(",", filters);
    }

    private static string Atempo(double speed)
    {
        if (Math.Abs(speed - 1) < 0.001)
        {
            return "";
        }
        var parts = new List<string>();
        var remaining = speed;
        var guard = 0;
        while (remaining > 2 && guard < 8)
        {
            parts.Add("atempo=2.0");
            remaining /= 2;
            guard++;
        }
        guard = 0;
        while (remaining < 0.5 && guard < 8)
        {
            parts.Add("atempo=0.5");
            remaining /= 0.5;
            guard++;
        }
        parts.Add("atempo=" + Math.Clamp(remaining, 0.5, 2).ToString("0.0000", CultureInfo.InvariantCulture));
        return string.Join(",", parts);
    }

    private static List<string> FinalVideoFilters(JsonObject project, int outW, int outH)
    {
        var filters = new List<string>();
        var f = JsonUtil.Obj(project["filters"]);
        var brightness = JsonUtil.Num(f["brightness"]) / 100;
        var contrast = Math.Max(0, 1 + JsonUtil.Num(f["contrast"]) / 100);
        var saturation = Math.Max(0, 1 + JsonUtil.Num(f["saturation"]) / 100);
        if (Math.Abs(brightness) > 0.0001 || Math.Abs(contrast - 1) > 0.0001 || Math.Abs(saturation - 1) > 0.0001)
        {
            filters.Add("eq=brightness=" + brightness.ToString("0.0000", CultureInfo.InvariantCulture)
                + ":contrast=" + contrast.ToString("0.0000", CultureInfo.InvariantCulture)
                + ":saturation=" + saturation.ToString("0.0000", CultureInfo.InvariantCulture));
        }
        var gray = JsonUtil.Num(f["grayscale"]) / 100;
        if (gray > 0.001)
        {
            filters.Add(Mixer(gray, [[0.2126, 0.7152, 0.0722], [0.2126, 0.7152, 0.0722], [0.2126, 0.7152, 0.0722]]));
        }
        var sepia = JsonUtil.Num(f["sepia"]) / 100;
        if (sepia > 0.001)
        {
            filters.Add(Mixer(sepia, [[0.393, 0.769, 0.189], [0.349, 0.686, 0.168], [0.272, 0.534, 0.131]]));
        }
        var blur = JsonUtil.Num(f["blur"]);
        if (blur > 0.05)
        {
            filters.Add("gblur=sigma=" + blur.ToString("0.000", CultureInfo.InvariantCulture));
        }
        filters.Add("scale=" + outW + ":" + outH + ":force_original_aspect_ratio=decrease:flags=bicubic");
        filters.Add("pad=" + outW + ":" + outH + ":(ow-iw)/2:(oh-ih)/2:color=black");
        filters.Add("setsar=1");
        filters.Add("format=yuv420p");
        return filters;
    }

    private static string Mixer(double amount, double[][] target)
    {
        double[][] identity = [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
        string[][] keys = [["rr", "rg", "rb"], ["gr", "gg", "gb"], ["br", "bg", "bb"]];
        var parts = new List<string>();
        for (var row = 0; row < 3; row++)
        {
            for (var col = 0; col < 3; col++)
            {
                var value = identity[row][col] * (1 - amount) + target[row][col] * amount;
                parts.Add(keys[row][col] + "=" + value.ToString("0.0000", CultureInfo.InvariantCulture));
            }
        }
        return "colorchannelmixer=" + string.Join(":", parts);
    }

    private static string OverlayCentered(double offsetX, double offsetY, double start, double end)
    {
        return "overlay=x='(W-w)/2+" + JsonUtil.Ff(offsetX) + "':y='(H-h)/2+" + JsonUtil.Ff(offsetY) + "':eof_action=pass:" + EnableBetween(start, end);
    }

    private static string OverlayBox(double centerX, double centerY, double start, double end)
    {
        return "overlay=x='" + JsonUtil.Ff(centerX) + "-w/2':y='" + JsonUtil.Ff(centerY) + "-h/2':eof_action=pass:" + EnableBetween(start, end);
    }

    private static string EnableBetween(double start, double end)
    {
        return "enable='between(t\\," + JsonUtil.Ff(start) + "\\," + JsonUtil.Ff(end) + ")'";
    }

    private static string HexColor(string hex)
    {
        hex = hex.ToLowerInvariant();
        return Regex.IsMatch(hex, "^#[0-9a-f]{6}$") ? "0x" + hex[1..] : "0xFFFFFF";
    }

    private static int Even(int value)
    {
        value = Math.Max(2, value);
        if (value % 2 != 0) value++;
        return value;
    }

    private static string FilterPath(string path)
    {
        path = path.Replace('\\', '/').Replace(":", "\\:").Replace("'", "'\\''");
        return "'" + path + "'";
    }

    private static string ResolveFont(string family, bool bold, bool italic)
    {
        var map = new Dictionary<string, Dictionary<string, string>>(StringComparer.Ordinal)
        {
            ["Arial"] = new() { ["regular"] = "arial.ttf", ["bold"] = "arialbd.ttf", ["italic"] = "ariali.ttf", ["boldItalic"] = "arialbi.ttf" },
            ["Verdana"] = new() { ["regular"] = "verdana.ttf", ["bold"] = "verdanab.ttf", ["italic"] = "verdanai.ttf", ["boldItalic"] = "verdanaz.ttf" },
            ["Georgia"] = new() { ["regular"] = "georgia.ttf", ["bold"] = "georgiab.ttf", ["italic"] = "georgiai.ttf", ["boldItalic"] = "georgiaz.ttf" },
            ["Times New Roman"] = new() { ["regular"] = "times.ttf", ["bold"] = "timesbd.ttf", ["italic"] = "timesi.ttf", ["boldItalic"] = "timesbi.ttf" },
            ["Courier New"] = new() { ["regular"] = "cour.ttf", ["bold"] = "courbd.ttf", ["italic"] = "couri.ttf", ["boldItalic"] = "courbi.ttf" },
        };
        if (!map.ContainsKey(family)) family = "Arial";
        var style = bold && italic ? "boldItalic" : bold ? "bold" : italic ? "italic" : "regular";
        string[] names = [map[family][style], map["Arial"][style], "arial.ttf"];
        var fonts = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Windows), "Fonts");
        foreach (var name in names.Distinct(StringComparer.OrdinalIgnoreCase))
        {
            var path = Path.Combine(fonts, name);
            if (File.Exists(path))
            {
                return path;
            }
        }
        throw new AppException("A required font is not available on the server.");
    }
}
