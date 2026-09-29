$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$project = Join-Path $here "VideoEditor.Win\VideoEditor.Win.csproj"
$root = Split-Path -Parent $here
$out = Join-Path $root "dist\VideoEditor"

dotnet publish $project -c Release -r win-x64 --self-contained true -o $out
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$www = Join-Path $out "www"
if (Test-Path $www) { Remove-Item $www -Recurse -Force }
New-Item -ItemType Directory -Force -Path $www | Out-Null
Copy-Item (Join-Path $root "index.php") (Join-Path $www "index.php") -Force
Copy-Item (Join-Path $root "assets") (Join-Path $www "assets") -Recurse -Force

$bin = Join-Path $out "bin"
New-Item -ItemType Directory -Force -Path $bin | Out-Null
Copy-Item (Join-Path $root "bin\ffmpeg.exe") (Join-Path $bin "ffmpeg.exe") -Force
Copy-Item (Join-Path $root "bin\ffprobe.exe") (Join-Path $bin "ffprobe.exe") -Force

Write-Host "Portable app: $out\VideoEditor.exe"
