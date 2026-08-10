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

if [ $# -gt 0 ]; then
  SUITES=("$@")
else
  # rewriter-test first: it needs no database, so a failure there is a pure
  # logic regression and worth seeing before anything touches WordPress.
  SUITES=(rewriter-test convert-restore-e2e bulk-e2e)
fi

failed=0

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
