"""`adb` falso para probar ADBController sin Android. Registra cada invocación."""
import json
import os
import sys

args = sys.argv[1:]
with open(os.environ["FAKE_ADB_LOG"], "a") as fh:
    fh.write(json.dumps(args) + "\n")
if args[:1] == ["devices"]:
    print("List of devices attached\n127.0.0.1:5565\tdevice product:x model:y\n")
    sys.exit(0)
if args[:1] == ["connect"]:
    print(f"connected to {args[1]}")
    sys.exit(0)
if args[:2] == ["-s", "127.0.0.1:5565"]:
    rest = args[2:]
    if os.environ.get("FAKE_ADB_OFFLINE"):
        sys.stderr.write("error: device '127.0.0.1:5565' not found\n")
        sys.exit(1)
    if rest[:2] == ["shell", "echo"]:
        print("ok")
    elif rest[:3] == ["shell", "wm", "size"]:
        print("Physical size: 900x1600")
    elif rest[:3] == ["shell", "dumpsys", "input"]:
        print("    SurfaceOrientation: 1")
    elif rest[:3] == ["shell", "dumpsys", "window"]:
        print("  mCurrentFocus=Window{abc u0 com.diamantesexpress.giftsim/com.diamantesexpress.giftsim.MainActivity}")
    elif rest[:3] == ["exec-out", "screencap", "-p"]:
        sys.stdout.buffer.write(b"\x89PNG\r\n\x1a\nfake")
    elif rest[:3] == ["shell", "pm", "path"]:
        print("package:/data/app/base.apk" if rest[3] == "com.diamantesexpress.giftsim" else "")
    elif rest[:2] == ["shell", "getprop"]:
        print("1")
    sys.exit(0)
sys.exit(0)
