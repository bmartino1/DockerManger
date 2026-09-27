#!/bin/bash
set -e
mkdir -p "${STACKS_DIR:-/VMs/docker}" /data /run/php
exec "$@"
