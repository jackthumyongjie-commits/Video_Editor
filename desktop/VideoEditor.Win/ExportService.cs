using System.Globalization;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace VideoEditor;

internal sealed class ExportService
{
    private const int JobTtlSeconds = 60 * 60 * 24;
    private readonly AppPaths _paths;
    private readonly FfmpegTools _tools;
    private readonly FilterGraphBuilder _graph;

    public ExportService(AppPaths paths, FfmpegTools tools, FilterGraphBuilder graph)
    {
        _paths = paths;
        _tools = tools;
        _graph = graph;
    }

    public string Create(JsonObject project)
    {
        if (!_tools.FfmpegAvailable)
        {
            throw new AppException("FFmpeg is unavailable.", 503);
        }
        if (!_paths.DirectoriesWritable())
        {
            throw new AppException("Upload folder is not writable.", 500);
        }
        Cleanup();
        var id = JsonUtil.NewId();
        Directory.CreateDirectory(JobDir(id));
        var job = new JsonObject
        {
            ["id"] = id,
            ["status"] = "queued",
            ["progress"] = 0,
            ["createdAt"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
            ["startedAt"] = null,
            ["finishedAt"] = null,
            ["error"] = "",
            ["duration"] = Math.Round(ProjectValidator.Duration(project), 3),
            ["name"] = JsonUtil.Str(project["name"]),
            ["project"] = project,
        };
        Save(job);
        _ = Task.Run(() => Run(id));
        return id;
    }

    public JsonObject Status(string id)
    {
        var job = Load(id) ?? throw new AppException("Export job not found.", 404);
        var created = (long)JsonUtil.Num(job["createdAt"], DateTimeOffset.UtcNow.ToUnixTimeSeconds());
        if (JsonUtil.Str(job["status"]) == "queued" && DateTimeOffset.UtcNow.ToUnixTimeSeconds() - created > 20)
        {
            job["status"] = "error";
            job["error"] = "Export could not be started.";
            job["finishedAt"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            Save(job);
        }

        var status = JsonUtil.Str(job["status"], "queued");
        var progress = 0;
        if (status == "running")
        {
            var rendered = ReadProgress(ProgressFile(id));
            var duration = Math.Max(0.001, JsonUtil.Num(job["duration"], 1));
            progress = (int)Math.Min(99, Math.Max(1, Math.Round(rendered / duration * 100)));
        }
        else if (status == "done")
        {
            progress = 100;
        }

        var started = (long)JsonUtil.Num(job["startedAt"] ?? job["createdAt"], DateTimeOffset.UtcNow.ToUnixTimeSeconds());
        var finished = (long)JsonUtil.Num(job["finishedAt"], DateTimeOffset.UtcNow.ToUnixTimeSeconds());
        var error = JsonUtil.Str(job["error"]);
        return new JsonObject
        {
            ["ok"] = status != "error",
            ["message"] = status == "error" ? (error.Length > 0 ? error : "Export failed.") : "OK",
            ["job"] = id,
            ["status"] = status,
            ["progress"] = progress,
            ["elapsed"] = Math.Max(0, finished - started),
            ["error"] = error,
            ["download"] = status == "done" ? "api/download.php?job=" + Uri.EscapeDataString(id) : null,
        };
    }

    public string OutputFile(string id)
    {
        var job = Load(id);
        if (job == null || JsonUtil.Str(job["status"]) != "done")
        {
            throw new AppException("Export job not found.", 404);
        }
        var path = Path.GetFullPath(Path.Combine(_paths.ExportDirectory, "output", id + ".mp4"));
        var root = Path.GetFullPath(_paths.ExportDirectory + Path.DirectorySeparatorChar + "output" + Path.DirectorySeparatorChar);
        if (!path.StartsWith(root, StringComparison.OrdinalIgnoreCase) || !File.Exists(path))
        {
            throw new AppException("Export job not found.", 404);
        }
        return path;
    }

    public string DownloadName(string id)
    {
        var job = Load(id);
        return JsonUtil.SafeDownloadName(job == null ? "video" : JsonUtil.Str(job["name"], "video"));
    }

    private void Run(string id)
    {
        var job = Load(id);
        if (job == null || JsonUtil.Str(job["status"]) != "queued")
        {
            return;
        }
        job["status"] = "running";
        job["startedAt"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        job["error"] = "";
        Save(job);

        var output = Path.Combine(_paths.ExportDirectory, "output", id + ".mp4");
        var progress = ProgressFile(id);
        var log = Path.Combine(JobDir(id), "export.log");
        try
        {
            if (File.Exists(output)) File.Delete(output);
            var project = job["project"] as JsonObject ?? throw new AppException("Invalid project data.");
            var args = _graph.Build(project, JobDir(id), output, progress);
            File.AppendAllText(log, string.Join(" ", args.Select(Quote)) + Environment.NewLine);
            var result = _tools.RunLogged(args[0], args.Skip(1).ToList(), log);
            if (result.Code != 0 || !File.Exists(output) || new FileInfo(output).Length == 0)
            {
                if (File.Exists(output)) File.Delete(output);
                throw new AppException(Classify(result.Stderr));
            }
            var fresh = Load(id) ?? job;
            fresh["status"] = "done";
            fresh["progress"] = 100;
            fresh["finishedAt"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            fresh["error"] = "";
            fresh["bytes"] = new FileInfo(output).Length;
            Save(fresh);
        }
        catch (Exception ex)
        {
            _paths.Log("Export job failed: " + ex.Message);
            var fresh = Load(id) ?? job;
            fresh["status"] = "error";
            fresh["finishedAt"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            var safe = JsonUtil.SafeUserMessage(ex.Message);
            fresh["error"] = safe == "An unexpected error occurred." ? "Export failed." : safe;
            Save(fresh);
        }
    }

    private JsonObject? Load(string id)
    {
        if (!JsonUtil.IsId(id)) return null;
        return JsonUtil.ReadObject(Path.Combine(_paths.ExportDirectory, "jobs", id + ".json"));
    }

    private void Save(JsonObject job)
    {
        var id = JsonUtil.Str(job["id"]);
        JsonUtil.WriteAtomic(Path.Combine(_paths.ExportDirectory, "jobs", id + ".json"), job);
    }

    private string JobDir(string id) => Path.Combine(_paths.ExportDirectory, "jobs", id);
    private string ProgressFile(string id) => Path.Combine(JobDir(id), "progress.txt");

    private void Cleanup()
    {
        var dir = Path.Combine(_paths.ExportDirectory, "jobs");
        if (!Directory.Exists(dir)) return;
        var now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        foreach (var file in Directory.EnumerateFiles(dir, "*.json"))
        {
            var id = Path.GetFileNameWithoutExtension(file);
            if (!JsonUtil.IsId(id)) continue;
            var job = Load(id);
            var status = job == null ? "" : JsonUtil.Str(job["status"]);
            if (status is "running" or "queued") continue;
            var created = job == null ? File.GetLastWriteTimeUtc(file).Ticks : (long)JsonUtil.Num(job["createdAt"]);
            if (job == null)
            {
                created = new DateTimeOffset(File.GetLastWriteTimeUtc(file)).ToUnixTimeSeconds();
            }
            if (now - created < JobTtlSeconds) continue;
            try
            {
                var output = Path.Combine(_paths.ExportDirectory, "output", id + ".mp4");
                if (File.Exists(output)) File.Delete(output);
                if (Directory.Exists(JobDir(id))) Directory.Delete(JobDir(id), true);
                File.Delete(file);
            }
            catch
            {
                // Leave locked files for the next cleanup.
            }
        }
    }

    private static double ReadProgress(string file)
    {
        if (!File.Exists(file)) return 0;
        string text;
        try
        {
            text = File.ReadAllText(file);
        }
        catch
        {
            return 0;
        }
        var us = Regex.Matches(text, @"out_time_us=(\d+)");
        if (us.Count > 0) return int.Parse(us[^1].Groups[1].Value, CultureInfo.InvariantCulture) / 1000000d;
        var ms = Regex.Matches(text, @"out_time_ms=(\d+)");
        if (ms.Count > 0)
        {
            var raw = long.Parse(ms[^1].Groups[1].Value, CultureInfo.InvariantCulture);
            return raw > 100000 ? raw / 1000000d : raw / 1000d;
        }
        var clock = Regex.Matches(text, @"out_time=(\d+):(\d+):([0-9.]+)");
        if (clock.Count > 0)
        {
            var last = clock[^1];
            return int.Parse(last.Groups[1].Value) * 3600 + int.Parse(last.Groups[2].Value) * 60 + double.Parse(last.Groups[3].Value, CultureInfo.InvariantCulture);
        }
        return 0;
    }

    private static string Classify(string stderr)
    {
        var text = stderr.ToLowerInvariant();
        if (text.Contains("no such file") || text.Contains("does not exist")) return "Missing media.";
        if (text.Contains("font file") || text.Contains("cannot find a valid font")) return "A required font is not available on the server.";
        return "Export failed.";
    }

    private static string Quote(string value) => "\"" + value.Replace("\"", "\\\"") + "\"";
}
