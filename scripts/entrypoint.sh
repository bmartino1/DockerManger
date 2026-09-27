#!/bin/bash
set -e
mkdir -p "${STACKS_DIR:-/opt/stacks}" /data /run/php
exec "$@"
