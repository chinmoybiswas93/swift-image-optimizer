#!/usr/bin/env bash
#
# Run the WP-CLI harness against any Local site.
#
#   tests/php/run-cli.sh --sites            # every Local site, with its socket and PHP
#   tests/php/run-cli.sh                    # the site this plugin copy lives in
#   tests/php/run-cli.sh --site ff-booking  # a named Local site
#   tests/php/run-cli.sh --smoke            # read-only commands only, no fixtures
#
# Nothing here is pinned to one site. The WordPress root comes from this
# script's own location; the socket, PHP binary and expected domain come from
# Local's own sites.json, looked up by the site that owns that root. Override
# any of it with SIO_SOCKET / SIO_PHP / SIO_WP / SIO_EXPECTED_SITEURL.
#
# Local names every database `local`; only the socket differs, and the wrong
# socket silently pairs this site's files with another site's database. So the
# pairing is proved with `option get siteurl` before anything runs, and the
# site's own PHP version is used rather than whatever is newest on the machine.

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

LOCAL_HOME="$HOME/Library/Application Support/Local"
SITES_JSON="$LOCAL_HOME/sites.json"

# WordPress root: plugin -> plugins -> wp-content -> the install.
WP_ROOT="$(cd ../../.. && pwd)"

# --------------------------------------------------------------------------
# Local's site registry. One python call answers every lookup below.
#
#   sites_query list                 -> id \t name \t php \t domain \t path
#   sites_query byname  <name>       -> the one row
#   sites_query bypath  <wp_root>    -> the row whose site path contains it
# --------------------------------------------------------------------------

sites_query() {
  python3 - "$SITES_JSON" "$@" <<'PY'
import json, sys, os

path, mode = sys.argv[1], sys.argv[2]
arg = sys.argv[3] if len(sys.argv) > 3 else ""

try:
    sites = json.load(open(path))
except Exception as e:
    sys.stderr.write("Could not read Local's sites.json: %s\n" % e)
    sys.exit(2)

rows = []
for sid, s in sites.items():
    php = (s.get("services", {}).get("php", {}) or {}).get("version", "")
    rows.append((
        sid,
        s.get("name", ""),
        php,
        s.get("domain", ""),
        # Local stores paths with a literal ~.
        os.path.expanduser(s.get("path", "")),
    ))

if mode == "list":
    for r in sorted(rows, key=lambda r: r[1]):
        print("\t".join(r))
    sys.exit(0)

if mode == "byname":
    for r in rows:
        if r[1] == arg:
            print("\t".join(r)); sys.exit(0)
    sys.exit(1)

if mode == "bypath":
    target = os.path.realpath(arg)
    # Longest matching site path wins, so a nested checkout picks its own site.
    best = None
    for r in rows:
        if not r[4]:
            continue
        sp = os.path.realpath(r[4])
        if target == sp or target.startswith(sp + os.sep):
            if best is None or len(sp) > len(os.path.realpath(best[4])):
                best = r
    if best:
        print("\t".join(best)); sys.exit(0)
    sys.exit(1)

sys.exit(2)
PY
}

# --------------------------------------------------------------------------
# Arguments.
# --------------------------------------------------------------------------

SITE_NAME=""
SMOKE_ONLY=0

while [ $# -gt 0 ]; do
  case "$1" in
    --sites)
      printf '%-22s %-9s %-26s %s\n' SITE PHP DOMAIN SOCKET
      sites_query list | while IFS=$'\t' read -r sid name php domain spath; do
        sock="$LOCAL_HOME/run/$sid/mysql/mysqld.sock"
        [ -S "$sock" ] || sock="(not running)"
        printf '%-22s %-9s %-26s %s\n' "$name" "$php" "$domain" "$sock"
      done
      echo
      echo "Run one with:  tests/php/run-cli.sh --site <SITE>"
      echo "That site must have its own copy of the plugin; this script tests the copy it lives in."
      exit 0
      ;;
    --site)
      SITE_NAME="${2:-}"
      [ -n "$SITE_NAME" ] || { echo "--site needs a name" >&2; exit 2; }
      shift 2
      ;;
    --smoke)
      SMOKE_ONLY=1
      shift
      ;;
    -h|--help)
      sed -n '2,20p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 2
      ;;
  esac
done

# --------------------------------------------------------------------------
# Resolve the site: named if asked, otherwise whichever site owns this file.
# --------------------------------------------------------------------------

if [ -n "$SITE_NAME" ]; then
  SITE_ROW="$(sites_query byname "$SITE_NAME")" || { echo "No Local site named '$SITE_NAME'. Try --sites." >&2; exit 2; }
else
  SITE_ROW="$(sites_query bypath "$WP_ROOT")" || { echo "No Local site owns $WP_ROOT. Pass --site <name>." >&2; exit 2; }
fi

IFS=$'\t' read -r SITE_ID SITE_NAME SITE_PHP SITE_DOMAIN SITE_PATH <<< "$SITE_ROW"

# A --site pointing somewhere else would test this checkout's files against
# that site's database - the exact two-database trap, in slow motion.
CANONICAL_ROW="$(sites_query bypath "$WP_ROOT")" || CANONICAL_ROW=""
if [ -n "$CANONICAL_ROW" ]; then
  CANONICAL_NAME="$(printf '%s' "$CANONICAL_ROW" | cut -f2)"
  if [ "$CANONICAL_NAME" != "$SITE_NAME" ]; then
    echo "REFUSING TO RUN." >&2
    echo "These files live in Local site '$CANONICAL_NAME' but --site named '$SITE_NAME'." >&2
    echo "Run the copy of this script inside $SITE_NAME's own plugin directory instead." >&2
    exit 2
  fi
fi

SITE_SOCKET="${SIO_SOCKET:-$LOCAL_HOME/run/$SITE_ID/mysql/mysqld.sock}"

# --------------------------------------------------------------------------
# Binaries. The site's own PHP version, not the newest on the machine: an
# engine that exists in 8.4 and not in the site's 8.2 would make this suite
# prove something about a PHP the site never runs.
# --------------------------------------------------------------------------

find_php() {
  local want="$1" candidate
  for root in \
    "$LOCAL_HOME/lightning-services" \
    "/Applications/Local.app/Contents/Resources/extraResources/lightning-services"
  do
    [ -d "$root" ] || continue
    # Exact version first, then any build of it, then anything at all.
    for pattern in "php-${want}+"* "php-${want%.*}"* "php-"*; do
      candidate="$(find "$root"/$pattern -maxdepth 4 -type f -name php -perm -u+x 2>/dev/null | sort -V | tail -1)"
      [ -n "$candidate" ] && { printf '%s' "$candidate"; return 0; }
    done
  done
  return 1
}

find_wp() {
  for candidate in \
    "/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp" \
    "$LOCAL_HOME/bin/wp-cli/posix/wp" \
    "$(command -v wp 2>/dev/null)"
  do
    [ -n "$candidate" ] && [ -f "$candidate" ] && { printf '%s' "$candidate"; return 0; }
  done
  return 1
}

PHP_BIN="${SIO_PHP:-$(find_php "$SITE_PHP")}"
WP_BIN="${SIO_WP:-$(find_wp)}"

[ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ] || { echo "PHP binary not found for $SITE_PHP. Set SIO_PHP=/path/to/php" >&2; exit 2; }
[ -n "$WP_BIN" ] && [ -f "$WP_BIN" ]   || { echo "wp-cli not found. Set SIO_WP=/path/to/wp" >&2; exit 2; }
[ -S "$SITE_SOCKET" ] || { echo "MySQL socket not found: $SITE_SOCKET"$'\n'"Is $SITE_NAME running? Try: tests/php/run-cli.sh --sites" >&2; exit 2; }
[ -f "$WP_ROOT/wp-load.php" ] || { echo "No wp-load.php at $WP_ROOT" >&2; exit 2; }

echo "site   : $SITE_NAME ($SITE_ID), PHP $SITE_PHP, $SITE_DOMAIN"
echo "php    : $PHP_BIN"
echo "wp     : $WP_BIN"
echo "root   : $WP_ROOT"
echo "socket : $SITE_SOCKET"

wp_run() {
  "$PHP_BIN" -d mysqli.default_socket="$SITE_SOCKET" "$WP_BIN" --path="$WP_ROOT" "$@"
}

# --------------------------------------------------------------------------
# The guard I-4 asks for: confirm the socket reached the site you meant.
# --------------------------------------------------------------------------

ACTUAL_SITEURL="$(wp_run option get siteurl 2>/dev/null | tr -d '\r')"

if [ -z "$ACTUAL_SITEURL" ]; then
  echo "REFUSING TO RUN. \`option get siteurl\` returned nothing - no database reached." >&2
  exit 2
fi

if [ -n "${SIO_EXPECTED_SITEURL:-}" ]; then
  if [ "$ACTUAL_SITEURL" != "$SIO_EXPECTED_SITEURL" ]; then
    echo "REFUSING TO RUN. Expected $SIO_EXPECTED_SITEURL but reached $ACTUAL_SITEURL." >&2
    exit 2
  fi
else
  # Host must match the domain Local has on record for this site.
  ACTUAL_HOST="$(printf '%s' "$ACTUAL_SITEURL" | sed -E 's#^[a-z]+://##; s#/.*$##; s#:[0-9]+$##')"
  if [ "$ACTUAL_HOST" != "$SITE_DOMAIN" ]; then
    echo >&2
    echo "REFUSING TO RUN." >&2
    echo "Local says $SITE_NAME is $SITE_DOMAIN, but this socket reached $ACTUAL_SITEURL." >&2
    echo "That is the two-database trap: these files and that database are different sites." >&2
    echo "Set SIO_EXPECTED_SITEURL=$ACTUAL_SITEURL if the mismatch is deliberate." >&2
    exit 2
  fi
fi

echo "siteurl: $ACTUAL_SITEURL  (matches $SITE_DOMAIN)"

if ! wp_run plugin is-active swift-image-optimizer >/dev/null 2>&1; then
  echo "swift-image-optimizer is not active on $ACTUAL_SITEURL" >&2
  exit 2
fi

if wp_run core is-installed --network >/dev/null 2>&1; then
  echo "note   : multisite - every command below runs against $ACTUAL_SITEURL only"
fi

# --------------------------------------------------------------------------
# Smoke pass: the read-only commands. Cheap, safe on any site including a
# production-shaped one, and the right first thing to run on a site you have
# not tested before.
# --------------------------------------------------------------------------

echo
echo "########## smoke: read-only commands ##########"

smoke_failed=0

run_smoke() {
  echo
  echo "\$ wp swift-image-optimizer $*"
  wp_run swift-image-optimizer "$@" || { echo "FAILED: $*" >&2; smoke_failed=1; }
}

run_smoke diagnostics
run_smoke stats
run_smoke logs --lines=5
run_smoke optimize --dry-run

if [ "$smoke_failed" -ne 0 ]; then
  echo >&2
  echo "Read-only commands failed on $SITE_NAME. Not running the bulk harness." >&2
  exit 1
fi

if [ "$SMOKE_ONLY" -eq 1 ]; then
  echo
  echo "SMOKE PASSED on $ACTUAL_SITEURL (bulk harness skipped: --smoke)"
  exit 0
fi

# --------------------------------------------------------------------------
# The harness itself. It creates its own fixtures and refuses to run if the
# library holds anything else pending or restorable.
# --------------------------------------------------------------------------

echo
echo "########## cli-bulk-e2e ##########"

SIO_PHP="$PHP_BIN" \
SIO_WP="$WP_BIN" \
SIO_SOCKET="$SITE_SOCKET" \
SIO_EXPECTED_SITEURL="$ACTUAL_SITEURL" \
"$PHP_BIN" -d mysqli.default_socket="$SITE_SOCKET" tests/php/cli-bulk-e2e.php

status=$?

echo
if [ "$status" -ne 0 ]; then
  echo "CLI SUITE FAILED on $ACTUAL_SITEURL ($SITE_NAME)"
  exit "$status"
fi

echo "CLI SUITE PASSED on $ACTUAL_SITEURL ($SITE_NAME)"
