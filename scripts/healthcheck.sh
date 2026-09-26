#!/bin/bash
set -e
curl --fail --silent --max-time 3 http://127.0.0.1/health >/dev/null
