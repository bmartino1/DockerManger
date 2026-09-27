#!/usr/bin/env node
'use strict';

// Fixed-purpose helper used by PHP. The supplied text is data for Composerize;
// it is never executed as a shell command by DockerManger.
const { convertDockerRunToCompose } = require('composerize');

try {
  const encoded = process.argv[2] || '';
  const input = Buffer.from(encoded, 'base64').toString('utf8').trim();
  if (!input || input.length > 65536) throw new Error('Docker run command is empty or too large.');
  if (!/^docker\s+run(?:\s|$)/i.test(input)) throw new Error('Paste a docker run command beginning with "docker run".');
  const compose = convertDockerRunToCompose(input, null, 'latest', 2);
  process.stdout.write(String(compose).trim() + '\n');
} catch (error) {
  process.stderr.write((error && error.message ? error.message : 'Unable to convert Docker run command.') + '\n');
  process.exit(1);
}
