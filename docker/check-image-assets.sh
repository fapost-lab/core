#!/usr/bin/env bash
# Checks that the core image carries exactly the compiled front-end of the web image.
#
#   check-image-assets.sh <core image> <web image>
#
# The console view reads public/build/manifest.json through @vite in the PHP
# container, while nginx serves the files from the web image. A core image
# without the build answers 500 on every console page, and the web tier alone
# looks healthy — so the checksums of every file under public/build must be the
# same in both images.
set -euo pipefail

[ "$#" -eq 2 ] || { echo "usage: check-image-assets.sh <core image> <web image>" >&2; exit 2; }

# Both images are Alpine-based: BusyBox find, sha256sum and sort are there.
assets() {
    docker run --rm --entrypoint sh "$1" -c \
        'cd /var/www/html/public && find build -type f -exec sha256sum {} + | sort -k 2'
}

core_assets="$(assets "$1")"
web_assets="$(assets "$2")"

grep -q ' build/manifest\.json$' <<< "$core_assets" || { echo "::error::$1 has no public/build/manifest.json" >&2; exit 1; }

if [ "$core_assets" != "$web_assets" ]; then
    echo "::error::the public/build of $1 differs from that of $2" >&2
    diff <(echo "$core_assets") <(echo "$web_assets") >&2 || true
    exit 1
fi

echo "$(wc -l <<< "$core_assets" | tr -d ' ') front-end files match between $1 and $2"
