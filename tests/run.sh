#!/usr/bin/env bash
# Unit tests: no database, no network. Core's BoxFilter is read from
# SHOPCLASS_CORE, or ../osclass; it must be a Shopclass 7.1 checkout.
set -u
cd "$(dirname "$0")/.."
fail=0
for t in tests/*.php; do
    echo "── $t"
    php "$t" | tail -3
    [ "${PIPESTATUS[0]}" -eq 0 ] || fail=1
done
echo "── assets/map-search.js"
node --check assets/map-search.js || fail=1
echo "── tests/map-search.test.js"
node tests/map-search.test.js | tail -1
[ "${PIPESTATUS[0]}" -eq 0 ] || fail=1
for f in index.php settings.php src/*.php; do
    php -l "$f" >/dev/null || fail=1
done
exit "$fail"
