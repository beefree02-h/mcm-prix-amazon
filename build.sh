#!/usr/bin/env bash
# Publie une version : construit dist/mcm-prix-amazon-<version>.zip et met à jour update.json.
# Usage : ./build.sh   (puis git add -A && git commit && git push)
set -euo pipefail
cd "$(dirname "$0")"
SRC=mcm-prix-amazon
VERSION=$(sed -n 's/^ \* Version: *//p' "$SRC/mcm-prix-amazon.php" | head -1 | tr -d '\r')
[ -n "$VERSION" ] || { echo "Version introuvable"; exit 1; }
php -l "$SRC/mcm-prix-amazon.php" >/dev/null
grep -q "const VERSION      = '$VERSION'" "$SRC/mcm-prix-amazon.php" || { echo "const VERSION différente de l'en-tête ($VERSION)"; exit 1; }
mkdir -p dist
ZIP="dist/mcm-prix-amazon-$VERSION.zip"
rm -f "$ZIP"
zip -q -X -r "$ZIP" "$SRC"
SHA=$(sha256sum "$ZIP" | cut -d' ' -f1)
CHANGELOG=$(awk -v v="## $VERSION" '$0==v{f=1;next} /^## /{f=0} f' CHANGELOG.md | sed '/^$/d')
python3 - "$VERSION" "$SHA" "$CHANGELOG" <<'PY'
import json, sys
version, sha, changelog = sys.argv[1:4]
info = {
    "version": version,
    "package": f"https://raw.githubusercontent.com/beefree02-h/mcm-prix-amazon/main/dist/mcm-prix-amazon-{version}.zip",
    "sha256": sha,
    "homepage": "https://github.com/beefree02-h/mcm-prix-amazon",
    "requires": "5.8",
    "requires_php": "7.4",
    "changelog": changelog,
}
open("update.json", "w", encoding="utf-8").write(json.dumps(info, ensure_ascii=False, indent=2) + "\n")
PY
echo "Version $VERSION : $ZIP ($SHA)"
