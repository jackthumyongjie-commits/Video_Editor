namespace VideoEditor;

internal sealed class AppPaths
{
    public string ExeDirectory { get; }
    public string WebRoot { get; }
    public string DataRoot { get; }
    public string UploadDirectory => Path.Combine(DataRoot, "uploads");
    public string ExportDirectory => Path.Combine(DataRoot, "exports");
    public string LogDirectory => Path.Combine(DataRoot, "logs");

    public AppPaths()
    {
        ExeDirectory = Path.GetDirectoryName(Environment.ProcessPath) ?? AppContext.BaseDirectory;
        WebRoot = FindWebRoot(ExeDirectory);
        var folderName = Path.GetFileName(WebRoot.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar));
        DataRoot = string.Equals(folderName, "www", StringComparison.OrdinalIgnoreCase)
            ? Path.Combine(Directory.GetParent(WebRoot)!.FullName, "data")
            : WebRoot;
        EnsureDirectories();
    }

    public string? FindTool(string name)
    {
        var file = OperatingSystem.IsWindows() ? name + ".exe" : name;
        var roots = new[]
        {
            Path.Combine(ExeDirectory, "bin"),
            Path.Combine(DataRoot, "bin"),
            Path.Combine(WebRoot, "bin"),
            Path.Combine(Directory.GetParent(WebRoot)?.FullName ?? WebRoot, "bin"),
        };
        foreach (var root in roots.Distinct(StringComparer.OrdinalIgnoreCase))
        {
            var candidate = Path.Combine(root, file);
            if (File.Exists(candidate))
            {
                return candidate;
            }
        }

        var pathEnv = Environment.GetEnvironmentVariable("PATH") ?? "";
        foreach (var dir in pathEnv.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries))
        {
            try
            {
                var candidate = Path.Combine(dir.Trim(), file);
                if (File.Exists(candidate))
                {
                    return candidate;
                }
            }
            catch
            {
                // Ignore malformed PATH entries.
            }
        }

        return null;
    }

    public void EnsureDirectories()
    {
        foreach (var dir in new[]
        {
            Path.Combine(UploadDirectory, "videos"),
            Path.Combine(UploadDirectory, "images"),
            Path.Combine(UploadDirectory, "audio"),
            Path.Combine(UploadDirectory, "meta"),
            Path.Combine(UploadDirectory, "thumbs"),
            Path.Combine(ExportDirectory, "jobs"),
            Path.Combine(ExportDirectory, "output"),
            LogDirectory,
            Path.Combine(DataRoot, "webview2"),
        })
        {
            Directory.CreateDirectory(dir);
        }
    }

    public bool DirectoriesWritable()
    {
        string[] checks =
        [
            Path.Combine(UploadDirectory, "videos"),
            Path.Combine(UploadDirectory, "meta"),
            Path.Combine(ExportDirectory, "jobs"),
            Path.Combine(ExportDirectory, "output"),
            LogDirectory,
        ];
        foreach (var dir in checks)
        {
            try
            {
                Directory.CreateDirectory(dir);
                var probe = Path.Combine(dir, ".write-probe");
                File.WriteAllText(probe, "ok");
                File.Delete(probe);
            }
            catch
            {
                return false;
            }
        }
        return true;
    }

    public void Log(string message)
    {
        try
        {
            Directory.CreateDirectory(LogDirectory);
            var line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " " + message + Environment.NewLine;
            File.AppendAllText(Path.Combine(LogDirectory, "app.log"), line);
        }
        catch
        {
            // Logging must not break the editor.
        }
    }

    private static string FindWebRoot(string start)
    {
        var current = new DirectoryInfo(start);
        for (var i = 0; i < 8 && current != null; i++)
        {
            var index = Path.Combine(current.FullName, "index.php");
            var script = Path.Combine(current.FullName, "assets", "js", "app.js");
            if (File.Exists(index) && File.Exists(script))
            {
                return current.FullName;
            }

            var bundled = Path.Combine(current.FullName, "www", "index.php");
            var bundledScript = Path.Combine(current.FullName, "www", "assets", "js", "app.js");
            if (File.Exists(bundled) && File.Exists(bundledScript))
            {
                return Path.Combine(current.FullName, "www");
            }

            current = current.Parent;
        }

        var beside = Path.Combine(start, "www");
        return Directory.Exists(beside) ? beside : start;
    }
}
