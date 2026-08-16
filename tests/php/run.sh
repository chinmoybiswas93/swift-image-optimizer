#!/usr/bin/env bash
#
# Run the PHP harnesses against the Local install.
#
#   tests/php/run.sh                  # every harness
#   tests/php/run.sh rewriter-test    # one of them
#   SIO_TEST_ENGINE=gd tests/php/run.sh
#
# Local names every database `local` and distinguishes sites only by socket,
# so the socket is pinned here rather than left to chance. Each harness also
# re-checks siteurl before touching anything.

set -uo pipefail

SITE_SOCKET="${SIO_SOCKET:-$HOME/Library/Application Support/Local/run/aRpCXvFUz/mysql/mysqld.sock}"
PHP_BIN="${SIO_PHP:-$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php}"

cd "$(dirname "$0")/../.." || exit 2

if [ ! -x "$PHP_BIN" ]; then
  echo "PHP binary not found: $PHP_BIN" >&2
  echo "Override with SIO_PHP=/path/to/php" >&2
  exit 2
fi

if [ ! -S "$SITE_SOCKET" ]; then
  echo "MySQL socket not found: $SITE_SOCKET" >&2
  echo "Is the Local site running? Override with SIO_SOCKET=/path/to/mysqld.sock" >&2
  exit 2
fi

WEB_MODE=0
if [ "${1:-}" = "--web" ]; then
  WEB_MODE=1
  shift
fi

if [ $# -gt 0 ]; then
  SUITES=("$@")
else
  # The two database-free suites run first: a failure in either is a pure
  # logic regression, worth seeing before anything touches WordPress.
  SUITES=(rewriter-test notice-strip-test convert-restore-e2e bulk-e2e)
fi

failed=0

# ---------------------------------------------------------------------------
# Web mode: run each suite through php-fpm instead of CLI PHP.
#
# CLI PHP here has no Imagick, so a CLI run exercises cwebp whatever engine is
# requested - the whole of issue I-2. The token is created immediately before
# the request and removed immediately after, in every exit path.
# ---------------------------------------------------------------------------
if [ "$WEB_MODE" -eq 1 ]; then
  BASE_URL="${SIO_BASE_URL:-https://cb-test.local}"
  TOKEN_FILE="tests/php/.web-token"

  cleanup_token() { rm -f "$TOKEN_FILE"; }
  trap cleanup_token EXIT INT TERM

  for suite in "${SUITES[@]}"; do
    suite="${suite%.php}"

    if [ ! -f "tests/php/${suite}.php" ]; then
      echo "No such harness: tests/php/${suite}.php" >&2
      failed=1
      continue
    fi

    TOKEN="$(openssl rand -hex 24)"
    printf '%s' "$TOKEN" > "$TOKEN_FILE"

    echo
    echo "########## $suite (web / php-fpm${SIO_TEST_ENGINE:+, engine=$SIO_TEST_ENGINE}) ##########"

    url="${BASE_URL}/wp-content/plugins/swift-image-optimizer/tests/php/web-runner.php?token=${TOKEN}&suite=${suite}"
    [ -n "${SIO_TEST_ENGINE:-}" ] && url="${url}&engine=${SIO_TEST_ENGINE}"

    body="$(curl -sk --max-time 600 "$url")"
    echo "$body"

    # The harness exits non-zero on failure, but curl cannot see that, so the
    # summary line is the contract.
    if ! printf '%s' "$body" | grep -q '^OK: '; then
      failed=1
    fi

    cleanup_token
  done

  echo
  if [ "$failed" -ne 0 ]; then
    echo "SUITE FAILED"
    exit 1
  fi

  echo "ALL SUITES PASSED"
  exit 0
fi

for suite in "${SUITES[@]}"; do
  file="tests/php/${suite%.php}.php"

  if [ ! -f "$file" ]; then
    echo "No such harness: $file" >&2
    failed=1
    continue
  fi

  echo
  echo "########## $suite ##########"

  "$PHP_BIN" -d mysqli.default_socket="$SITE_SOCKET" "$file" || failed=1
done

echo
if [ "$failed" -ne 0 ]; then
  echo "SUITE FAILED"
  exit 1
fi

echo "ALL SUITES PASSED"
