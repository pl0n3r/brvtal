"""Stamp the checked-out source commit without rewriting the product version."""
from pathlib import Path
import re
import subprocess

REPO_ROOT = Path(__file__).resolve().parents[1]
CONFIG_ROOT = (REPO_ROOT / 'config').resolve()
VERSION_FILE = (CONFIG_ROOT / 'version.php').resolve()

try:
    VERSION_FILE.relative_to(CONFIG_ROOT)
except ValueError as exc:
    raise SystemExit('Refusing to write release metadata outside config') from exc
if VERSION_FILE != CONFIG_ROOT / 'version.php':
    raise SystemExit('Refusing to write release metadata outside config/version.php')

with VERSION_FILE.open('r', encoding='utf-8') as handle:
    text = handle.read()

# Ignore previous metadata-only commits, including a manual workflow rerun.
sha = subprocess.check_output(
    ['git', 'log', '-1', '--format=%H', '--', '.', ':(exclude)config/version.php'], text=True
).strip()
date = subprocess.check_output(['git', 'show', '-s', '--format=%cs', sha], text=True).strip()
for name, value in [('BRVTAL_APP_BUILD', sha[:7]), ('BRVTAL_RELEASE_DATE', date)]:
    text, count = re.subn(r"const " + name + r" = '[^']*';", f"const {name} = '{value}';", text)
    if count != 1:
        raise SystemExit(f'Expected exactly one {name} constant')

with VERSION_FILE.open('w', encoding='utf-8') as handle:
    handle.write(text)
