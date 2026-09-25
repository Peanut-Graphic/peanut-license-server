#!/usr/bin/env bash
set -euo pipefail

workflow=".github/workflows/wp-contract.yml"
url="https://phar.phpunit.de/phpunit-9.6.37.phar"
sha256="3466327b9c9f5047cfd71acc4d19620fa4cc943e7590b8f32a3ac173fd56c363"

test "$(grep -Fxc "  PHPUNIT_PHAR_URL: ${url}" "${workflow}")" -eq 1
test "$(grep -Fxc "  PHPUNIT_PHAR_SHA256: ${sha256}" "${workflow}")" -eq 1
test "$(grep -Fxc '          printf '\''%s  %s\n'\'' "$PHPUNIT_PHAR_SHA256" /tmp/phpunit9.phar \' "${workflow}")" -eq 2
test "$(grep -Fxc '        run: bash scripts/tests/phpunit-phar-lock.test.sh' "${workflow}")" -eq 2

if grep -Fq 'https://phar.phpunit.de/phpunit-9.phar' "${workflow}"; then
    echo "floating PHPUnit PHAR selector found in ${workflow}" >&2
    exit 1
fi

echo "License Server PHPUnit PHAR is exact-version and SHA-256 locked."
