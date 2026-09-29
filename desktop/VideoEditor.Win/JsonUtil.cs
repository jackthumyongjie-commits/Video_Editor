using System.Globalization;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace VideoEditor;

internal static class JsonUtil
{
    public static readonly JsonSerializerOptions WriteOptions = new()
    {
        WriteIndented = true,
        Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
    };

    public static double Num(JsonNode? node, double fallback = 0)
    {
        if (node is not JsonValue value)
        {
            return fallback;
        }
        if (value.TryGetValue<double>(out var number) && double.IsFinite(number))
        {
            return number;
        }
        if (value.TryGetValue<int>(out var integer))
        {
            return integer;
        }
        if (value.TryGetValue<long>(out var longer))
        {
            return longer;
        }
        return fallback;
    }

    public static int Int(JsonNode? node, int fallback = 0)
    {
        var number = Num(node, fallback);
        if (!double.IsFinite(number))
        {
            return fallback;
        }
        return (int)number;
    }

    public static bool Bool(JsonNode? node)
    {
        if (node is not JsonValue value)
        {
            return false;
        }
        if (value.TryGetValue<bool>(out var flag))
        {
            return flag;
        }
        return Num(node) != 0;
    }

    public static string Str(JsonNode? node, string fallback = "")
    {
        if (node is not JsonValue value)
        {
            return fallback;
        }
        if (value.TryGetValue<string>(out var text) && text != null)
        {
            return text;
        }
        return fallback;
    }

    public static JsonObject Obj(JsonNode? node)
    {
        return node as JsonObject ?? new JsonObject();
    }

    public static JsonArray Arr(JsonNode? node)
    {
        return node as JsonArray ?? new JsonArray();
    }

    public static string Ff(double value)
    {
        var formatted = value.ToString("0.000", CultureInfo.InvariantCulture);
        return formatted == "-0.000" ? "0.000" : formatted;
    }

    public static string Limit(string value, int max)
    {
        if (max < 1 || string.IsNullOrEmpty(value))
        {
            return "";
        }
        var count = 0;
        var index = 0;
        foreach (var rune in value.EnumerateRunes())
        {
            if (count >= max)
            {
                return value[..index];
            }
            count++;
            index += rune.Utf16SequenceLength;
        }
        return value;
    }

    public static bool IsId(string id) => Regex.IsMatch(id, "^[a-f0-9]{32}$");

    public static string NewId() => Guid.NewGuid().ToString("N");

    public static string SafeOriginalName(string name)
    {
        name = Path.GetFileName(name.Replace('\\', '/'));
        var clean = Regex.Replace(name, @"[^\p{L}\p{N}\s._()\-]", "");
        clean = clean.Trim();
        if (clean.Length == 0)
        {
            clean = "media";
        }
        return Limit(clean, 120);
    }

    public static string SafeDownloadName(string name)
    {
        name = SafeOriginalName(name);
        name = Regex.Replace(name, @"\s+", "-");
        name = Regex.Replace(name, @"[^A-Za-z0-9._\-]", "");
        name = name.Trim('.', '-');
        if (name.Length == 0)
        {
            name = "video";
        }
        return name + ".mp4";
    }

    public static string KindFolder(string kind) => kind switch
    {
        "video" => "videos",
        "image" => "images",
        "audio" => "audio",
        _ => throw new AppException("Invalid media path."),
    };

    public static string MimeForExtension(string ext) => ext.ToLowerInvariant() switch
    {
        "mp4" => "video/mp4",
        "webm" => "video/webm",
        "mov" => "video/quicktime",
        "wmv" => "video/x-ms-wmv",
        "jpg" or "jpeg" => "image/jpeg",
        "png" => "image/png",
        "webp" => "image/webp",
        "mp3" => "audio/mpeg",
        "wav" => "audio/wav",
        "aac" => "audio/aac",
        "m4a" => "audio/mp4",
        "css" => "text/css; charset=utf-8",
        "js" => "text/javascript; charset=utf-8",
        "html" => "text/html; charset=utf-8",
        "svg" => "image/svg+xml",
        "json" => "application/json; charset=utf-8",
        _ => "application/octet-stream",
    };

    public static string SafeUserMessage(string message)
    {
        string[] allowed =
        [
            "Unsupported file type.",
            "File is too large.",
            "Upload failed.",
            "FFmpeg is unavailable.",
            "FFprobe is unavailable.",
            "Media could not be analysed.",
            "Export failed.",
            "Export job not found.",
            "Invalid project data.",
            "Invalid media path.",
            "Missing media.",
            "Add media to the timeline before exporting.",
            "Project is too long to export.",
            "Project is too complex to export.",
            "Media is too short.",
            "A required font is not available on the server.",
            "Upload folder is not writable.",
            "Export could not be started.",
            "Invalid playback speed.",
            "Invalid request.",
            "File was only partially uploaded.",
        ];
        return allowed.Contains(message) ? message : "An unexpected error occurred.";
    }

    public static void WriteAtomic(string path, JsonNode data)
    {
        var dir = Path.GetDirectoryName(path);
        if (!string.IsNullOrEmpty(dir))
        {
            Directory.CreateDirectory(dir);
        }
        var json = data.ToJsonString(WriteOptions);
        var tmp = path + "." + Guid.NewGuid().ToString("N")[..8] + ".tmp";
        File.WriteAllText(tmp, json, new UTF8Encoding(false));
        File.Move(tmp, path, true);
    }

    public static JsonObject? ReadObject(string path)
    {
        if (!File.Exists(path))
        {
            return null;
        }
        try
        {
            var raw = File.ReadAllText(path, Encoding.UTF8);
            if (raw.Length > 0 && raw[0] == '\uFEFF')
            {
                raw = raw[1..];
            }
            return JsonNode.Parse(raw) as JsonObject;
        }
        catch
        {
            return null;
        }
    }
}

internal sealed class AppException : Exception
{
    public int Status { get; }

    public AppException(string message, int status = 400) : base(message)
    {
        Status = status;
    }
}
