"""Verify an opmanager release zip and optionally install it into the local GLPI copy.

Usage:
  python tools/release_check.py opmanagerv0.1.0.zip
  python tools/release_check.py opmanagerv0.1.0.zip --install C:/Users/teguh.umar/Downloads/glpi/plugins

Reads bytes straight from the archive (never from an extraction, hub §5) and never
uses a fallback that could print a passing value when a step cannot run.
"""
import hashlib
import re
import shutil
import sys
import zipfile
from pathlib import Path

CANON = {
    "opmanager/lib/pgnsuite/ConfigTab.php": "F94A80C1C813A951919A20D2521DE35B431F9C836BBCCE89BAC70E544D0B80FA",
    "opmanager/lib/pgnsuite/AssetTab.php": "F8629CCA6D141B1E9271686C8D7F9D65E4FC84E39CF07E85B10FBC849A0B2466",
    "opmanager/lib/pgnsuite/Menu.php": "FC0B96219DD489639DEECD720197BA6B9810F6303A28CE7DB891139E7913CBFE",
}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest().upper()


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    zpath = Path(sys.argv[1])
    install_dir = Path(sys.argv[sys.argv.index("--install") + 1]) if "--install" in sys.argv else None
    ok = True

    with zipfile.ZipFile(zpath) as z:
        files = [n for n in z.namelist() if not n.endswith("/")]
        bad_root = [n for n in files if not n.startswith("opmanager/")]
        print(f"entries: {len(files)}")
        if bad_root:
            print(f"FAIL entries outside opmanager/: {bad_root[:5]}")
            ok = False
        for name, want in CANON.items():
            got = sha(z.read(name)) if name in files else "missing"
            print(("PASS " if got == want else "FAIL ") + f"{name} {got}")
            ok &= got == want
        leaked = [n for n in files if (n.startswith(("opmanager/tests/", "opmanager/docs/")) and n not in ("opmanager/docs/", "opmanager/docs/manual.md")) or n.endswith(".zip")]
        if leaked:
            print(f"FAIL dev files in zip: {leaked[:5]}")
            ok = False
        m = re.search(rb"PLUGIN_OPMANAGER_VERSION',\s*'([^']+)'", z.read("opmanager/setup.php"))
        version = m.group(1).decode() if m else "missing"
        print(f"setup.php version: {version}")
        if f"v{version}.zip" not in zpath.name:
            print(f"FAIL zip name {zpath.name} does not carry version {version}")
            ok = False

        if install_dir is not None and ok:
            target = install_dir / "opmanager"
            if target.exists():
                shutil.rmtree(target)
            z.extractall(install_dir)
            on_disk = [p for p in target.rglob("*") if p.is_file()]
            print(f"installed: {len(on_disk)} files into {target}")
            if len(on_disk) != len(files):
                print("FAIL installed file count differs from zip")
                ok = False
            for name, want in CANON.items():
                got = sha((install_dir / name).read_bytes())
                print(("PASS " if got == want else "FAIL ") + f"installed {name}")
                ok &= got == want
            m2 = re.search(r"PLUGIN_OPMANAGER_VERSION',\s*'([^']+)'", (target / "setup.php").read_text(encoding="utf-8"))
            print(("PASS " if m2 and m2.group(1) == version else "FAIL ") + "installed setup.php version")
            ok &= bool(m2 and m2.group(1) == version)

    print("RELEASE OK" if ok else "RELEASE FAILED")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
