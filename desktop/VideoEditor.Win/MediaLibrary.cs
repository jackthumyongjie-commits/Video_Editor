using System.Diagnostics;
using System.Globalization;
using System.Text;
using System.Text.Json.Nodes;

namespace VideoEditor;

internal sealed class MediaLibrary
{
    public const long MaxVideoSize = 512L * 1024 * 1024;
    public const long MaxImageSize = 25L * 1024 * 1024;
    public const long MaxAudioSize = 80L * 1024 * 1024;
    public const double MinClipDuration = 0.1;

    private readonly AppPaths _paths;
    private readonly FfmpegTools _tools;

    public MediaLibrary(AppPaths paths, FfmpegTools tools)
    {
        _paths = paths;
        _tools = tools;
    }

    public static string ExtensionKind(string ext)
    {
        ext = ext.ToLowerInvariant();
        if (ext is "mp4" or "webm" or "mov" or "wmv") return "video";
        if (ext is "jpg" or "jpeg" or "png" or "webp") return "image";
        if (ext is "mp3" or "wav" or "aac" or "m4a") return "audio";
        return "";
    }

    public static long MaxSize(string kind) => kind switch
    {
        "video" => MaxVideoSize,
        "image" => MaxImageSize,
        "audio" => MaxAudioSize,
        _ => 0,
    };

    public JsonObject? LoadMeta(string id)
    {
        if (!JsonUtil.IsId(id))
        {
            return null;
        }
        return JsonUtil.ReadObject(MetaPath(id));
    }

    public string MediaFile(JsonObject meta)
    {
        var id = JsonUtil.Str(meta["id"]);
        var ext = JsonUtil.Str(meta["ext"]).ToLowerInvariant();
        var kind = JsonUtil.Str(meta["kind"]);
        if (!JsonUtil.IsId(id) || ext.Length is < 1 or > 5)
        {
            throw new AppException("Invalid media path.");
        }
        var folder = Path.Combine(_paths.UploadDirectory, JsonUtil.KindFolder(kind));
        return Path.Combine(folder, id + "." + ext);
    }

    public string ThumbFile(string id)
    {
        if (!JsonUtil.IsId(id))
        {
            throw new AppException("Invalid media path.");
        }
        return Path.Combine(_paths.UploadDirectory, "thumbs", id + "_thumb.jpg");
    }

    public string PreviewFile(string id)
    {
        if (!JsonUtil.IsId(id))
        {
            throw new AppException("Invalid media path.");
        }
        return Path.Combine(_paths.UploadDirectory, "videos", id + "_preview.mp4");
    }

    public JsonArray List()
    {
        var dir = Path.Combine(_paths.UploadDirectory, "meta");
        var items = new List<JsonObject>();
        if (Directory.Exists(dir))
        {
            foreach (var file in Directory.EnumerateFiles(dir, "*.json"))
            {
                var name = Path.GetFileNameWithoutExtension(file);
                if (!JsonUtil.IsId(name))
                {
                    continue;
                }
                var meta = LoadMeta(name);
                if (meta != null)
                {
                    items.Add(PublicMedia(meta));
                }
            }
        }
        items.Sort((a, b) => string.Compare(JsonUtil.Str(b["createdAt"]), JsonUtil.Str(a["createdAt"]), StringComparison.Ordinal));
        var array = new JsonArray();
        foreach (var item in items)
        {
            array.Add(item);
        }
        return array;
    }

    public JsonObject PublicMedia(JsonObject meta)
    {
        var id = JsonUtil.Str(meta["id"]);
        var thumb = JsonUtil.Bool(meta["thumbAvailable"]);
        var preview = JsonUtil.Bool(meta["previewAvailable"]);
        return new JsonObject
        {
            ["id"] = id,
            ["kind"] = JsonUtil.Str(meta["kind"]),
            ["originalName"] = JsonUtil.Str(meta["originalName"], "media"),
            ["ext"] = JsonUtil.Str(meta["ext"]),
            ["mime"] = JsonUtil.Str(meta["mime"], "application/octet-stream"),
            ["size"] = JsonUtil.Int(meta["size"]),
            ["duration"] = Math.Round(JsonUtil.Num(meta["duration"]), 3),
            ["width"] = JsonUtil.Int(meta["width"]),
            ["height"] = JsonUtil.Int(meta["height"]),
            ["hasAudio"] = JsonUtil.Bool(meta["hasAudio"]),
            ["hasVideo"] = JsonUtil.Bool(meta["hasVideo"]),
            ["browserPlayable"] = JsonUtil.Bool(meta["browserPlayable"]),
            ["previewAvailable"] = preview,
            ["thumbAvailable"] = thumb,
            ["createdAt"] = JsonUtil.Str(meta["createdAt"]),
            ["url"] = "api/stream.php?id=" + Uri.EscapeDataString(id),
            ["thumbUrl"] = thumb ? "api/stream.php?id=" + Uri.EscapeDataString(id) + "&thumb=1" : null,
            ["previewUrl"] = preview ? "api/stream.php?id=" + Uri.EscapeDataString(id) + "&preview=1" : null,
        };
    }

    public JsonObject ImportFile(string sourcePath, string originalName, long size)
    {
        var original = JsonUtil.SafeOriginalName(originalName);
        var ext = Path.GetExtension(original).TrimStart('.').ToLowerInvariant();
        var kind = ExtensionKind(ext);
        if (kind.Length == 0)
        {
            throw new AppException("Unsupported file type.", 415);
        }
        if (size <= 0 || size > MaxSize(kind))
        {
            throw new AppException("File is too large.", 413);
        }
        if (!MimeAllowed(kind, ext, sourcePath))
        {
            throw new AppException("Unsupported file type.", 415);
        }
        if ((kind == "video" || kind == "audio") && !_tools.FfprobeAvailable)
        {
            throw new AppException("FFprobe is unavailable.", 503);
        }

        var id = JsonUtil.NewId();
        var dest = Path.Combine(_paths.UploadDirectory, JsonUtil.KindFolder(kind), id + "." + ext);
        File.Copy(sourcePath, dest, true);
        try
        {
            var meta = new JsonObject
            {
                ["id"] = id,
                ["kind"] = kind,
                ["originalName"] = original,
                ["ext"] = ext,
                ["mime"] = JsonUtil.MimeForExtension(ext),
                ["size"] = new FileInfo(dest).Length,
                ["duration"] = 0,
                ["width"] = 0,
                ["height"] = 0,
                ["hasAudio"] = false,
                ["hasVideo"] = false,
                ["videoCodec"] = "",
                ["audioCodec"] = "",
                ["browserPlayable"] = false,
                ["previewAvailable"] = false,
                ["thumbAvailable"] = false,
                ["createdAt"] = DateTime.Now.ToString("o"),
            };

            if (kind == "image")
            {
                var probe = _tools.Probe(dest);
                meta["width"] = probe.Width;
                meta["height"] = probe.Height;
                if (probe.Width < 1 || probe.Height < 1)
                {
                    ReadImageSize(dest, meta);
                }
                if (JsonUtil.Int(meta["width"]) < 1 || JsonUtil.Int(meta["height"]) < 1)
                {
                    throw new AppException("Media could not be analysed.");
                }
                if (_tools.MakeThumbnail(dest, ThumbFile(id), 0))
                {
                    meta["thumbAvailable"] = true;
                }
            }
            else
            {
                var probe = _tools.Probe(dest);
                if (kind == "video" && !probe.HasVideo)
                {
                    throw new AppException("Media could not be analysed.");
                }
                if (kind == "audio" && !probe.HasAudio)
                {
                    throw new AppException("Media could not be analysed.");
                }
                if (probe.Duration < MinClipDuration)
                {
                    throw new AppException("Media is too short.");
                }
                meta["duration"] = Math.Round(probe.Duration, 3);
                meta["width"] = probe.Width;
                meta["height"] = probe.Height;
                meta["hasAudio"] = probe.HasAudio;
                meta["hasVideo"] = probe.HasVideo;
                meta["videoCodec"] = probe.VideoCodec;
                meta["audioCodec"] = probe.AudioCodec;
                if (kind == "video")
                {
                    var playable = BrowserCanPlay(ext, probe.VideoCodec);
                    meta["browserPlayable"] = playable;
                    if (_tools.MakeThumbnail(dest, ThumbFile(id), probe.Duration))
                    {
                        meta["thumbAvailable"] = true;
                    }
                    if (!playable && _tools.MakePreview(dest, PreviewFile(id), probe.HasAudio))
                    {
                        meta["previewAvailable"] = true;
                    }
                }
            }

            JsonUtil.WriteAtomic(MetaPath(id), meta);
            return PublicMedia(meta);
        }
        catch
        {
            TryDelete(dest);
            TryDelete(ThumbFile(id));
            TryDelete(PreviewFile(id));
            TryDelete(MetaPath(id));
            throw;
        }
    }

    public void Delete(string id)
    {
        var meta = LoadMeta(id) ?? throw new AppException("Missing media.", 404);
        TryDelete(MediaFile(meta));
        TryDelete(ThumbFile(id));
        TryDelete(PreviewFile(id));
        TryDelete(MetaPath(id));
    }

    private string MetaPath(string id) => Path.Combine(_paths.UploadDirectory, "meta", id + ".json");

    private static void TryDelete(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // Best effort cleanup.
        }
    }

    private static bool BrowserCanPlay(string ext, string codec)
    {
        var codecs = new[] { "h264", "avc1", "vp8", "vp9", "av1" };
        var extensions = new[] { "mp4", "webm", "mov" };
        return codecs.Contains(codec.ToLowerInvariant()) && extensions.Contains(ext.ToLowerInvariant());
    }

    private static bool MimeAllowed(string kind, string ext, string path)
    {
        var mime = DetectMime(path, ext);
        var allowed = kind switch
        {
            "video" => new[] { "video/mp4", "video/webm", "video/quicktime", "video/x-ms-wmv", "video/x-ms-asf", "application/octet-stream" },
            "image" => new[] { "image/jpeg", "image/png", "image/webp", "application/octet-stream" },
            "audio" => new[] { "audio/mpeg", "audio/wav", "audio/aac", "audio/mp4", "application/octet-stream" },
            _ => Array.Empty<string>(),
        };
        return allowed.Contains(mime);
    }

    private static string DetectMime(string path, string ext)
    {
        try
        {
            Span<byte> header = stackalloc byte[16];
            using var stream = File.OpenRead(path);
            var read = stream.Read(header);
            if (read >= 3 && header[0] == 0xFF && header[1] == 0xD8 && header[2] == 0xFF) return "image/jpeg";
            if (read >= 8 && header[0] == 0x89 && header[1] == 0x50 && header[2] == 0x4E && header[3] == 0x47) return "image/png";
            if (read >= 12 && header[0] == (byte)'R' && header[1] == (byte)'I' && header[2] == (byte)'F' && header[3] == (byte)'F' && header[8] == (byte)'W' && header[9] == (byte)'E' && header[10] == (byte)'B' && header[11] == (byte)'P') return "image/webp";
            if (read >= 12 && header[4] == (byte)'f' && header[5] == (byte)'t' && header[6] == (byte)'y' && header[7] == (byte)'p')
            {
                var brand = Encoding.ASCII.GetString(header.Slice(8, 4));
                if (brand.StartsWith("qt", StringComparison.Ordinal)) return "video/quicktime";
                if (brand is "M4A " or "M4B ") return "audio/mp4";
                return ext is "m4a" ? "audio/mp4" : "video/mp4";
            }
            if (read >= 4 && header[0] == 0x1A && header[1] == 0x45 && header[2] == 0xDF && header[3] == 0xA3) return "video/webm";
            if (read >= 3 && header[0] == (byte)'I' && header[1] == (byte)'D' && header[2] == (byte)'3') return "audio/mpeg";
            if (read >= 2 && header[0] == 0xFF && (header[1] & 0xE0) == 0xE0) return ext == "aac" ? "audio/aac" : "audio/mpeg";
            if (read >= 12 && header[0] == (byte)'R' && header[1] == (byte)'I' && header[2] == (byte)'F' && header[3] == (byte)'F' && header[8] == (byte)'W' && header[9] == (byte)'A' && header[10] == (byte)'V' && header[11] == (byte)'E') return "audio/wav";
            if (read >= 4 && header[0] == 0x30 && header[1] == 0x26 && header[2] == 0xB2 && header[3] == 0x75) return "video/x-ms-wmv";
            if (read >= 2 && header[0] == 0xFF && (header[1] & 0xF6) == 0xF0) return "audio/aac";
        }
        catch
        {
            return "application/octet-stream";
        }
        return "application/octet-stream";
    }

    private static void ReadImageSize(string path, JsonObject meta)
    {
        try
        {
            using var image = Image.FromFile(path);
            meta["width"] = image.Width;
            meta["height"] = image.Height;
        }
        catch
        {
            // FFprobe is the primary measurement.
        }
    }
}

internal sealed class ProbeResult
{
    public double Duration { get; init; }
    public int Width { get; init; }
    public int Height { get; init; }
    public bool HasAudio { get; init; }
    public bool HasVideo { get; init; }
    public string VideoCodec { get; init; } = "";
    public string AudioCodec { get; init; } = "";
}

internal sealed class FfmpegTools
{
    private readonly AppPaths _paths;
    private bool? _ffmpegOk;
    private bool? _ffprobeOk;
    private bool? _amixNormalize;

    public FfmpegTools(AppPaths paths)
    {
        _paths = paths;
    }

    public string? FfmpegPath => _paths.FindTool("ffmpeg");
    public string? FfprobePath => _paths.FindTool("ffprobe");
    public bool FfmpegAvailable => _ffmpegOk ??= ToolWorks(FfmpegPath, "ffmpeg");
    public bool FfprobeAvailable => _ffprobeOk ??= ToolWorks(FfprobePath, "ffprobe");

    public bool AmixSupportsNormalize
    {
        get
        {
            if (_amixNormalize != null)
            {
                return _amixNormalize.Value;
            }
            if (FfmpegPath == null)
            {
                _amixNormalize = false;
                return false;
            }
            var result = Run(FfmpegPath, "-hide_banner", "-h", "filter=amix");
            _amixNormalize = (result.Stdout + result.Stderr).Contains("normalize", StringComparison.OrdinalIgnoreCase);
            return _amixNormalize.Value;
        }
    }

    public ProbeResult Probe(string path)
    {
        if (!FfprobeAvailable || FfprobePath == null)
        {
            throw new AppException("FFprobe is unavailable.", 503);
        }
        var result = Run(FfprobePath, "-v", "error", "-print_format", "json", "-show_format", "-show_streams", path);
        if (result.Code != 0 || string.IsNullOrWhiteSpace(result.Stdout))
        {
            _paths.Log("FFprobe failed.");
            throw new AppException("Media could not be analysed.");
        }
        JsonObject? data;
        try
        {
            data = JsonNode.Parse(result.Stdout) as JsonObject;
        }
        catch
        {
            throw new AppException("Media could not be analysed.");
        }
        if (data == null)
        {
            throw new AppException("Media could not be analysed.");
        }

        var duration = JsonUtil.Num(data["format"]?["duration"]);
        var width = 0;
        var height = 0;
        var hasAudio = false;
        var hasVideo = false;
        var videoCodec = "";
        var audioCodec = "";
        foreach (var node in JsonUtil.Arr(data["streams"]))
        {
            if (node is not JsonObject stream)
            {
                continue;
            }
            var type = JsonUtil.Str(stream["codec_type"]);
            if (type == "video" && !hasVideo)
            {
                hasVideo = true;
                videoCodec = JsonUtil.Str(stream["codec_name"]).ToLowerInvariant();
                width = JsonUtil.Int(stream["width"]);
                height = JsonUtil.Int(stream["height"]);
                if (duration <= 0 && stream["duration"] != null)
                {
                    duration = JsonUtil.Num(stream["duration"]);
                }
            }
            if (type == "audio" && !hasAudio)
            {
                hasAudio = true;
                audioCodec = JsonUtil.Str(stream["codec_name"]).ToLowerInvariant();
                if (duration <= 0 && stream["duration"] != null)
                {
                    duration = JsonUtil.Num(stream["duration"]);
                }
            }
        }
        return new ProbeResult
        {
            Duration = Math.Max(0, duration),
            Width = width,
            Height = height,
            HasAudio = hasAudio,
            HasVideo = hasVideo,
            VideoCodec = videoCodec,
            AudioCodec = audioCodec,
        };
    }

    public bool MakeThumbnail(string source, string dest, double duration)
    {
        if (!FfmpegAvailable || FfmpegPath == null)
        {
            return false;
        }
        var ss = duration > 0.75 ? "0.500" : "0";
        var result = Run(FfmpegPath, "-y", "-hide_banner", "-ss", ss, "-i", source, "-frames:v", "1", "-vf", "scale=320:-2", dest);
        return result.Code == 0 && File.Exists(dest) && new FileInfo(dest).Length > 0;
    }

    public bool MakePreview(string source, string dest, bool hasAudio)
    {
        if (!FfmpegAvailable || FfmpegPath == null)
        {
            return false;
        }
        var args = new List<string> { "-y", "-hide_banner", "-i", source, "-vf", "scale=960:-2", "-c:v", "libx264", "-preset", "veryfast", "-crf", "30", "-pix_fmt", "yuv420p", "-movflags", "+faststart" };
        if (hasAudio)
        {
            args.AddRange(["-c:a", "aac", "-b:a", "128k", "-ac", "2"]);
        }
        else
        {
            args.Add("-an");
        }
        args.Add(dest);
        var result = Run(FfmpegPath, args.ToArray());
        if (result.Code != 0 || !File.Exists(dest) || new FileInfo(dest).Length == 0)
        {
            TryDelete(dest);
            _paths.Log("Preview transcode failed.");
            return false;
        }
        return true;
    }

    public ProcessResult Run(string fileName, params string[] args)
    {
        try
        {
            var info = new ProcessStartInfo
            {
                FileName = fileName,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
                WorkingDirectory = _paths.DataRoot,
            };
            foreach (var arg in args)
            {
                info.ArgumentList.Add(arg);
            }
            using var process = Process.Start(info);
            if (process == null)
            {
                return new ProcessResult(1, "", "Failed to start process");
            }
            var stdout = process.StandardOutput.ReadToEnd();
            var stderr = process.StandardError.ReadToEnd();
            process.WaitForExit();
            return new ProcessResult(process.ExitCode, stdout, stderr);
        }
        catch (Exception ex)
        {
            return new ProcessResult(1, "", ex.Message);
        }
    }

    public ProcessResult RunLogged(string fileName, IReadOnlyList<string> args, string logFile)
    {
        try
        {
            var info = new ProcessStartInfo
            {
                FileName = fileName,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
                WorkingDirectory = _paths.DataRoot,
            };
            foreach (var arg in args)
            {
                info.ArgumentList.Add(arg);
            }
            using var process = Process.Start(info);
            if (process == null)
            {
                return new ProcessResult(1, "", "Failed to start process");
            }
            var stdoutTask = process.StandardOutput.ReadToEndAsync();
            var stderrTask = process.StandardError.ReadToEndAsync();
            process.WaitForExit();
            var stderr = stderrTask.GetAwaiter().GetResult();
            _ = stdoutTask.GetAwaiter().GetResult();
            File.AppendAllText(logFile, stderr, new UTF8Encoding(false));
            return new ProcessResult(process.ExitCode, "", stderr);
        }
        catch (Exception ex)
        {
            return new ProcessResult(1, "", ex.Message);
        }
    }

    private bool ToolWorks(string? path, string needle)
    {
        if (path == null)
        {
            return false;
        }
        var result = Run(path, "-hide_banner", "-version");
        var text = (result.Stdout + result.Stderr).ToLowerInvariant();
        return result.Code == 0 && text.Contains(needle, StringComparison.Ordinal);
    }

    private static void TryDelete(string path)
    {
        try
        {
            if (File.Exists(path)) File.Delete(path);
        }
        catch
        {
            // Ignore.
        }
    }
}

internal readonly record struct ProcessResult(int Code, string Stdout, string Stderr);
