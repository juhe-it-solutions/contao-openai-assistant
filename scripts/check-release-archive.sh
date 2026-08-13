#!/usr/bin/env bash

# Verify the exact file set Composer and GitHub will export for a release tag.
# Usage: ./scripts/check-release-archive.sh [git-ref]

set -euo pipefail

REF="${1:-HEAD}"

fail() {
    echo "Release archive check failed: $1" >&2
    exit 1
}

PROJECT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$PROJECT_DIR"

if ! ARCHIVE_TREE=$(git rev-parse --verify "$REF^{tree}" 2>/dev/null); then
    fail "git ref '$REF' does not resolve to a tree"
fi

ARCHIVE_DIR=$(mktemp -d)
trap 'rm -rf "$ARCHIVE_DIR"' EXIT

git ls-tree -r --name-only "$ARCHIVE_TREE" > "$ARCHIVE_DIR/tracked-entries.txt"

if grep -Eiq '(^|/)(\.env([^/]*|$)|XXX_DOCS_INTERN|vendor|node_modules|dist|build|coverage|logs?|secrets?)(/|$)|(^|/)composer\.lock$|\.(bak|backup|crt|db|key|log|orig|p12|pem|rej|sqlite|temp|tmp)$' "$ARCHIVE_DIR/tracked-entries.txt"; then
    echo "Forbidden files tracked by the release tag:" >&2
    grep -Ei '(^|/)(\.env([^/]*|$)|XXX_DOCS_INTERN|vendor|node_modules|dist|build|coverage|logs?|secrets?)(/|$)|(^|/)composer\.lock$|\.(bak|backup|crt|db|key|log|orig|p12|pem|rej|sqlite|temp|tmp)$' "$ARCHIVE_DIR/tracked-entries.txt" >&2
    fail "the tag tree contains internal, generated or secret-bearing file names"
fi

set +e
git grep -I -l -E \
    '(-----BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----|sk-(proj-)?[A-Za-z0-9_-]{32,}|github_pat_[A-Za-z0-9_]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|XXX_DOCS_INTERN|/home/julle|/mnt/c/Users|AppData/Local/Temp)' \
    "$ARCHIVE_TREE" -- . \
    ':(exclude).gitattributes' \
    ':(exclude).gitignore' \
    ':(exclude)scripts/check-release-archive.sh' \
    > "$ARCHIVE_DIR/tracked-sensitive-files.txt"
TRACKED_SENSITIVE_STATUS=$?
set -e

if [ "$TRACKED_SENSITIVE_STATUS" -eq 0 ]; then
    echo "Tracked files matching the sensitive-content scan:" >&2
    cat "$ARCHIVE_DIR/tracked-sensitive-files.txt" >&2
    fail "potential credential or local machine path found in the tag tree"
fi

if [ "$TRACKED_SENSITIVE_STATUS" -ne 1 ]; then
    fail "the tag-tree sensitive-content scan could not be completed"
fi

git archive --format=tar "$ARCHIVE_TREE" > "$ARCHIVE_DIR/package.tar"
tar -tf "$ARCHIVE_DIR/package.tar" > "$ARCHIVE_DIR/entries.txt"
tar -tvf "$ARCHIVE_DIR/package.tar" > "$ARCHIVE_DIR/entry-details.txt"
tar -xf "$ARCHIVE_DIR/package.tar" -C "$ARCHIVE_DIR"

if awk '$1 !~ /^[-d]/ { print; found = 1 } END { exit !found }' "$ARCHIVE_DIR/entry-details.txt" > "$ARCHIVE_DIR/non-regular-entries.txt"; then
    echo "Unsupported non-file/non-directory archive entries:" >&2
    cat "$ARCHIVE_DIR/non-regular-entries.txt" >&2
    fail "symbolic links or other special entries were exported"
fi

if awk '$1 ~ /^-..x/ { print; found = 1 } END { exit !found }' "$ARCHIVE_DIR/entry-details.txt" > "$ARCHIVE_DIR/executable-files.txt"; then
    echo "Unexpected executable files in the runtime package:" >&2
    cat "$ARCHIVE_DIR/executable-files.txt" >&2
    fail "runtime files must not carry executable permissions"
fi

EXPECTED_TOP_LEVEL=$(cat <<'EOF'
LICENSE
LICENSE-PREMIUM
README.md
composer.json
config
contao
public
src
EOF
)

ACTUAL_TOP_LEVEL=$(awk -F/ 'NF && $1 != "" { print $1 }' "$ARCHIVE_DIR/entries.txt" | sort -u)

if [ "$ACTUAL_TOP_LEVEL" != "$EXPECTED_TOP_LEVEL" ]; then
    echo "Expected top-level archive entries:" >&2
    echo "$EXPECTED_TOP_LEVEL" >&2
    echo "Actual top-level archive entries:" >&2
    echo "$ACTUAL_TOP_LEVEL" >&2
    fail "the exported top-level file set changed"
fi

for required in \
    composer.json \
    config/routes.yaml \
    config/services.yaml \
    contao/config/config.php \
    contao/templates/.twig-root \
    public/css/ai-chat.css \
    public/js/ai-chat.js \
    src/ContaoManagerPlugin.php \
    src/ContaoOpenaiAssistantBundle.php
do
    if ! grep -Fxq "$required" "$ARCHIVE_DIR/entries.txt"; then
        fail "required runtime file '$required' is missing"
    fi
done

if grep -Eiq '(^|/)(\.env([^/]*|$)|\.git(hub|ignore|attributes)?|XXX_DOCS_INTERN|docs|scripts|tests|vendor|node_modules|dist|build|coverage|cache|logs?|secrets?)(/|$)|(^|/)composer\.lock$|\.(bak|backup|crt|db|key|log|orig|p12|pem|rej|sqlite|temp|tmp)$' "$ARCHIVE_DIR/entries.txt"; then
    echo "Forbidden archive entries:" >&2
    grep -Ei '(^|/)(\.env([^/]*|$)|\.git(hub|ignore|attributes)?|XXX_DOCS_INTERN|docs|scripts|tests|vendor|node_modules|dist|build|coverage|cache|logs?|secrets?)(/|$)|(^|/)composer\.lock$|\.(bak|backup|crt|db|key|log|orig|p12|pem|rej|sqlite|temp|tmp)$' "$ARCHIVE_DIR/entries.txt" >&2
    fail "development, internal or secret-bearing file names were exported"
fi

set +e
grep -RIlE --exclude=package.tar --exclude=entries.txt --exclude=entry-details.txt \
    --exclude=non-regular-entries.txt --exclude=executable-files.txt --exclude=sensitive-files.txt \
    '(-----BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----|sk-(proj-)?[A-Za-z0-9_-]{32,}|github_pat_[A-Za-z0-9_]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|XXX_DOCS_INTERN|/home/julle|/mnt/c/Users|AppData/Local/Temp)' \
    "$ARCHIVE_DIR" > "$ARCHIVE_DIR/sensitive-files.txt"
SENSITIVE_STATUS=$?
set -e

if [ "$SENSITIVE_STATUS" -eq 0 ]; then
    echo "Files matching the sensitive-content scan:" >&2
    cat "$ARCHIVE_DIR/sensitive-files.txt" >&2
    fail "potential secret or internal path found in the exported package"
fi

if [ "$SENSITIVE_STATUS" -ne 1 ]; then
    fail "the sensitive-content scan could not be completed"
fi

FILE_COUNT=$(grep -vc '/$' "$ARCHIVE_DIR/entries.txt")
ARCHIVE_SIZE=$(wc -c < "$ARCHIVE_DIR/package.tar" | tr -d ' ')

if [ "$ARCHIVE_SIZE" -gt 10485760 ]; then
    fail "uncompressed release archive unexpectedly exceeds 10 MiB"
fi

echo "Release archive check passed for $REF: $FILE_COUNT files, $ARCHIVE_SIZE bytes (tar)."
