#!/usr/bin/env bash
#
# Run a comparison on a fresh Ubuntu machine (a cloud VM, say) rather than on a
# laptop that has other things to do. It installs PHP and Composer, checks out two
# refs of statamic/cms, builds a target from each with the same generated content,
# and compares them.
#
#   tools/remote.sh <base-ref> <head-ref> [options]
#
#   --repo=URL      where the refs live (default https://github.com/statamic/cms.git)
#   --head-repo=URL where <head-ref> lives, if it's a fork
#   --size=medium   content size (see `bin/bench help`)
#   --awkward       add the awkward content
#   --rounds=3      rounds for `compare`
#   --only=a,b      scenarios to run
#   --noise         first compare the base with a second copy of itself, to see
#                   how much two identical targets differ on this machine
#
# Example, a fork's branch against 6.x:
#
#   tools/remote.sh 6.x perf/my-branch --head-repo=https://github.com/me/cms.git --noise
#
# Only compare numbers taken on the same machine in the same run. A VM whose CPU
# is shared with other customers can be noisier than a quiet laptop: run --noise
# before believing a small difference.

set -euo pipefail

[ $# -ge 2 ] || { sed -n '3,25p' "$0" | sed 's/^# \{0,1\}//'; exit 1; }

BASE_REF=$1; HEAD_REF=$2; shift 2
REPO=https://github.com/statamic/cms.git; HEAD_REPO=; SIZE=medium; AWKWARD=; ROUNDS=3; ONLY=; NOISE=

for arg in "$@"; do
    case $arg in
        --repo=*) REPO=${arg#*=} ;;
        --head-repo=*) HEAD_REPO=${arg#*=} ;;
        --size=*) SIZE=${arg#*=} ;;
        --rounds=*) ROUNDS=${arg#*=} ;;
        --only=*) ONLY=$arg ;;
        --awkward) AWKWARD=--awkward ;;
        --noise) NOISE=1 ;;
        *) echo "remote: unknown option $arg" >&2; exit 1 ;;
    esac
done

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=${BENCH_WORK:-$HOME/bench-src}
PHP_VERSION=${PHP_VERSION:-8.4}

if ! command -v php >/dev/null || ! command -v composer >/dev/null; then
    echo "==> installing PHP $PHP_VERSION and Composer"
    export DEBIAN_FRONTEND=noninteractive
    sudo apt-get update -qq
    sudo apt-get install -y -qq software-properties-common git rsync patch unzip curl >/dev/null
    sudo add-apt-repository -y ppa:ondrej/php >/dev/null
    sudo apt-get update -qq
    sudo apt-get install -y -qq "php$PHP_VERSION"-{cli,mbstring,xml,curl,zip,gd,intl,bcmath,sqlite3,opcache} >/dev/null
    curl -sS https://getcomposer.org/installer | sudo php -- --quiet --install-dir=/usr/local/bin --filename=composer
fi

checkout() { # <dir> <repo> <ref>
    if [ ! -d "$1/.git" ]; then git clone --quiet "$2" "$1"; fi
    git -C "$1" fetch --quiet origin "$3"
    git -C "$1" checkout --quiet --detach FETCH_HEAD
    echo "==> $1 at $(git -C "$1" log --oneline -1)"
}

mkdir -p "$WORK"
checkout "$WORK/base" "$REPO" "$BASE_REF"
checkout "$WORK/head" "${HEAD_REPO:-$REPO}" "$HEAD_REF"

cd "$ROOT"
setup() { [ -d "targets.noindex/$1" ] && bin/bench sync "$1" || bin/bench setup "$1" --src="$2" --size="$SIZE" $AWKWARD; }

setup base "$WORK/base"
setup head "$WORK/head"

echo "==> $(nproc) CPUs, $(awk '/MemTotal/ {printf "%.0f GB", $2 / 1048576}' /proc/meminfo), load $(cut -d' ' -f1-3 /proc/loadavg)"

if [ -n "$NOISE" ]; then
    setup base2 "$WORK/base"
    echo "==> noise: base against a second copy of itself"
    bin/bench compare base base2 --rounds="$ROUNDS" $ONLY
fi

bin/bench check base head
bin/bench compare base head --rounds="$ROUNDS" $ONLY
