#!/usr/bin/env node
'use strict';

/*
 * Fixed-purpose Composerize adapter used by DockerManger's PHP API.
 *
 * The supplied docker-run text is data only. It is never evaluated or passed
 * to a shell. Composerize 1.7.x has appeared with more than one CommonJS
 * export shape in the wild, so resolve the public converter defensively.
 */
const composerizeModule = require('composerize');

function resolveConverter(mod) {
  const candidates = [
    mod && mod.convertDockerRunToCompose,
    mod && mod.default && mod.default.convertDockerRunToCompose,
    mod && mod.default,
    mod,
  ];

  for (const candidate of candidates) {
    if (typeof candidate === 'function') return candidate;
  }

  const keys = mod && typeof mod === 'object' ? Object.keys(mod).join(', ') : typeof mod;
  throw new Error(`Composerize converter export was not found (exports: ${keys || 'none'}).`);
}

try {
  const encoded = process.argv[2] || '';
  const input = Buffer.from(encoded, 'base64').toString('utf8').trim();

  if (!input || input.length > 65536) {
    throw new Error('Docker run command is empty or too large.');
  }
  if (!/^docker\s+run(?:\s|$)/i.test(input)) {
    throw new Error('Paste a docker run command beginning with "docker run".');
  }

  const convertDockerRunToCompose = resolveConverter(composerizeModule);
  const compose = convertDockerRunToCompose(input, null, 'latest', 2);

  if (typeof compose !== 'string' || compose.trim() === '') {
    throw new Error('Composerize returned an empty Compose document.');
  }

  process.stdout.write(compose.trim() + '\n');
} catch (error) {
  process.stderr.write((error && error.message ? error.message : 'Unable to convert Docker run command.') + '\n');
  process.exit(1);
}
