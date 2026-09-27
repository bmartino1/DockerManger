#!/bin/bash
set -euo pipefail

curl \
    --fail \
    --silent \
    --show-error \
    --max-time 5 \
    http://127.0.0.1/health \
    >/dev/null
