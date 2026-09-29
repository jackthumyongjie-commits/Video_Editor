using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace VideoEditor;

internal sealed class MainForm : Form
{
    private readonly WebView2 _web = new() { Dock = DockStyle.Fill };
    private readonly Label _message = new()
    {
        Dock = DockStyle.Fill,
        TextAlign = ContentAlignment.MiddleCenter,
        ForeColor = Color.White,
        BackColor = Color.Black,
        Font = new Font("Segoe UI", 14f),
        Visible = false,
    };
    private LocalServer? _server;

    public MainForm()
    {
        Text = "视频编辑器";
        BackColor = Color.Black;
        StartPosition = FormStartPosition.CenterScreen;
        var area = Screen.PrimaryScreen?.WorkingArea ?? new Rectangle(0, 0, 1440, 900);
        Size = new Size(Math.Min(1440, area.Width), Math.Min(900, area.Height));
        MinimumSize = new Size(960, 640);
        Controls.Add(_web);
        Controls.Add(_message);
        Load += OnLoad;
        FormClosed += (_, _) => _server?.Dispose();
    }

    private async void OnLoad(object? sender, EventArgs e)
    {
        try
        {
            var paths = new AppPaths();
            var tools = new FfmpegTools(paths);
            var media = new MediaLibrary(paths, tools);
            var validator = new ProjectValidator(media);
            var exports = new ExportService(paths, tools, new FilterGraphBuilder(media, tools));
            _server = new LocalServer(paths, media, validator, exports, tools);
            _server.Start();

            var version = CoreWebView2Environment.GetAvailableBrowserVersionString();
            if (string.IsNullOrEmpty(version))
            {
                ShowMissingRuntime();
                return;
            }

            var environment = await CoreWebView2Environment.CreateAsync(null, Path.Combine(paths.LogDirectory, "webview2"));
            await _web.EnsureCoreWebView2Async(environment);
            _web.CoreWebView2.Settings.AreDevToolsEnabled = false;
            _web.DefaultBackgroundColor = Color.Black;
            _web.Source = new Uri(_server.Origin + "/");
        }
        catch (WebView2RuntimeNotFoundException)
        {
            ShowMissingRuntime();
        }
        catch (Exception ex)
        {
            _message.Text = "无法启动编辑器。\n" + ex.Message;
            _message.Visible = true;
            _message.BringToFront();
        }
    }

    private void ShowMissingRuntime()
    {
        _message.Text = "没有找到 WebView2 运行库。\n请安装 Microsoft Edge WebView2 后再打开。";
        _message.Visible = true;
        _message.BringToFront();
    }
}
