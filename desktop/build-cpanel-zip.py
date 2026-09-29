import os
import zipfile

src = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
zip_path = os.path.join(os.path.dirname(src), "Video_Editor-cpanel.zip")
root_name = "Video_Editor"
include_files = ["index.php", ".htaccess", "README.md"]
include_dirs = ["api", "assets", "includes"]
empty_dirs = [
    "uploads/videos",
    "uploads/images",
    "uploads/audio",
    "uploads/meta",
    "uploads/thumbs",
    "exports/jobs",
    "exports/output",
    "logs",
]


def add_bytes(zf, arc, data, mode):
    info = zipfile.ZipInfo(arc)
    info.compress_type = zipfile.ZIP_DEFLATED
    info.external_attr = (mode << 16)
    zf.writestr(info, data)


def add_file(zf, disk, arc, mode=0o644):
    with open(disk, "rb") as handle:
        add_bytes(zf, arc.replace("\\", "/"), handle.read(), mode)


def add_dir(zf, arc):
    name = arc.replace("\\", "/").rstrip("/") + "/"
    info = zipfile.ZipInfo(name)
    info.external_attr = (0o755 << 16) | 0x10
    zf.writestr(info, b"")


if os.path.exists(zip_path):
    os.remove(zip_path)

with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
    add_dir(zf, root_name)
    for name in include_files:
        add_file(zf, os.path.join(src, name), root_name + "/" + name)
    for folder in include_dirs:
        base = os.path.join(src, folder)
        for dirpath, _, filenames in os.walk(base):
            rel = os.path.relpath(dirpath, src).replace("\\", "/")
            add_dir(zf, root_name + "/" + rel)
            for filename in filenames:
                add_file(zf, os.path.join(dirpath, filename), root_name + "/" + rel + "/" + filename)
    add_dir(zf, root_name + "/bin")
    deny = b"Require all denied\n"
    bin_htaccess = os.path.join(src, "bin", ".htaccess")
    if os.path.isfile(bin_htaccess):
        add_file(zf, bin_htaccess, root_name + "/bin/.htaccess")
    else:
        add_bytes(zf, root_name + "/bin/.htaccess", deny, 0o644)
    for folder in empty_dirs:
        add_dir(zf, root_name + "/" + folder)
        htaccess = os.path.join(src, folder.replace("/", os.sep), ".htaccess")
        arc = root_name + "/" + folder + "/.htaccess"
        if os.path.isfile(htaccess):
            add_file(zf, htaccess, arc)
        else:
            add_bytes(zf, arc, deny, 0o644)

with zipfile.ZipFile(zip_path) as zf:
    for name in ("Video_Editor/index.php", "Video_Editor/assets/css/editor.css"):
        info = zf.getinfo(name)
        print(name, oct(info.external_attr >> 16), info.file_size)
    banned = [name for name in zf.namelist() if name.endswith("/ffmpeg") or name.endswith("/ffprobe")]
    print("bundledBinaries", len(banned))

print("zipMB", round(os.path.getsize(zip_path) / 1024 / 1024, 1))
print(zip_path)
