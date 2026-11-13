#!/bin/bash
# PHP lint on every PHP file in the plugin, including the bundled update checker.
cd "$(dirname "$0")/.." || exit 1
status=0
while IFS= read -r -d '' f; do
  out=$(php -l "$f" 2>&1) || { echo "$out"; status=1; }
done < <(find . -name '*.php' -not -path './build/*' -print0)
[ $status -eq 0 ] && echo "PHP lint: no syntax errors"
exit $status
