(() => {
    'use strict';
    // Client-side filtering only; stack discovery remains server-side.
    const search = document.getElementById('stack-search');
    if (search) search.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        document.querySelectorAll('.stack-item').forEach(item => item.hidden = q !== '' && !(item.dataset.stackName || '').includes(q));
    });

    const refresh = document.getElementById('refresh-dashboard');
    if (refresh) refresh.addEventListener('click', () => location.reload());

    // All mutations go through the named-operation API with the page CSRF token.
    async function post(data, outputElement = null) {
        if (outputElement) { outputElement.hidden = false; outputElement.textContent = 'Working…'; }
        try {
            const response = await fetch('/api.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:new URLSearchParams(data)});
            const json = await response.json();
            const message = json.result?.output || json.result?.error || json.error || (json.ok ? 'Completed successfully.' : 'Operation failed.');
            if (outputElement) outputElement.textContent = message;
            if (json.ok) setTimeout(() => location.reload(), 700);
            return json;
        } catch (error) {
            if (outputElement) outputElement.textContent = `Request failed: ${error.message}`;
            return {ok:false};
        }
    }

    const stackActions = document.querySelector('.stack-actions');
    const actionResult = document.getElementById('action-result');
    if (stackActions) stackActions.addEventListener('click', event => {
        const button = event.target.closest('.stack-action'); if (!button) return;
        if (button.dataset.action === 'stack-down' && !confirm('Down removes this stack’s containers and network. Continue?')) return;
        if (button.dataset.action === 'stack-delete') {
            const name = stackActions.dataset.stack;
            if (!confirm(`Permanently DOWN and DELETE stack "${name}"? This removes its containers/network and deletes its stack directory, compose.yaml and .env. This cannot be undone.`)) return;
            post({action:'stack-delete', stack:name, csrf_token:stackActions.dataset.csrf}, actionResult).then(json => { if (json.ok) location.href = '/'; });
            return;
        }
        post({action:button.dataset.action, stack:stackActions.dataset.stack, csrf_token:stackActions.dataset.csrf}, actionResult);
    });

    const editor = document.getElementById('compose-editor');
    if (editor) editor.addEventListener('submit', event => {
        event.preventDefault();
        post({action:'stack-save', stack:editor.dataset.stack, compose:editor.elements.compose.value, csrf_token:editor.dataset.csrf}, actionResult);
    });

    const envEditor = document.getElementById('env-editor');
    if (envEditor) envEditor.addEventListener('submit', event => {
        event.preventDefault();
        post({action:'stack-env-save', stack:envEditor.dataset.stack, env:envEditor.elements.env.value, csrf_token:envEditor.dataset.csrf}, actionResult);
    });

    const composerizeForm = document.getElementById('composerize-form');
    const composerizeResult = document.getElementById('composerize-result');
    if (composerizeForm) composerizeForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (composerizeResult) { composerizeResult.hidden = false; composerizeResult.textContent = 'Converting…'; }
        try {
            const response = await fetch('/api.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:new URLSearchParams({action:'composerize', docker_run:composerizeForm.elements.docker_run.value, csrf_token:composerizeForm.dataset.csrf})});
            const json = await response.json();
            if (!json.ok) throw new Error(json.result?.error || json.error || 'Conversion failed.');
            const compose = json.result?.compose || '';
            const target = document.getElementById('new-stack-compose');
            if (target) { target.value = compose; target.dispatchEvent(new Event('input', { bubbles: true })); }
            if (composerizeResult) composerizeResult.textContent = 'Converted. Review the generated Compose before creating the stack.';
        } catch (error) {
            if (composerizeResult) composerizeResult.textContent = `Conversion failed: ${error.message}`;
        }
    });

    const create = document.getElementById('create-stack-form');
    const createResult = document.getElementById('create-stack-result');
    if (create) {
        const stackInput = create.elements.stack;
        const composeInput = create.elements.compose;
        const nameHint = document.getElementById('stack-name-source');
        let lastComposeName = '';

        const explicitComposeName = text => {
            const match = String(text || '').match(/^name\s*:\s*(["']?)([A-Za-z0-9][A-Za-z0-9_.-]{0,63})\1\s*(?:#.*)?$/m);
            return match ? match[2] : '';
        };
        const syncStackName = () => {
            const composeName = explicitComposeName(composeInput.value);
            if (composeName) {
                if (!stackInput.value || stackInput.value === lastComposeName) stackInput.value = composeName;
                stackInput.value = composeName;
                stackInput.readOnly = true;
                if (nameHint) nameHint.textContent = 'Top-level Compose name: is the stack/project source of truth.';
            } else {
                stackInput.readOnly = false;
                if (lastComposeName && stackInput.value === lastComposeName) stackInput.value = '';
                if (nameHint) nameHint.textContent = 'Used when compose.yaml does not define a top-level name: field.';
            }
            lastComposeName = composeName;
        };

        composeInput.addEventListener('input', syncStackName);
        syncStackName();

        create.addEventListener('submit', async event => {
            event.preventDefault();
            const json = await post({action:'stack-create', stack:stackInput.value, compose:composeInput.value, env:create.elements.env?.value || '', csrf_token:create.dataset.csrf}, createResult);
            if (json.ok) {
                const effectiveName = json.result?.name || stackInput.value;
                location.href = `/?stack=${encodeURIComponent(effectiveName)}`;
            }
        });
    }

    document.addEventListener('click', async event => {
        const action = event.target.closest('.container-action');
        if (action) {
            const card = action.closest('[data-container]');
            if (action.dataset.action === 'container-kill' && !confirm('Force-kill this container immediately?')) return;
            post({action:action.dataset.action, container:card.dataset.container, csrf_token:card.dataset.csrf}, createResult || actionResult);
            return;
        }
        const logs = event.target.closest('.log-container');
        if (logs) {
            const response = await fetch(`/api.php?resource=logs&container=${encodeURIComponent(logs.dataset.container)}`);
            const json = await response.json();
            const viewer = document.getElementById('stack-logs');
            if (viewer) viewer.textContent = json.output || json.error || 'No logs.';
            else alert(json.output || json.error || 'No logs.');
        }
    });

    const refreshContainerLogs = document.getElementById('refresh-container-logs');
    if (refreshContainerLogs) refreshContainerLogs.addEventListener('click', async () => {
        const response = await fetch(`/api.php?resource=logs&container=${encodeURIComponent(refreshContainerLogs.dataset.container)}`);
        const json = await response.json();
        document.getElementById('container-logs').textContent = json.output || json.error || 'No logs.';
    });

    const refreshLogs = document.getElementById('refresh-logs');
    if (refreshLogs) refreshLogs.addEventListener('click', async () => {
        const response = await fetch(`/api.php?resource=logs&stack=${encodeURIComponent(refreshLogs.dataset.stack)}`);
        const json = await response.json();
        document.getElementById('stack-logs').textContent = json.output || json.error || 'No logs.';
    });
})();
