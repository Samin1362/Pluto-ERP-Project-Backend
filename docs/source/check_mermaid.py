"""Render every ```mermaid block in docs/*.md with mermaid-cli to prove the syntax is valid.

Usage: python3 docs/source/check_mermaid.py [path/to/mmdc]
"""
import glob, os, re, subprocess, sys, tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
DOCS = os.path.dirname(HERE)
MMDC = sys.argv[1] if len(sys.argv) > 1 else "mmdc"
cfg = os.path.join(tempfile.gettempdir(), "pptr.json")
open(cfg, "w").write('{"args":["--no-sandbox"],"executablePath":"%s"}' % os.environ.get("CHROME_PATH", "/opt/pw-browsers/chromium-1194/chrome-linux/chrome"))

blocks = []
for f in sorted(glob.glob(os.path.join(DOCS, "*.md"))) + [os.path.join(os.path.dirname(DOCS), "README.md")]:
    if not os.path.exists(f):
        continue
    for i, m in enumerate(re.finditer(r"```mermaid\n(.*?)```", open(f).read(), re.S)):
        blocks.append((os.path.basename(f), i + 1, m.group(1)))

fails = 0
with tempfile.TemporaryDirectory() as d:
    for name, i, src in blocks:
        inp, out = os.path.join(d, "in.mmd"), os.path.join(d, "out.svg")
        open(inp, "w").write(src)
        r = subprocess.run([MMDC, "-q", "-p", cfg, "-i", inp, "-o", out], capture_output=True, text=True, timeout=180)
        ok = r.returncode == 0 and os.path.exists(out) and "Syntax error" not in open(out).read()
        if not ok:
            fails += 1
            print(f"FAIL {name} block {i}: {(r.stderr or r.stdout)[-400:]}")
        if os.path.exists(out):
            os.remove(out)
print(f"mermaid blocks={len(blocks)} failed={fails}")
sys.exit(1 if fails else 0)
