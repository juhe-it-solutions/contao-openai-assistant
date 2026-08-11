#!/bin/bash

# Runs the contao6 CI jobs locally, in Docker, against the current checkout.
#
# The repository's vendor/ is the main-line install (PHP 8.2, Contao 5.3, PHPUnit 10),
# so it cannot verify the contao6 branch at all. This runs the three jobs from
# .github/workflows/ci.yml - ECS, PHPStan and PHPUnit - on PHP 8.4 with Contao 6,
# which is the only way to see a contao6 failure before pushing.
#
# Usage: ./scripts/verify-contao6.sh [ecs|phpstan|phpunit]
#        ./scripts/verify-contao6.sh --rebuild     # discard the cached workspace
#
# The repository is never modified: the checkout is exported to a workspace under
# /tmp and its own vendor/ is installed there. Dependencies are cached between runs,
# so only the first run pays the install.

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKSPACE="${CONTAO6_VERIFY_DIR:-/tmp/contao6-verify}"
IMAGE="php:8.4-cli"
# Built once and reused: intl/gd/zip plus Composer take minutes to install every run.
BUILT_IMAGE="contao-openai-assistant-verify:php8.4"

ONLY=""
REBUILD=0

for arg in "$@"; do
    case "$arg" in
        ecs|phpstan|phpunit) ONLY="$arg" ;;
        --rebuild) REBUILD=1 ;;
        *) echo "Usage: $0 [ecs|phpstan|phpunit] [--rebuild]"; exit 1 ;;
    esac
done

if ! command -v docker >/dev/null 2>&1; then
    echo -e "${RED}Docker is required but not installed.${NC}"
    exit 1
fi

BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD)"

if [ "$BRANCH" != "contao6" ]; then
    echo -e "${YELLOW}Warning: the checkout is on '$BRANCH', not 'contao6'.${NC}"
    echo -e "${YELLOW}Verifying it against Contao 6 and PHP 8.4 anyway.${NC}"
    echo ""
fi

run_in_container() {
    # --user matters: the container writes vendor/ and the tool caches into the mounted
    # workspace, and as root it would leave files this script cannot delete on its next
    # run. Composer is given an explicit HOME because the uid has no passwd entry.
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -v "$WORKSPACE/app:/app" \
        -v "$VENDOR_CACHE:/composer-cache" \
        -e COMPOSER_HOME=/composer-cache/home \
        -w /app \
        "$BUILT_IMAGE" bash -c "$1"
}

# A workspace left behind by an older version of this script is root-owned, so the host
# cannot delete it. Falling back to a root container clears it without needing sudo.
remove_path() {
    [ -e "$1" ] || return 0

    if rm -rf "$1" 2>/dev/null; then
        return 0
    fi

    local parent base
    parent="$(cd "$(dirname "$1")" && pwd)"
    base="$(basename "$1")"

    docker run --rm -v "$parent:/target" "$BUILT_IMAGE" rm -rf "/target/$base"
}

# The image is only rebuilt when it is missing, so the extension install is paid once.
if [ "$REBUILD" = "1" ] || ! docker image inspect "$BUILT_IMAGE" >/dev/null 2>&1; then
    echo -e "${BLUE}Building the PHP 8.4 image (intl, gd, zip, Composer)...${NC}"
    docker build -q -t "$BUILT_IMAGE" - >/dev/null <<EOF
FROM $IMAGE
RUN apt-get update -qq \
    && apt-get install -y -qq git unzip libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j4 zip intl gd \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
EOF
    echo -e "${GREEN}Image ready.${NC}"
fi

if [ "$REBUILD" = "1" ]; then
    remove_path "$WORKSPACE"
fi

VENDOR_CACHE="$WORKSPACE/.vendor-cache"
mkdir -p "$VENDOR_CACHE" "$WORKSPACE/app"

echo -e "${BLUE}Exporting $BRANCH to $WORKSPACE/app...${NC}"

# git archive honours export-ignore, which drops tests/, the docs and every tool
# config - exactly what has to be verified. So the tracked tree is copied instead,
# and only vendor/ is left behind (it belongs to the main line and must not leak in).
remove_path "$WORKSPACE/app"
mkdir -p "$WORKSPACE/app"
git -C "$REPO_ROOT" ls-files -z | while IFS= read -r -d '' file; do
    mkdir -p "$WORKSPACE/app/$(dirname "$file")"
    cp "$REPO_ROOT/$file" "$WORKSPACE/app/$file"
done

# Reuse the previous run's vendor/ so composer only resolves what actually changed.
if [ -d "$VENDOR_CACHE/vendor" ]; then
    cp -r "$VENDOR_CACHE/vendor" "$WORKSPACE/app/vendor"
fi

echo -e "${BLUE}Installing Contao 6 dependencies (cached after the first run)...${NC}"
run_in_container 'composer update --prefer-dist --no-progress --with contao/core-bundle:"^6.0" 2>&1 | tail -3'

remove_path "$VENDOR_CACHE/vendor.tmp"
cp -r "$WORKSPACE/app/vendor" "$VENDOR_CACHE/vendor.tmp"
remove_path "$VENDOR_CACHE/vendor"
mv "$VENDOR_CACHE/vendor.tmp" "$VENDOR_CACHE/vendor"

FAILED=""

run_job() {
    local name="$1"
    local cmd="$2"

    if [ -n "$ONLY" ] && [ "$ONLY" != "$name" ]; then
        return 0
    fi

    echo ""
    echo -e "${BLUE}=== $name ===${NC}"

    if run_in_container "$cmd"; then
        echo -e "${GREEN}$name passed.${NC}"
    else
        echo -e "${RED}$name FAILED.${NC}"
        FAILED="$FAILED $name"
    fi
}

# Mirrors the "Code Formatting" job, which runs --fix and fails if anything changed.
# Reporting the diff is what makes an ordering rule (protected before private, say)
# visible here instead of on the CI runner.
run_job ecs 'vendor/bin/ecs check --fix >/dev/null 2>&1; vendor/bin/ecs check'

# memory_limit=-1 because the default 128M kills php-parser mid-analysis and reports
# it as a "severe error", which reads like a code failure but is not one.
run_job phpstan 'php -d memory_limit=-1 vendor/bin/phpstan analyse src/ --level=5 --no-progress'

run_job phpunit 'vendor/bin/phpunit --no-coverage'

echo ""

if [ -n "$FAILED" ]; then
    echo -e "${RED}Failed:$FAILED${NC}"
    echo -e "${YELLOW}The workspace is kept at $WORKSPACE/app for inspection.${NC}"
    exit 1
fi

echo -e "${GREEN}All contao6 checks passed.${NC}"
