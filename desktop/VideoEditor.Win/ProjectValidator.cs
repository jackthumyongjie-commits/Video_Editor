using System.Globalization;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace VideoEditor;

internal sealed class ProjectValidator
{
    public const int MaxClips = 40;
    public const int MaxImages = 30;
    public const int MaxTexts = 30;
    public const int MaxAudios = 20;
    public const double MaxExportSeconds = 20 * 60;

    private static readonly string[] Families = ["Arial", "Verdana", "Georgia", "Times New Roman", "Courier New"];
    private readonly MediaLibrary _media;

    public ProjectValidator(MediaLibrary media)
    {
        _media = media;
    }

    public JsonObject Sanitize(JsonObject input)
    {
        var source = input["project"] as JsonObject ?? input;
        var name = JsonUtil.Str(source["name"], "Untitled Project").Trim();
        if (name.Length == 0)
        {
            name = "Untitled Project";
        }
        name = JsonUtil.Limit(name, 80);

        var fps = JsonUtil.Int(source["fps"], 30);
        if (fps is not (24 or 25 or 30 or 60))
        {
            throw new AppException("Invalid project data.");
        }
        var width = JsonUtil.Int(source["width"], 1920);
        var height = JsonUtil.Int(source["height"], 1080);
        if (width < 160 || height < 160 || width > 3840 || height > 2160 || width % 2 != 0 || height % 2 != 0)
        {
            throw new AppException("Invalid project data.");
        }
        var mode = JsonUtil.Str(source["exportMode"], "fast");
        if (mode is not ("fast" or "high"))
        {
            mode = "fast";
        }

        var filtersIn = JsonUtil.Obj(source["filters"]);
        var project = new JsonObject
        {
            ["name"] = name,
            ["fps"] = fps,
            ["width"] = width,
            ["height"] = height,
            ["clips"] = new JsonArray(),
            ["images"] = new JsonArray(),
            ["texts"] = new JsonArray(),
            ["audios"] = new JsonArray(),
            ["filters"] = new JsonObject
            {
                ["brightness"] = Clamp(filtersIn["brightness"], -100, 100),
                ["contrast"] = Clamp(filtersIn["contrast"], -100, 100),
                ["saturation"] = Clamp(filtersIn["saturation"], -100, 100),
                ["grayscale"] = Clamp(filtersIn["grayscale"], 0, 100),
                ["blur"] = Clamp(filtersIn["blur"], 0, 20),
                ["sepia"] = Clamp(filtersIn["sepia"], 0, 100),
            },
            ["muteOriginal"] = JsonUtil.Bool(source["muteOriginal"]),
            ["exportMode"] = mode,
        };

        var clips = JsonUtil.Arr(source["clips"]);
        var images = JsonUtil.Arr(source["images"]);
        var texts = JsonUtil.Arr(source["texts"]);
        var audios = JsonUtil.Arr(source["audios"]);
        if (clips.Count > MaxClips || images.Count > MaxImages || texts.Count > MaxTexts || audios.Count > MaxAudios)
        {
            throw new AppException("Invalid project data.");
        }

        var seen = new HashSet<string>(StringComparer.Ordinal);
        foreach (var node in clips)
        {
            if (node is not JsonObject item) throw new AppException("Invalid project data.");
            project["clips"]!.AsArray().Add(SanitizeClip(item, seen));
        }
        foreach (var node in images)
        {
            if (node is not JsonObject item) throw new AppException("Invalid project data.");
            project["images"]!.AsArray().Add(SanitizeImage(item, seen));
        }
        foreach (var node in texts)
        {
            if (node is not JsonObject item) throw new AppException("Invalid project data.");
            project["texts"]!.AsArray().Add(SanitizeText(item, seen, width, height));
        }
        foreach (var node in audios)
        {
            if (node is not JsonObject item) throw new AppException("Invalid project data.");
            project["audios"]!.AsArray().Add(SanitizeAudio(item, seen));
        }

        if (project["clips"]!.AsArray().Count == 0 && project["images"]!.AsArray().Count == 0 && project["texts"]!.AsArray().Count == 0)
        {
            throw new AppException("Add media to the timeline before exporting.");
        }
        var duration = Duration(project);
        if (duration < MediaLibrary.MinClipDuration)
        {
            throw new AppException("Add media to the timeline before exporting.");
        }
        if (duration > MaxExportSeconds)
        {
            throw new AppException("Project is too long to export.");
        }
        return project;
    }

    public static double Duration(JsonObject project)
    {
        var end = 0.0;
        foreach (var node in JsonUtil.Arr(project["clips"]))
        {
            if (node is not JsonObject clip) continue;
            var speed = Math.Max(0.1, JsonUtil.Num(clip["speed"], 1));
            end = Math.Max(end, JsonUtil.Num(clip["timelineStart"]) + (JsonUtil.Num(clip["outPoint"]) - JsonUtil.Num(clip["inPoint"])) / speed);
        }
        foreach (var node in JsonUtil.Arr(project["images"]))
        {
            if (node is not JsonObject image) continue;
            end = Math.Max(end, JsonUtil.Num(image["timelineStart"]) + JsonUtil.Num(image["duration"]));
        }
        foreach (var node in JsonUtil.Arr(project["texts"]))
        {
            if (node is not JsonObject text) continue;
            end = Math.Max(end, JsonUtil.Num(text["timelineStart"]) + JsonUtil.Num(text["duration"]));
        }
        foreach (var node in JsonUtil.Arr(project["audios"]))
        {
            if (node is not JsonObject audio) continue;
            end = Math.Max(end, JsonUtil.Num(audio["timelineStart"]) + (JsonUtil.Num(audio["outPoint"]) - JsonUtil.Num(audio["inPoint"])));
        }
        return end;
    }

    private JsonObject SanitizeClip(JsonObject clip, HashSet<string> seen)
    {
        var id = RequireElementId(clip, seen);
        var mediaId = RequireMediaId(clip);
        var meta = RequireKind(mediaId, "video");
        var duration = JsonUtil.Num(meta["duration"]);
        if (duration < MediaLibrary.MinClipDuration)
        {
            throw new AppException("Media is too short.");
        }
        var speed = NormalizeSpeed(clip["speed"]);
        var minSource = MediaLibrary.MinClipDuration * speed;
        if (duration < minSource)
        {
            throw new AppException("Media is too short.");
        }
        var inPoint = JsonUtil.Num(clip["inPoint"]);
        var outPoint = clip["outPoint"] == null ? duration : JsonUtil.Num(clip["outPoint"]);
        inPoint = Math.Max(0, Math.Min(inPoint, duration - minSource));
        outPoint = Math.Max(inPoint + minSource, Math.Min(outPoint, duration));
        var rotation = JsonUtil.Int(clip["rotation"]);
        if (rotation is not (0 or 90 or 180 or 270))
        {
            throw new AppException("Invalid project data.");
        }
        return new JsonObject
        {
            ["id"] = id,
            ["mediaId"] = mediaId,
            ["name"] = ElementName(clip, JsonUtil.Str(meta["originalName"])),
            ["timelineStart"] = Clamp(clip["timelineStart"], 0, MaxExportSeconds),
            ["inPoint"] = Math.Round(inPoint, 3),
            ["outPoint"] = Math.Round(outPoint, 3),
            ["speed"] = speed,
            ["volume"] = Clamp(clip["volume"] ?? 100, 0, 100),
            ["muted"] = JsonUtil.Bool(clip["muted"]),
            ["x"] = Clamp(clip["x"], -4000, 4000),
            ["y"] = Clamp(clip["y"], -4000, 4000),
            ["scale"] = Clamp(clip["scale"] ?? 1, 0.05, 5),
            ["rotation"] = rotation,
            ["crop"] = ClampCrop(JsonUtil.Obj(clip["crop"]), JsonUtil.Int(meta["width"]), JsonUtil.Int(meta["height"])),
        };
    }

    private JsonObject SanitizeImage(JsonObject image, HashSet<string> seen)
    {
        var id = RequireElementId(image, seen);
        var mediaId = RequireMediaId(image);
        var meta = RequireKind(mediaId, "image");
        return new JsonObject
        {
            ["id"] = id,
            ["mediaId"] = mediaId,
            ["name"] = ElementName(image, JsonUtil.Str(meta["originalName"])),
            ["timelineStart"] = Clamp(image["timelineStart"], 0, MaxExportSeconds),
            ["duration"] = Math.Round(Clamp(image["duration"] ?? 5, MediaLibrary.MinClipDuration, MaxExportSeconds), 3),
            ["x"] = Clamp(image["x"] ?? 40, -4000, 4000),
            ["y"] = Clamp(image["y"] ?? 40, -4000, 4000),
            ["width"] = Clamp(image["width"] ?? 480, 16, 3840),
            ["scale"] = Clamp(image["scale"] ?? 1, 0.05, 5),
            ["opacity"] = Clamp(image["opacity"] ?? 100, 0, 100),
            ["rotation"] = Math.Round(Clamp(image["rotation"], 0, 360), 2),
        };
    }

    private JsonObject SanitizeText(JsonObject text, HashSet<string> seen, int width, int height)
    {
        var id = RequireElementId(text, seen);
        var content = Regex.Replace(JsonUtil.Str(text["content"]).Trim(), @"[\r\n]+", " ");
        if (content.Length == 0)
        {
            throw new AppException("Invalid project data.");
        }
        content = JsonUtil.Limit(content, 160);
        var family = JsonUtil.Str(text["fontFamily"], "Arial");
        if (!Families.Contains(family))
        {
            family = "Arial";
        }
        var align = JsonUtil.Str(text["align"], "center");
        if (align is not ("left" or "center" or "right"))
        {
            align = "center";
        }
        var color = JsonUtil.Str(text["color"], "#ffffff").ToLowerInvariant();
        if (!Regex.IsMatch(color, "^#[0-9a-f]{6}$"))
        {
            throw new AppException("Invalid project data.");
        }
        return new JsonObject
        {
            ["id"] = id,
            ["content"] = content,
            ["fontFamily"] = family,
            ["fontSize"] = Clamp(text["fontSize"] ?? 64, 8, 256),
            ["color"] = color,
            ["bold"] = JsonUtil.Bool(text["bold"]),
            ["italic"] = JsonUtil.Bool(text["italic"]),
            ["align"] = align,
            ["x"] = Clamp(text["x"] ?? (width / 2.0), -4000, 4000),
            ["y"] = Clamp(text["y"] ?? (height / 2.0), -4000, 4000),
            ["timelineStart"] = Clamp(text["timelineStart"], 0, MaxExportSeconds),
            ["duration"] = Clamp(text["duration"] ?? 5, MediaLibrary.MinClipDuration, MaxExportSeconds),
            ["opacity"] = Clamp(text["opacity"] ?? 100, 0, 100),
            ["rotation"] = Math.Round(Clamp(text["rotation"], 0, 360), 2),
        };
    }

    private JsonObject SanitizeAudio(JsonObject audio, HashSet<string> seen)
    {
        var id = RequireElementId(audio, seen);
        var mediaId = RequireMediaId(audio);
        var meta = RequireKind(mediaId, "audio");
        var duration = JsonUtil.Num(meta["duration"]);
        if (duration < MediaLibrary.MinClipDuration)
        {
            throw new AppException("Media is too short.");
        }
        var inPoint = Math.Max(0, Math.Min(JsonUtil.Num(audio["inPoint"]), duration - MediaLibrary.MinClipDuration));
        var outPoint = Math.Max(inPoint + MediaLibrary.MinClipDuration, Math.Min(audio["outPoint"] == null ? duration : JsonUtil.Num(audio["outPoint"]), duration));
        var track = JsonUtil.Int(audio["track"], 1);
        if (track is < 1 or > 8)
        {
            track = 1;
        }
        return new JsonObject
        {
            ["id"] = id,
            ["mediaId"] = mediaId,
            ["name"] = ElementName(audio, JsonUtil.Str(meta["originalName"])),
            ["timelineStart"] = Clamp(audio["timelineStart"], 0, MaxExportSeconds),
            ["inPoint"] = Math.Round(inPoint, 3),
            ["outPoint"] = Math.Round(outPoint, 3),
            ["volume"] = Clamp(audio["volume"] ?? 100, 0, 100),
            ["track"] = track,
        };
    }

    private JsonObject RequireKind(string id, string kind)
    {
        var meta = _media.LoadMeta(id);
        if (meta == null || JsonUtil.Str(meta["kind"]) != kind)
        {
            throw new AppException("Missing media.");
        }
        var path = _media.MediaFile(meta);
        if (!File.Exists(path))
        {
            throw new AppException("Missing media.");
        }
        return meta;
    }

    private static string RequireElementId(JsonObject item, HashSet<string> seen)
    {
        var id = JsonUtil.Str(item["id"]);
        if (!JsonUtil.IsId(id) || !seen.Add(id))
        {
            throw new AppException("Invalid project data.");
        }
        return id;
    }

    private static string RequireMediaId(JsonObject item)
    {
        var id = JsonUtil.Str(item["mediaId"]);
        if (!JsonUtil.IsId(id))
        {
            throw new AppException("Invalid project data.");
        }
        return id;
    }

    private static string ElementName(JsonObject item, string fallback)
    {
        var name = JsonUtil.Str(item["name"], fallback).Trim();
        if (name.Length == 0)
        {
            name = fallback.Length > 0 ? fallback : "Clip";
        }
        return JsonUtil.SafeOriginalName(name);
    }

    private static double NormalizeSpeed(JsonNode? value)
    {
        var speed = Math.Round(JsonUtil.Num(value, double.NaN), 3);
        if (!double.IsFinite(speed) || speed < 0.1 || speed > 8)
        {
            throw new AppException("Invalid playback speed.");
        }
        return speed;
    }

    private static double Clamp(JsonNode? value, double min, double max)
    {
        if (value == null)
        {
            return min;
        }
        var number = JsonUtil.Num(value, double.NaN);
        if (!double.IsFinite(number))
        {
            throw new AppException("Invalid project data.");
        }
        return Math.Max(min, Math.Min(max, number));
    }

    private static JsonObject ClampCrop(JsonObject crop, int width, int height)
    {
        var top = Math.Max(0, JsonUtil.Int(crop["top"]));
        var right = Math.Max(0, JsonUtil.Int(crop["right"]));
        var bottom = Math.Max(0, JsonUtil.Int(crop["bottom"]));
        var left = Math.Max(0, JsonUtil.Int(crop["left"]));
        if (width < 4 || height < 4)
        {
            return new JsonObject { ["top"] = 0, ["right"] = 0, ["bottom"] = 0, ["left"] = 0 };
        }
        if (left + right > width - 2)
        {
            right = Math.Max(0, Math.Min(right, width - 2));
            left = Math.Max(0, Math.Min(left, width - 2 - right));
        }
        if (top + bottom > height - 2)
        {
            bottom = Math.Max(0, Math.Min(bottom, height - 2));
            top = Math.Max(0, Math.Min(top, height - 2 - bottom));
        }
        return new JsonObject { ["top"] = top, ["right"] = right, ["bottom"] = bottom, ["left"] = left };
    }
}
