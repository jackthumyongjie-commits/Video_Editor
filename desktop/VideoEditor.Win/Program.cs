namespace VideoEditor;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        using var mutex = new Mutex(true, @"Local\VideoEditor.Portable.SingleInstance", out var created);
        if (!created)
        {
            MessageBox.Show("视频编辑器已经在运行。", "视频编辑器", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }

        ApplicationConfiguration.Initialize();
        Application.Run(new MainForm());
    }
}
