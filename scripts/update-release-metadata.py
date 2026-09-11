"""Stamp the checked-out source commit without rewriting the product version."""
from pathlib import Path
import re
import subprocess

path = Path('config/version.php')
text = path.read_text()
# Ignore previous metadata-only commits, including a manual workflow rerun.
sha = subprocess.check_output(
    ['git', 'log', '-1', '--format=%H', '--', '.', ':(exclude)config/version.php'], text=True
).strip()
date = subprocess.check_output(['git', 'show', '-s', '--format=%cs', sha], text=True).strip()
for name, value in [('BRVTAL_APP_BUILD', sha[:7]), ('BRVTAL_RELEASE_DATE', date)]:
    text, count = re.subn(r"const " + name + r" = '[^']*';", f"const {name} = '{value}';", text)
    if count != 1:
        raise SystemExit(f'Expected exactly one {name} constant')
path.write_text(text)
