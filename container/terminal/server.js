'use strict';

/**
 * DockerManger terminal transport.
 *
 * This service intentionally accepts only three named terminal targets:
 * local, container, and host. Browser input becomes PTY input only after the
 * target has been selected server-side; there is no arbitrary command API.
 */
const http = require('http');
const { spawnSync } = require('child_process');
const pty = require('node-pty');
const { WebSocketServer } = require('ws');

const host = '127.0.0.1';
const port = Number(process.env.DOCKERMANGER_TERMINAL_PORT || 3000);
const envBool = (name, fallback = false) => {
  const raw = process.env[name];
  if (raw === undefined || String(raw).trim() === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(raw).trim().toLowerCase());
};
const enabled = envBool('DOCKERMANGER_ENABLE_CONSOLE', true);
const hostShellEnabled = envBool('DOCKERMANGER_HOST_SHELL_ENABLED', false);
const containerPattern = /^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/;

function shellForContainer(name) {
  const probe = spawnSync('docker', ['exec', name, 'sh', '-c', 'command -v bash || command -v sh'], { encoding: 'utf8' });
  if (probe.status !== 0) throw new Error('Container is not running or does not provide a supported shell.');
  const shell = probe.stdout.trim().split(/\s+/)[0];
  if (!shell || !['/bin/bash', '/usr/bin/bash', '/bin/sh', '/usr/bin/sh'].includes(shell)) {
    throw new Error('Container does not provide bash or sh.');
  }
  return shell;
}

function terminalCommand(url) {
  const target = url.searchParams.get('target') || process.env.DOCKERMANGER_CONSOLE_DEFAULT_TARGET || 'local';

  if (target === 'local') return { file: '/bin/bash', args: ['-l'], label: 'DockerManger shell' };

  if (target === 'container') {
    const name = (url.searchParams.get('container') || '').trim();
    if (!containerPattern.test(name)) throw new Error('Invalid container name.');
    const inspect = spawnSync('docker', ['container', 'inspect', name], { stdio: 'ignore' });
    if (inspect.status !== 0) throw new Error('Container not found.');
    const shell = shellForContainer(name);
    return { file: 'docker', args: ['exec', '-it', name, shell], label: `Container: ${name}` };
  }

  if (target === 'host') {
    if (!hostShellEnabled) throw new Error('Host SSH console is disabled.');
    const sshHost = process.env.DOCKERMANGER_HOST_SSH_HOST || 'host.docker.internal';
    const sshPort = process.env.DOCKERMANGER_HOST_SSH_PORT || '22';
    const sshUser = process.env.DOCKERMANGER_HOST_SSH_USER || 'root';
    const sshKey = (process.env.DOCKERMANGER_HOST_SSH_KEY || '').trim();
    const args = ['-tt', '-p', sshPort];
    if (sshKey) args.push('-i', sshKey);
    args.push(`${sshUser}@${sshHost}`);
    return { file: 'ssh', args, label: `Host: ${sshUser}@${sshHost}` };
  }

  throw new Error('Unsupported console target.');
}

const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(enabled ? 200 : 503, { 'Content-Type': 'text/plain' });
    res.end(enabled ? 'ok\n' : 'disabled\n');
    return;
  }
  res.writeHead(404).end();
});

const wss = new WebSocketServer({ noServer: true });
server.on('upgrade', (req, socket, head) => {
  if (!enabled) return socket.destroy();
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname !== '/terminal-ws') return socket.destroy();
  wss.handleUpgrade(req, socket, head, ws => wss.emit('connection', ws, req, url));
});

wss.on('connection', (ws, req, url) => {
  let term;
  try {
    const command = terminalCommand(url);
    term = pty.spawn(command.file, command.args, {
      name: process.env.DOCKERMANGER_TERMINAL_TYPE || 'xterm-256color',
      cols: 120,
      rows: 32,
      cwd: '/root',
      env: { ...process.env, TERM: process.env.DOCKERMANGER_TERMINAL_TYPE || 'xterm-256color' }
    });
    ws.send(JSON.stringify({ type: 'ready', label: command.label }));
    term.onData(data => { if (ws.readyState === ws.OPEN) ws.send(JSON.stringify({ type: 'output', data })); });
    term.onExit(({ exitCode }) => {
      if (ws.readyState === ws.OPEN) ws.send(JSON.stringify({ type: 'exit', exitCode }));
      ws.close();
    });
  } catch (error) {
    ws.send(JSON.stringify({ type: 'error', message: error.message || 'Unable to start terminal.' }));
    ws.close();
    return;
  }

  ws.on('message', raw => {
    try {
      const message = JSON.parse(raw.toString());
      if (message.type === 'input' && typeof message.data === 'string' && message.data.length <= 65536) term.write(message.data);
      if (message.type === 'resize') {
        const cols = Math.max(20, Math.min(400, Number(message.cols) || 120));
        const rows = Math.max(5, Math.min(200, Number(message.rows) || 32));
        term.resize(cols, rows);
      }
    } catch (_) { /* Ignore malformed client frames. */ }
  });
  ws.on('close', () => { if (term) term.kill(); });
});

server.listen(port, host, () => {
  console.log(`[DockerManger] Terminal service listening on ${host}:${port}`);
});
