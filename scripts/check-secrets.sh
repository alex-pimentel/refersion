#!/usr/bin/env bash
set -euo pipefail

if git ls-files --error-unmatch .env >/dev/null 2>&1; then
    echo "ERROR: .env is tracked by Git. Remove it from the index before committing." >&2
    exit 1
fi

if git grep -n -E '^(REFERSION_CLIENT_ID|REFERSION_CLIENT_SECRET|APP_KEY)=.+' -- . ':!.env.example' ':!phpunit.xml'; then
    echo "ERROR: non-empty credential values were found in tracked files." >&2
    exit 1
fi

echo "No credentials found in tracked files."
