# Per-app WebUI configuration

DockerManger displays **individual containers** on **Apps**, with their parent Compose stack. Each app can have **zero or one** **Open WebUI** button. The same optional link appears beside **Console** and **Logs** in the stack's **Stack Containers** panel. Apps without a configured URL do not show a WebUI button.

There are two supported ways to configure a URL:

1. A `com.dockermanger.webui` **label on an individual Compose service** (preferred when the Compose file is managed).
2. A **manual URL** saved from DockerManger's per-app WebUI editor.

The URL is a browser shortcut only. It does not publish ports, change Docker networking, or proxy traffic. Set a reachable IP/hostname, protocol and port.

## Compose service labels

Put `com.dockermanger.webui` under the **service's** `labels:` block, not the stack's root. For example, the following one stack contains two independent apps with separate WebUI URLs:

```yaml
name: nginx-multi

services:
  nginx-test-1:
    image: nginx:alpine
    container_name: nginx-test-1
    restart: unless-stopped
    ports:
      - "8081:80"
    labels:
      com.dockermanger.webui: "http://192.168.2.27:8081"

  nginx-test-2:
    image: nginx:alpine
    container_name: nginx-test-2
    restart: unless-stopped
    ports:
      - "8082:80"
    labels:
      com.dockermanger.webui: "http://192.168.2.27:8082"
```

For a second, single-container stack:

```yaml
name: nginx-single

services:
  nginx-test-3:
    image: nginx:alpine
    container_name: nginx-test-3
    ports:
      - "8083:80"
    labels:
      com.dockermanger.webui: "http://192.168.2.27:8083"
```

Replace `192.168.2.27` with a URL reachable **from the browser**; it is the address of the Debian 13 development test host, not a default DockerManger address. Some apps do not expose a web interface—just omit their WebUI label.

## Where to edit: two distinct sources of truth

### When the WebUI comes from a Compose label

1. Open **Stacks**, select the stack, and find **App settings → Open WebUI**.
2. Choose the specific app in **App in this stack**.
3. The label-controlled URL is shown **read-only**. **Save WebUI** and **Remove WebUI** are disabled for that app.
4. Click **Edit** beside the existing app's **Open WebUI** link. It takes you to the corresponding service's label in the **Compose editor**.
5. Change or delete the `com.dockermanger.webui` label and click **Validate & Save**.

**The saved, validated Compose configuration is authoritative for a labeled app.** DockerManger resolves managed stack labels via `docker compose config --format json`, so changing/removing a saved label updates the displayed shortcut without recreating a running container. **Validate & Save does not redeploy the stack**: use **Up/Update** later if you also want Docker's running containers/labels to reflect the edited Compose file.

This is the tested **source-of-truth Edit flow**: the button opens the place where the active value is actually defined, rather than letting a second manual entry silently override it.

### When there is no Compose WebUI label

1. In **App settings → Open WebUI**, select the container.
2. Enter an optional HTTP(S) address and click **Save WebUI**.
3. When a URL is already saved, click **Edit** beside that app's **Open WebUI** link to select it and focus the manual URL field.
4. Click **Save WebUI** after changing the URL, or **Remove WebUI** to clear it.

Manual settings are stored in DockerManger's persistent `/data/config/webui.json`. They do not change Compose, container environment variables, or running containers. On an individual container page, the same WebUI edit/remove controls are available.

## Precedence and removal

| Current state | Visible Open WebUI | Correct edit location |
| --- | --- | --- |
| Valid WebUI label in saved managed Compose | Label URL | Compose editor; Validate & Save |
| No Compose label; manual URL exists | Manual URL | App WebUI editor; Save WebUI or Remove WebUI |
| Neither is configured | No button | Add an optional label or manual URL |
| Both saved label and manual URL exist | Compose label wins | Edit the Compose label |
| Saved Compose differs from deployed container | Saved Compose label, where resolvable | Compose editor; deploy separately |
| Saved Compose config cannot be resolved | Runtime label fallback, subject to manual settings | Resolve Compose validation/config errors |

A previously saved manual URL may reappear when its higher-priority Compose label is removed. To leave the app without any WebUI link, remove the manual URL too. A blank manual setting is retained to suppress a stale runtime label. If the Compose config is invalid or cannot be resolved, investigate before relying on the displayed fallback URL.

**Only one URL per app is displayed.** Earlier experimental named label keys such as `com.dockermanger.webui.alternate` are not part of the supported UI and are ignored. A stack can still have many WebUI links overall—**one for each app**.

## Reproduce the Debian 13 Nginx test

Create directories `/opt/stacks/nginx-multi` and `/opt/stacks/nginx-single`, place the examples above into each directory's `compose.yaml`, then run:

```bash
docker compose -f /opt/stacks/nginx-multi/compose.yaml up -d
docker compose -f /opt/stacks/nginx-single/compose.yaml up -d

docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
docker inspect nginx-test-1 nginx-test-2 nginx-test-3 \
  --format '{{.Name}} {{json .Config.Labels}}'
```

Test these cases:

1. **Apps** shows nginx-test-1, nginx-test-2 and nginx-test-3 separately, with their correct stack names and one Open WebUI each.
2. **Stack Containers** shows Open WebUI next to Console and Logs for apps with a configured URL.
3. Change nginx-test-1's Compose label URL, click **Validate & Save**, and confirm that DockerManger immediately shows the new URL. Note that changing a shortcut **does not** create a listener on the new port.
4. Remove nginx-test-2's label, click **Validate & Save**. Its Open WebUI button disappears **if no manual URL has been saved**.
5. With no label, manually save `http://192.168.2.27:8082` for nginx-test-2. Use the link's **Edit** button to make a change; then use **Remove WebUI** and verify that its button disappears.
6. Re-add nginx-test-2's Compose label and **Validate & Save**. The label should again control the displayed URL.

These are the workflows exercised during the Debian 13 feature tests, including the contextual **Edit** action shown in the UI screenshots.

To stop/remove test containers when finished (without deleting Compose files or saved settings):

```bash
docker compose -f /opt/stacks/nginx-multi/compose.yaml down
docker compose -f /opt/stacks/nginx-single/compose.yaml down
```

## Troubleshooting

- **Missing Open WebUI button:** Check the exact label key, URL syntax, Compose validation, app selection, and whether a manual URL exists. No URL intentionally means no button.
- **Button remains after removing a label:** A saved manual URL may exist. Remove it in App settings. If saved Compose cannot be resolved, old runtime labels may temporarily be used as fallback.
- **`docker inspect` shows an old label:** Docker containers retain their old labels until recreated, even though DockerManger can immediately read the saved Compose file.
- **Edit takes you to Compose:** Correct for a label-controlled WebUI; the manual URL field is intentionally read-only.
- **URL opens but page fails:** Check browser reachability, service listener, scheme, port, reverse proxy and firewall. Labels never configure network publishing.
- **Old `WEBUI_URL` environment values:** These are not the supported WebUI shortcut configuration.

Use `http://` or `https://` URLs without embedded username/password credentials. Avoid placing private access tokens in browser shortcuts.

[Back to README](../README.md)
