using System.Net;
using System.Text;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace VideoEditor;

internal sealed class LocalServer : IDisposable
{
    private const int MaxJsonBytes = 2 * 1024 * 1024;
    private readonly AppPaths _paths;
    private readonly MediaLibrary _media;
    private readonly ProjectValidator _validator;
    private readonly ExportService _exports;
    private readonly FfmpegTools _tools;
    private readonly HttpListener _listener = new();
    private CancellationTokenSource? _cts;

    public string Origin { get; private set; } = "";
    public int Port { get; private set; }

    public LocalServer(AppPaths paths, MediaLibrary media, ProjectValidator validator, ExportService exports, FfmpegTools tools)
    {
        _paths = paths;
        _media = media;
        _validator = validator;
        _exports = exports;
        _tools = tools;
    }

    public void Start()
    {
        Port = FreePort();
        Origin = "http://127.0.0.1:" + Port;
        _listener.Prefixes.Add(Origin + "/");
        _listener.Start();
        _cts = new CancellationTokenSource();
        _ = Task.Run(() => Loop(_cts.Token));
        File.WriteAllText(Path.Combine(_paths.LogDirectory, "port.txt"), Port.ToString());
    }

    public void Dispose()
    {
        try { _cts?.Cancel(); } catch { /* shutting down */ }
        try { _listener.Stop(); } catch { /* shutting down */ }
        _listener.Close();
    }

    private async Task Loop(CancellationToken token)
    {
        while (!token.IsCancellationRequested)
        {
            HttpListenerContext context;
            try
            {
                context = await _listener.GetContextAsync();
            }
            catch
            {
                break;
            }
            _ = Task.Run(() => Handle(context));
        }
    }

    private void Handle(HttpListenerContext context)
    {
        var request = context.Request;
        var response = context.Response;
        try
        {
            var path = Uri.UnescapeDataString(request.Url?.AbsolutePath ?? "/").Replace('\\', '/');
            if (path.Contains("..", StringComparison.Ordinal))
            {
                WriteJson(response, 400, Error("Invalid request."));
                return;
            }
            var query = Query(request.Url);
            if (path.StartsWith("/api/", StringComparison.OrdinalIgnoreCase))
            {
                RouteApi(request, response, path, query);
                return;
            }
            ServeStatic(request, response, path);
        }
        catch (AppException ex)
        {
            var safe = JsonUtil.SafeUserMessage(ex.Message);
            var status = safe == "An unexpected error occurred." ? 500 : ex.Status;
            WriteJson(response, status, Error(safe == "An unexpected error occurred." && ex.Status >= 500 ? "Export failed." : safe));
        }
        catch (Exception ex)
        {
            _paths.Log(ex.Message);
            try { WriteJson(response, 500, Error("An unexpected error occurred.")); } catch { /* response already started */ }
        }
    }

    private void RouteApi(HttpListenerRequest request, HttpListenerResponse response, string path, Dictionary<string, string> query)
    {
        var name = path["/api/".Length..].ToLowerInvariant();
        switch (name)
        {
            case "health.php":
                Health(request, response);
                return;
            case "media.php":
                Media(request, response);
                return;
            case "upload.php":
                Upload(request, response);
                return;
            case "stream.php":
                StreamMedia(request, response, query);
                return;
            case "export.php":
                Export(request, response);
                return;
            case "export_status.php":
                ExportStatus(request, response, query);
                return;
            case "download.php":
                Download(request, response, query);
                return;
            default:
                WriteJson(response, 404, Error("Invalid request."));
                return;
        }
    }

    private void Health(HttpListenerRequest request, HttpListenerResponse response)
    {
        if (request.HttpMethod != "GET")
        {
            WriteJson(response, 405, Error("Invalid request."));
            return;
        }
        var ffmpeg = _tools.FfmpegAvailable;
        var ffprobe = _tools.FfprobeAvailable;
        var writable = _paths.DirectoriesWritable();
        var message = !writable ? "Upload folder is not writable." : ffmpeg && ffprobe ? "FFmpeg: Ready" : "FFmpeg: Not Available";
        WriteJson(response, 200, new JsonObject
        {
            ["ok"] = true,
            ["message"] = message,
            ["ffmpeg"] = ffmpeg,
            ["ffprobe"] = ffprobe,
            ["writable"] = writable,
            ["limits"] = new JsonObject
            {
                ["video"] = MediaLibrary.MaxVideoSize,
                ["image"] = MediaLibrary.MaxImageSize,
                ["audio"] = MediaLibrary.MaxAudioSize,
            },
            ["extensions"] = new JsonObject
            {
                ["video"] = new JsonArray("mp4", "webm", "mov", "wmv"),
                ["image"] = new JsonArray("jpg", "jpeg", "png", "webp"),
                ["audio"] = new JsonArray("mp3", "wav", "aac", "m4a"),
            },
            ["composition"] = new JsonObject { ["width"] = 1920, ["height"] = 1080, ["fps"] = 30 },
        });
    }

    private void Media(HttpListenerRequest request, HttpListenerResponse response)
    {
        if (request.HttpMethod == "GET")
        {
            WriteJson(response, 200, new JsonObject { ["ok"] = true, ["message"] = "OK", ["media"] = _media.List() });
            return;
        }
        if (request.HttpMethod != "POST")
        {
            WriteJson(response, 405, Error("Invalid request."));
            return;
        }
        AssertSameOrigin(request);
        var body = ReadJson(request);
        var action = JsonUtil.Str(body["action"]);
        var id = JsonUtil.Str(body["id"]);
        if (action != "delete" || !JsonUtil.IsId(id))
        {
            WriteJson(response, 400, Error("Invalid request."));
            return;
        }
        _media.Delete(id);
        WriteJson(response, 200, new JsonObject { ["ok"] = true, ["message"] = "Media deleted", ["id"] = id });
    }

    private void Upload(HttpListenerRequest request, HttpListenerResponse response)
    {
        if (request.HttpMethod != "POST")
        {
            WriteJson(response, 405, Error("Invalid request."));
            return;
        }
        AssertSameOrigin(request);
        if (!_paths.DirectoriesWritable())
        {
            WriteJson(response, 500, Error("Upload folder is not writable."));
            return;
        }
        var uploaded = ReadMultipartFile(request);
        try
        {
            var media = _media.ImportFile(uploaded.TempPath, uploaded.FileName, uploaded.Size);
            WriteJson(response, 200, new JsonObject { ["ok"] = true, ["message"] = "Upload successful", ["media"] = media });
        }
        finally
        {
            try { File.Delete(uploaded.TempPath); } catch { /* temp cleanup */ }
        }
    }

    private void StreamMedia(HttpListenerRequest request, HttpListenerResponse response, Dictionary<string, string> query)
    {
        if (request.HttpMethod != "GET")
        {
            WriteJson(response, 405, Error("Invalid request."));
            return;
        }
        var id = query.GetValueOrDefault("id") ?? "";
        if (!JsonUtil.IsId(id))
        {
            WriteJson(response, 404, Error("Missing media."));
            return;
        }
        var meta = _media.LoadMeta(id) ?? throw new AppException("Missing media.", 404);
        if (query.ContainsKey("thumb"))
        {
            SendFile(request, response, _media.ThumbFile(id), "image/jpeg", "", false);
            return;
        }
        if (query.ContainsKey("preview"))
        {
            SendFile(request, response, _media.PreviewFile(id), "video/mp4", "", false);
            return;
        }
        var path = _media.MediaFile(meta);
        SendFile(request, response, path, JsonUtil.MimeForExtension(JsonUtil.Str(meta["ext"])), "", false);
    }

    private void Export(HttpListenerRequest request, HttpListenerResponse response)
    {
        if (request.HttpMethod != "POST")
        {
            WriteJson(response, 405, Error("Invalid request."));
            return;
        }
        AssertSameOrigin(request);
        var body = ReadJson(request);
        var project = _validator.Sanitize(body);
        var id = _exports.Create(project);
        WriteJson(response, 200, new JsonObject
        {
            ["ok"] = true,
            ["message"] = "Export started",
            ["job"] = id,
            ["status"] = "queued",
        });
    }

    private void ExportStatus(HttpListenerRequest request, HttpListenerResponse response, Dictionary<string, string> query)
    {
        if (request.HttpMethod != "GET")
        {
            WriteJson(response, 405, Error("Invalid request."));
            return;
        }
        var id = query.GetValueOrDefault("job") ?? "";
        if (!JsonUtil.IsId(id))
        {
            WriteJson(response, 404, Error("Export job not found."));
            return;
        }
        WriteJson(response, 200, _exports.Status(id));
    }

    private void Download(HttpListenerRequest request, HttpListenerResponse response, Dictionary<string, string> query)
    {
        if (request.HttpMethod != "GET")
        {
            WriteJson(response, 405, Error("Invalid request."));
            return;
        }
        var id = query.GetValueOrDefault("job") ?? "";
        if (!JsonUtil.IsId(id))
        {
            WriteJson(response, 404, Error("Export job not found."));
            return;
        }
        var path = _exports.OutputFile(id);
        SendFile(request, response, path, "video/mp4", _exports.DownloadName(id), true);
    }

    private void ServeStatic(HttpListenerRequest request, HttpListenerResponse response, string path)
    {
        if (request.HttpMethod != "GET" && request.HttpMethod != "HEAD")
        {
            WriteJson(response, 405, Error("Invalid request."));
            return;
        }
        if (path == "/" || path.Equals("/index.php", StringComparison.OrdinalIgnoreCase) || path.Equals("/index.html", StringComparison.OrdinalIgnoreCase))
        {
            var index = Path.Combine(_paths.WebRoot, "index.php");
            if (!File.Exists(index))
            {
                index = Path.Combine(_paths.WebRoot, "index.html");
            }
            if (!File.Exists(index))
            {
                WriteJson(response, 404, Error("Invalid request."));
                return;
            }
            var html = File.ReadAllText(index);
            html = Regex.Replace(html, @"<\?php.*?\?>", "", RegexOptions.Singleline);
            html = html.Replace("<?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?>", "Video Editor");
            html = html.Replace("<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>", "");
            var bytes = Encoding.UTF8.GetBytes(html);
            response.StatusCode = 200;
            response.ContentType = "text/html; charset=utf-8";
            response.ContentLength64 = request.HttpMethod == "HEAD" ? bytes.Length : bytes.Length;
            if (request.HttpMethod != "HEAD")
            {
                response.OutputStream.Write(bytes);
            }
            response.Close();
            return;
        }

        var relative = path.TrimStart('/').Replace('/', Path.DirectorySeparatorChar);
        var full = Path.GetFullPath(Path.Combine(_paths.WebRoot, relative));
        var root = Path.GetFullPath(_paths.WebRoot + Path.DirectorySeparatorChar);
        if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase) || !File.Exists(full))
        {
            response.StatusCode = 404;
            response.Close();
            return;
        }
        var ext = Path.GetExtension(full).TrimStart('.').ToLowerInvariant();
        if (ext is not ("css" or "js" or "png" or "jpg" or "jpeg" or "webp" or "svg" or "ico" or "woff" or "woff2" or "map" or "txt"))
        {
            response.StatusCode = 404;
            response.Close();
            return;
        }
        SendFile(request, response, full, JsonUtil.MimeForExtension(ext), "", false);
    }

    private static void SendFile(HttpListenerRequest request, HttpListenerResponse response, string path, string mime, string downloadName, bool attachment)
    {
        if (!File.Exists(path))
        {
            WriteJson(response, 404, Error("Missing media."));
            return;
        }
        var info = new FileInfo(path);
        var size = info.Length;
        long start = 0;
        var end = size > 0 ? size - 1 : 0;
        var length = size;
        var status = 200;
        var range = request.Headers["Range"];
        if (!attachment && !string.IsNullOrEmpty(range) && Regex.IsMatch(range, @"bytes=(\d*)-(\d*)"))
        {
            var match = Regex.Match(range, @"bytes=(\d*)-(\d*)");
            if (match.Groups[1].Value.Length == 0 && match.Groups[2].Value.Length > 0)
            {
                var suffix = long.Parse(match.Groups[2].Value);
                start = Math.Max(0, size - suffix);
            }
            else
            {
                start = match.Groups[1].Value.Length == 0 ? 0 : long.Parse(match.Groups[1].Value);
                end = match.Groups[2].Value.Length == 0 ? size - 1 : Math.Min(size - 1, long.Parse(match.Groups[2].Value));
            }
            if (start > end || start >= size)
            {
                response.StatusCode = 416;
                response.Headers["Content-Range"] = "bytes */" + size;
                response.Close();
                return;
            }
            length = end - start + 1;
            status = 206;
            response.Headers["Content-Range"] = "bytes " + start + "-" + end + "/" + size;
        }

        response.StatusCode = status;
        response.ContentType = mime;
        response.Headers["Accept-Ranges"] = "bytes";
        response.Headers["Content-Disposition"] = attachment
            ? "attachment; filename=\"" + downloadName + "\""
            : "inline";
        response.Headers["Cache-Control"] = attachment ? "no-store" : "public, max-age=86400";
        response.ContentLength64 = length;
        if (request.HttpMethod == "HEAD")
        {
            response.Close();
            return;
        }
        using var stream = File.OpenRead(path);
        if (start > 0) stream.Seek(start, SeekOrigin.Begin);
        var buffer = new byte[1024 * 256];
        var remaining = length;
        while (remaining > 0)
        {
            var read = stream.Read(buffer, 0, (int)Math.Min(buffer.Length, remaining));
            if (read <= 0) break;
            try
            {
                response.OutputStream.Write(buffer, 0, read);
            }
            catch
            {
                break;
            }
            remaining -= read;
        }
        response.Close();
    }

    private static JsonObject ReadJson(HttpListenerRequest request)
    {
        if (request.ContentLength64 > MaxJsonBytes)
        {
            throw new AppException("Invalid project data.");
        }
        using var reader = new StreamReader(request.InputStream, Encoding.UTF8);
        var raw = reader.ReadToEnd();
        if (raw.Length > 0 && raw[0] == '\uFEFF') raw = raw[1..];
        try
        {
            return JsonNode.Parse(raw) as JsonObject ?? throw new AppException("Invalid project data.");
        }
        catch (AppException)
        {
            throw;
        }
        catch
        {
            throw new AppException("Invalid project data.");
        }
    }

    private static (string FileName, string TempPath, long Size) ReadMultipartFile(HttpListenerRequest request)
    {
        var contentType = request.ContentType ?? "";
        var match = Regex.Match(contentType, "boundary=(?:\"(?<b>[^\"]+)\"|(?<b>[^;]+))", RegexOptions.IgnoreCase);
        if (!match.Success)
        {
            throw new AppException("Upload failed.");
        }
        var boundary = match.Groups["b"].Value.Trim();
        var tempBody = Path.Combine(Path.GetTempPath(), "ve-upload-" + Guid.NewGuid().ToString("N"));
        using (var output = File.Create(tempBody))
        {
            request.InputStream.CopyTo(output);
        }
        try
        {
            using var body = File.OpenRead(tempBody);
            var headerBuffer = new byte[Math.Min(64 * 1024, body.Length)];
            var headerRead = body.Read(headerBuffer, 0, headerBuffer.Length);
            var headerMark = Encoding.ASCII.GetBytes("\r\n\r\n");
            var headerEnd = IndexOf(headerBuffer.AsSpan(0, headerRead).ToArray(), headerMark, 0);
            if (headerEnd < 0)
            {
                throw new AppException("Upload failed.");
            }
            var header = Encoding.UTF8.GetString(headerBuffer, 0, headerEnd);
            var fileMatch = Regex.Match(header, "filename\\*=UTF-8''(?<n>[^\\r\\n;]+)|filename=\"(?<n>[^\"]*)\"", RegexOptions.IgnoreCase);
            var fileName = fileMatch.Success ? Uri.UnescapeDataString(fileMatch.Groups["n"].Value) : "media";
            var dataStart = headerEnd + headerMark.Length;
            var tailLength = (int)Math.Min(body.Length, 1024);
            var tail = new byte[tailLength];
            body.Seek(body.Length - tailLength, SeekOrigin.Begin);
            body.ReadExactly(tail);
            var closing = Encoding.ASCII.GetBytes("\r\n--" + boundary);
            var closeInTail = LastIndexOf(tail, closing);
            if (closeInTail < 0)
            {
                throw new AppException("Upload failed.");
            }
            var closeAt = body.Length - tailLength + closeInTail;
            if (closeAt < dataStart)
            {
                throw new AppException("Upload failed.");
            }
            var size = closeAt - dataStart;
            var fileTemp = Path.Combine(Path.GetTempPath(), "ve-file-" + Guid.NewGuid().ToString("N"));
            body.Seek(dataStart, SeekOrigin.Begin);
            using (var output = File.Create(fileTemp))
            {
                var buffer = new byte[1024 * 256];
                var remaining = size;
                while (remaining > 0)
                {
                    var read = body.Read(buffer, 0, (int)Math.Min(buffer.Length, remaining));
                    if (read <= 0) break;
                    output.Write(buffer, 0, read);
                    remaining -= read;
                }
            }
            return (fileName, fileTemp, size);
        }
        finally
        {
            try { File.Delete(tempBody); } catch { /* temp cleanup */ }
        }
    }

    private void AssertSameOrigin(HttpListenerRequest request)
    {
        var origin = request.Headers["Origin"];
        if (string.IsNullOrEmpty(origin)) return;
        if (!Uri.TryCreate(origin, UriKind.Absolute, out var uri))
        {
            throw new AppException("Invalid request.", 403);
        }
        var host = request.UserHostName ?? "";
        var hostOnly = Regex.Replace(host, @":\d+$", "");
        if (!hostOnly.Equals(uri.Host, StringComparison.OrdinalIgnoreCase))
        {
            throw new AppException("Invalid request.", 403);
        }
    }

    private static Dictionary<string, string> Query(Uri? url)
    {
        var values = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        var query = url?.Query;
        if (string.IsNullOrEmpty(query)) return values;
        foreach (var part in query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var split = part.IndexOf('=');
            var key = Uri.UnescapeDataString(split < 0 ? part : part[..split]);
            var value = split < 0 ? "" : Uri.UnescapeDataString(part[(split + 1)..]);
            values[key] = value;
        }
        return values;
    }

    private static void WriteJson(HttpListenerResponse response, int status, JsonNode body)
    {
        var bytes = Encoding.UTF8.GetBytes(body.ToJsonString(new System.Text.Json.JsonSerializerOptions
        {
            Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
        }));
        response.StatusCode = status;
        response.ContentType = "application/json; charset=utf-8";
        response.Headers["Cache-Control"] = "no-store";
        response.ContentLength64 = bytes.Length;
        response.OutputStream.Write(bytes);
        response.Close();
    }

    private static JsonObject Error(string message) => new() { ["ok"] = false, ["message"] = message };

    private static int FreePort()
    {
        var listener = new System.Net.Sockets.TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        return port;
    }

    private static int IndexOf(byte[] data, byte[] pattern, int start)
    {
        for (var i = start; i <= data.Length - pattern.Length; i++)
        {
            var found = true;
            for (var j = 0; j < pattern.Length; j++)
            {
                if (data[i + j] != pattern[j]) { found = false; break; }
            }
            if (found) return i;
        }
        return -1;
    }

    private static int LastIndexOf(byte[] data, byte[] pattern)
    {
        for (var i = data.Length - pattern.Length; i >= 0; i--)
        {
            var found = true;
            for (var j = 0; j < pattern.Length; j++)
            {
                if (data[i + j] != pattern[j]) { found = false; break; }
            }
            if (found) return i;
        }
        return -1;
    }
}
