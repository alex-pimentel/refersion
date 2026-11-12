#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

docker run --rm \
    --user "$(id -u):$(id -g)" \
    -v "$PROJECT_DIR":/app \
    -w /app \
    composer:2 \
    ./vendor/bin/pint "$@"
