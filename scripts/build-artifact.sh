#!/bin/bash

# Build a Composer artifact (ZIP) for the Contao Manager — no git tag, no GitHub release.
#
# Usage: ./scripts/build-artifact.sh <version> [git-ref]
# Example: ./scripts/build-artifact.sh 2.1.900 feature/link-extraction-5x
#
# The ZIP is built from a git ref (NOT the working tree) and honours
# .gitattributes export-ignore, so tests/, docs/, .github/ etc. stay out.
# A "version" property is injected into composer.json, which the Contao
# Manager requires for artifacts.
#
# Install on the target site: Contao Manager -> Packages -> upload the ZIP,
# then apply the changes and run the database migration.

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

if [ $# -eq 0 ]; then
    echo "Usage: $0 <version> [git-ref]"
    echo "Example: $0 2.1.900 feature/link-extraction-5x"
    echo
    echo "Use a 2.1.9xx / 3.0.9xx style version for test builds: it sorts above"
    echo "every released patch, below the next real release, and can never"
    echo "collide with a future tag."
    exit 1
fi

VERSION=$1
REF=${2:-HEAD}

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
    echo -e "${RED}❌ Error: '$VERSION' is not a valid Composer version (e.g. 2.1.900)${NC}"
    exit 1
fi

if ! git rev-parse --verify --quiet "$REF" > /dev/null; then
    echo -e "${RED}❌ Error: git ref '$REF' does not exist${NC}"
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo -e "${YELLOW}⚠️  Working directory is not clean — the ZIP is built from '$REF', not from your uncommitted changes${NC}"
fi

PACKAGE=$(php -r 'echo json_decode(file_get_contents("composer.json"), true)["name"];')
SLUG=${PACKAGE##*/}
OUT_DIR="$(pwd)/dist"
OUT_FILE="$OUT_DIR/$SLUG-$VERSION.zip"
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

echo -e "${BLUE}📦 Building artifact $PACKAGE $VERSION from '$REF'...${NC}"

# Export the ref (applies .gitattributes export-ignore)
git archive --format=tar "$REF" | tar -x -C "$TMP_DIR"

# Files that are useful in the repository but have no business in a package
rm -f "$TMP_DIR/CLAUDE.md" "$TMP_DIR/.cursorrules" "$TMP_DIR/composer.lock"

if [ ! -f "$TMP_DIR/composer.json" ]; then
    echo -e "${RED}❌ Error: no composer.json in the exported ref${NC}"
    exit 1
fi

# Inject the version property (mandatory for Contao Manager artifacts)
php -r '
$file = $argv[1];
$data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$out = [];
foreach ($data as $key => $value) {
    $out[$key] = $value;
    if ("name" === $key) {
        $out["version"] = $argv[2];
    }
}
if (!isset($out["version"])) {
    $out["version"] = $argv[2];
}
file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
' "$TMP_DIR/composer.json" "$VERSION"

echo -e "${YELLOW}📝 composer.json version set to $VERSION${NC}"
echo -e "${CYAN}   requires: $(php -r 'foreach (json_decode(file_get_contents($argv[1]), true)["require"] as $p => $c) { echo "$p:$c "; }' "$TMP_DIR/composer.json")${NC}"

mkdir -p "$OUT_DIR"
rm -f "$OUT_FILE"

# Zip the package contents at the ZIP root (no parent folder) — Contao Manager
# expects composer.json in the archive root.
(cd "$TMP_DIR" && zip -q -r "$OUT_FILE" . -x '.git/*')

echo -e "${GREEN}✅ $OUT_FILE${NC}"
echo -e "${BLUE}   $(unzip -l "$OUT_FILE" | tail -1 | tr -s ' ')${NC}"
echo
echo -e "${CYAN}Install: Contao Manager → Packages → drag & drop the ZIP → apply changes${NC}"
echo -e "${CYAN}Then run the database migration (Contao Manager → System → Update database)${NC}"
echo -e "${CYAN}Uninstall: remove the artifact in the Contao Manager and reinstall the released version${NC}"
