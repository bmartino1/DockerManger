(() => {
    'use strict';

    const surface = document.getElementById('terminal');
    if (!surface || typeof Terminal === 'undefined') return;

    const status = document.getElementById('terminal-status');
    const title = document.getElementById('terminal-title');
    const terminal = new Terminal({
        cursorBlink: true,
        convertEol: true,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
        fontSize: 14,
        scrollback: 5000,
        theme: { background: '#080d12' }
    });
    terminal.open(surface);
    terminal.focus();

    const params = new URLSearchParams({ target: surface.dataset.target || 'local' });
    if (surface.dataset.container) params.set('container', surface.dataset.container);
    const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const socket = new WebSocket(`${scheme}//${location.host}/terminal-ws?${params.toString()}`);

    const resize = () => {
        // Keep the PTY dimensions bounded even without the optional FitAddon.
        const cols = Math.max(40, Math.min(180, Math.floor(surface.clientWidth / 8.5)));
        const rows = Math.max(12, Math.min(60, Math.floor(surface.clientHeight / 18)));
        terminal.resize(cols, rows);
        if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ type: 'resize', cols, rows }));
    };

    socket.addEventListener('open', () => {
        status.textContent = 'Connected';
        resize();
    });
    socket.addEventListener('message', event => {
        let message;
        try { message = JSON.parse(event.data); } catch (_) { return; }
        if (message.type === 'output') terminal.write(message.data);
        if (message.type === 'ready') title.textContent = message.label || 'Interactive console';
        if (message.type === 'error') { terminal.writeln(`\r\nDockerManger: ${message.message}`); status.textContent = 'Error'; }
        if (message.type === 'exit') status.textContent = `Session exited (${message.exitCode})`;
    });
    socket.addEventListener('close', () => { if (status.textContent === 'Connected') status.textContent = 'Disconnected'; });
    socket.addEventListener('error', () => { status.textContent = 'WebSocket connection failed'; });
    terminal.onData(data => {
        if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ type: 'input', data }));
    });
    window.addEventListener('resize', resize);
})();
