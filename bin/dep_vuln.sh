#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if ! command -v trivy >/dev/null 2>&1; then
    echo "trivy not found. Install with: brew install trivy" >&2
    exit 1
fi

cd "$PROJECT_ROOT"

trivy fs \
    --scanners vuln \
    --skip-files "vendor/**/package-lock.json" \
    --skip-dirs "vendor/mockery,vendor/**/docs" \
    --severity UNKNOWN,MEDIUM,HIGH,CRITICAL \
    --exit-code 1 \
    "$@" \
    .
