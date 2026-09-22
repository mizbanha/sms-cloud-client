#!/usr/bin/env bash
# Develop or test against an unreleased mizbanha/laravel-sms checkout (default
# ../laravel-sms). Adds a path repository to composer.json — do not commit it.
set -euo pipefail
CORE_PATH="${1:-../laravel-sms}"
composer config repositories.core "{\"type\": \"path\", \"url\": \"${CORE_PATH}\", \"options\": {\"symlink\": false, \"versions\": {\"mizbanha/laravel-sms\": \"0.1.1\"}}}"
# A partial update needs a lock file to update FROM, and this package commits
# none - so a clean checkout (CI, or a fresh clone) has nothing to narrow. Fall
# back to resolving everything there; narrow the update when a lock exists so a
# developer running this does not have the rest of their dependencies moved.
if [ -f composer.lock ]; then
  composer update mizbanha/laravel-sms --with-dependencies --no-interaction
else
  composer update --no-interaction
fi
