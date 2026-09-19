#!/usr/bin/env bash
# Develop or test against an unreleased mizbanha/laravel-sms checkout (default
# ../laravel-sms). Adds a path repository to composer.json — do not commit it.
set -euo pipefail
CORE_PATH="${1:-../laravel-sms}"
composer config repositories.core "{\"type\": \"path\", \"url\": \"${CORE_PATH}\", \"options\": {\"symlink\": false, \"versions\": {\"mizbanha/laravel-sms\": \"0.1.1\"}}}"
composer update mizbanha/laravel-sms --with-dependencies --no-interaction
