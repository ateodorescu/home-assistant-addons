<?php
/** @var array{server_id:string,name:string,host:string,port:string,user:string,password:string,interface:string,kg_key:string,privilege_level:string,extra:string,command_args:string} $form */
/** @var array<string,mixed>|null $result */
/** @var list<string> $interfaces */
/** @var list<string> $privileges */
/** @var array<string, string> $actions */
/** @var string $action */
/** @var list<array<string, string>> $servers */
/** @var int $apiVersion */
/** @var string $addonVersion */

$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$serversJson = json_encode($servers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IPMItool server</title>
    <style>
        :root {
            --bg: #f3f5f7;
            --surface: #ffffff;
            --text: #1c2430;
            --muted: #5b6775;
            --border: #d5dde5;
            --accent: #0b6e4f;
            --accent-hover: #095c42;
            --danger: #a33b2d;
            --danger-hover: #8a2f24;
            --danger-bg: #fdebec;
            --danger-text: #8a1f2b;
            --ok-bg: #e8f6ef;
            --ok-text: #0b6e4f;
            --shadow: 0 10px 30px rgba(28, 36, 48, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(11, 110, 79, 0.08), transparent 40%),
                linear-gradient(180deg, #eef2f6 0%, var(--bg) 100%);
            min-height: 100vh;
        }
        main {
            max-width: 960px;
            margin: 0 auto;
            padding: 2rem 1.25rem 3rem;
        }
        h1 {
            margin: 0 0 0.35rem;
            font-size: 1.75rem;
            letter-spacing: -0.02em;
        }
        h2 {
            margin: 0 0 0.75rem;
            font-size: 1.05rem;
        }
        .lead {
            margin: 0 0 0.5rem;
            color: var(--muted);
        }
        .version {
            margin: 0 0 1.5rem;
            color: var(--muted);
            font-size: 0.85rem;
        }
        .version code {
            font-size: 0.85em;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.9rem 1rem;
        }
        label {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            font-size: 0.9rem;
            font-weight: 600;
        }
        label.full { grid-column: 1 / -1; }
        input, select, button, textarea {
            font: inherit;
        }
        input, select, textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.7rem 0.8rem;
            background: #fff;
            color: var(--text);
        }
        textarea {
            min-height: 2.8rem;
            resize: vertical;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.9rem;
        }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid rgba(11, 110, 79, 0.25);
            border-color: var(--accent);
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            margin-top: 1rem;
        }
        button {
            border: 0;
            border-radius: 999px;
            padding: 0.7rem 1.05rem;
            background: var(--accent);
            color: #fff;
            font-weight: 650;
            cursor: pointer;
        }
        button:hover { background: var(--accent-hover); }
        button:disabled {
            opacity: 0.65;
            cursor: wait;
        }
        button.secondary {
            background: #243447;
        }
        button.secondary:hover {
            background: #1a2735;
        }
        button.danger {
            background: var(--danger);
        }
        button.danger:hover {
            background: var(--danger-hover);
        }
        .hint {
            margin-top: 0.85rem;
            color: var(--muted);
            font-size: 0.85rem;
        }
        .banner {
            border-radius: 10px;
            padding: 0.85rem 1rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }
        .banner.error {
            background: var(--danger-bg);
            color: var(--danger-text);
        }
        .banner.ok {
            background: var(--ok-bg);
            color: var(--ok-text);
        }
        .sensor-group + .sensor-group { margin-top: 1.25rem; }
        .sensor-group h2 {
            margin: 0 0 0.6rem;
            text-transform: capitalize;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 0.65rem 0.4rem;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        th {
            color: var(--muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        td.value {
            font-variant-numeric: tabular-nums;
            font-weight: 650;
            white-space: nowrap;
        }
        .empty {
            color: var(--muted);
        }
        details {
            margin-top: 1rem;
        }
        pre {
            margin: 0.5rem 0 0;
            padding: 0.85rem;
            overflow: auto;
            background: #111827;
            color: #e5e7eb;
            border-radius: 10px;
            font-size: 0.8rem;
            white-space: pre-wrap;
        }
        .command-prefix {
            color: var(--muted);
            font-size: 0.85rem;
            margin-bottom: 0.35rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        #result-panel[hidden] {
            display: none;
        }
        .loading-mask {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(28, 36, 48, 0.45);
            backdrop-filter: blur(2px);
        }
        .loading-mask.is-visible {
            display: flex;
        }
        .loading-dialog {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.85rem;
            padding: 1.35rem 1.6rem;
            border-radius: 14px;
            background: var(--surface);
            box-shadow: var(--shadow);
            color: var(--text);
            font-weight: 650;
        }
        .spinner {
            width: 2rem;
            height: 2rem;
            border: 3px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @media (max-width: 720px) {
            .grid { grid-template-columns: 1fr; }
            label.full { grid-column: auto; }
        }
    </style>
</head>
<body>
<main>
    <h1>IPMItool server</h1>
    <p class="lead">Connect to a BMC, read sensors, run power commands, or send a custom <code>ipmitool</code> command.</p>
    <p class="version">API version <?= $e((string) $apiVersion) ?> · Add-on <?= $e($addonVersion) ?></p>

    <form id="ipmi-form" method="post" action="">
        <input type="hidden" name="server_id" id="server_id" value="<?= $e($form['server_id']) ?>">

        <section class="card">
            <h2>Saved servers</h2>
            <div class="grid">
                <label class="full">
                    Load saved server
                    <select id="server_picker">
                        <option value="">New server…</option>
                    </select>
                </label>
                <label class="full">
                    Profile name
                    <input type="text" name="name" id="server_name" value="<?= $e($form['name']) ?>" placeholder="Living room NAS" autocomplete="off">
                </label>
            </div>
            <div class="actions">
                <button type="submit" class="secondary" name="ui_action" value="save_server">Save server</button>
                <button type="submit" class="danger" name="ui_action" value="delete_server"
                        formnovalidate
                        data-confirm="Delete this saved server?">Delete saved</button>
            </div>
            <p class="hint">Profiles (including password and kg key) are stored on this add-on in <code>/data/ipmi-servers.json</code>.</p>
        </section>

        <section class="card">
            <h2>Connection</h2>
            <div class="grid">
                <label>
                    Host / IP
                    <input type="text" name="host" id="host" required value="<?= $e($form['host']) ?>" placeholder="192.168.1.50" autocomplete="off">
                </label>
                <label>
                    Port
                    <input type="number" name="port" id="port" min="1" max="65535" value="<?= $e($form['port']) ?>">
                </label>
                <label>
                    Username
                    <input type="text" name="user" id="user" value="<?= $e($form['user']) ?>" autocomplete="username">
                </label>
                <label>
                    Password
                    <input type="password" name="password" id="password" value="<?= $e($form['password']) ?>" autocomplete="current-password">
                </label>
                <label>
                    Interface
                    <select name="interface" id="interface">
                        <?php foreach ($interfaces as $interface): ?>
                            <option value="<?= $e($interface) ?>" <?= $form['interface'] === $interface ? 'selected' : '' ?>>
                                <?= $interface === '' ? 'Auto detect' : $e($interface) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Privilege level
                    <select name="privilege_level" id="privilege_level">
                        <?php foreach ($privileges as $privilege): ?>
                            <option value="<?= $e($privilege) ?>" <?= $form['privilege_level'] === $privilege ? 'selected' : '' ?>>
                                <?= $privilege === '' ? 'Default' : $e($privilege) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="full">
                    Kg key (optional)
                    <input type="text" name="kg_key" id="kg_key" value="<?= $e($form['kg_key']) ?>" autocomplete="off">
                </label>
                <label class="full">
                    Extra params (optional)
                    <input type="text" name="extra" id="extra" value="<?= $e($form['extra']) ?>" placeholder="-C 3" autocomplete="off">
                </label>
            </div>
            <p class="hint">Credentials are sent to this add-on only and used to run <code>ipmitool</code> on the backend. Leave password blank when updating a saved server to keep the stored password. Prefer Interface <code>lanplus</code> for remote BMCs; Auto detect only applies to Fetch sensors. Extra params are passed through to <code>ipmitool</code> (for example <code>-C 3</code> for Super Micro cipher suite 3).</p>
        </section>

        <section class="card">
            <h2>Sensors</h2>
            <div class="actions">
                <button type="submit" name="ui_action" value="sensors">Fetch sensors</button>
            </div>
        </section>

        <section class="card">
            <h2>Power commands</h2>
            <div class="actions">
                <button type="submit" class="secondary" name="ui_action" value="power_on"
                        data-confirm="Power on this server?">Power on</button>
                <button type="submit" class="danger" name="ui_action" value="power_off"
                        data-confirm="Power off this server?">Power off</button>
                <button type="submit" class="danger" name="ui_action" value="power_cycle"
                        data-confirm="Power cycle this server?">Power cycle</button>
                <button type="submit" class="danger" name="ui_action" value="power_reset"
                        data-confirm="Reset this server?">Power reset</button>
                <button type="submit" class="danger" name="ui_action" value="soft_shutdown"
                        data-confirm="Request a soft shutdown?">Soft shutdown</button>
            </div>
        </section>

        <section class="card">
            <h2>Custom command</h2>
            <p class="command-prefix">ipmitool [connection options] …</p>
            <label class="full">
                Command arguments
                <textarea name="command_args" placeholder="bmc info"><?= $e($form['command_args']) ?></textarea>
            </label>
            <div class="actions">
                <button type="submit" class="secondary" name="ui_action" value="command">Run custom command</button>
            </div>
            <p class="hint">Example args: <code>bmc info</code>, <code>chassis status</code>, <code>sdr list full</code>. Connection options are added automatically from the form above.</p>
        </section>
    </form>

    <section id="result-panel" class="card" <?= $result === null ? 'hidden' : '' ?>>
        <?php if ($result !== null): ?>
            <?php
            $resultAction = (string) ($result['action'] ?? $action);
            $resultLabel = (string) ($result['action_label'] ?? ($actions[$resultAction] ?? 'Action'));
            ?>

            <?php if (in_array($resultAction, ['save_server', 'delete_server'], true)): ?>
                <?php if (!empty($result['success'])): ?>
                    <div class="banner ok"><?= $e((string) ($result['message'] ?? ($resultLabel.' completed successfully.'))) ?></div>
                <?php else: ?>
                    <div class="banner error"><?= $e((string) ($result['message'] ?? ($resultLabel.' failed.'))) ?></div>
                <?php endif; ?>

            <?php elseif ($resultAction === 'sensors'): ?>
                <?php if (!empty($result['success'])): ?>
                    <div class="banner ok"><?= $e($resultLabel) ?> completed successfully.</div>

                    <?php
                    $sensors = is_array($result['sensors'] ?? null) ? $result['sensors'] : [];
                    $states = is_array($result['states'] ?? null) ? $result['states'] : [];
                    $hasRows = false;
                    ?>

                    <?php foreach ($sensors as $type => $items): ?>
                        <?php if (!is_array($items) || $items === []) { continue; } ?>
                        <?php $hasRows = true; ?>
                        <div class="sensor-group">
                            <h2><?= $e((string) $type) ?></h2>
                            <table>
                                <thead>
                                <tr>
                                    <th>Sensor</th>
                                    <th>Value</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($items as $id => $name): ?>
                                    <tr>
                                        <td><?= $e((string) $name) ?></td>
                                        <td class="value"><?= $e(isset($states[$id]) ? (string) $states[$id] : '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$hasRows): ?>
                        <p class="empty">Connected, but no sensors were returned.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="banner error">
                        <?= $e((string) ($result['message'] ?? 'Failed to fetch sensors.')) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($result['debug'])): ?>
                    <details>
                        <summary>Debug output</summary>
                        <pre><?= $e((string) $result['debug']) ?></pre>
                    </details>
                <?php endif; ?>

            <?php elseif ($resultAction === 'command'): ?>
                <?php if (!empty($result['success'])): ?>
                    <div class="banner ok"><?= $e($resultLabel) ?> completed successfully.</div>
                <?php else: ?>
                    <div class="banner error"><?= $e($resultLabel) ?> failed.</div>
                <?php endif; ?>
                <pre><?= $e((string) ($result['output'] ?? '')) ?></pre>

            <?php else: ?>
                <?php if (!empty($result['success'])): ?>
                    <div class="banner ok"><?= $e($resultLabel) ?> completed successfully.</div>
                <?php else: ?>
                    <div class="banner error"><?= $e($resultLabel) ?> failed. Check connection details and IPMI privileges.</div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<div id="loading-mask" class="loading-mask" hidden aria-hidden="true" role="status" aria-live="polite">
    <div class="loading-dialog">
        <div class="spinner" aria-hidden="true"></div>
        <span id="loading-text">Working…</span>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('ipmi-form');
    const resultPanel = document.getElementById('result-panel');
    const loadingMask = document.getElementById('loading-mask');
    const loadingText = document.getElementById('loading-text');
    const serverPicker = document.getElementById('server_picker');
    const serverIdInput = document.getElementById('server_id');
    const serverNameInput = document.getElementById('server_name');
    const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
    let servers = <?= $serversJson ?>;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const extractErrorText = (raw) => {
        const text = String(raw || '').trim();
        if (!text) {
            return '';
        }

        const tmp = document.createElement('div');
        tmp.innerHTML = text;
        const stripped = (tmp.textContent || text).replace(/\s+/g, ' ').trim();
        return stripped.slice(0, 4000);
    };

    const setLoading = (isLoading, label) => {
        loadingText.textContent = label || 'Working…';
        loadingMask.hidden = !isLoading;
        loadingMask.classList.toggle('is-visible', isLoading);
        loadingMask.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
        buttons.forEach((button) => {
            button.disabled = isLoading;
        });
    };

    const refreshServerPicker = (selectedId = '') => {
        const current = selectedId || serverIdInput.value || '';
        serverPicker.innerHTML = '<option value="">New server…</option>';
        servers.forEach((server) => {
            const option = document.createElement('option');
            option.value = server.id;
            option.textContent = `${server.name} (${server.host})`;
            serverPicker.appendChild(option);
        });
        serverPicker.value = current;
        if (serverPicker.value !== current) {
            serverPicker.value = '';
        }
    };

    const applyServer = (server) => {
        if (!server) {
            serverIdInput.value = '';
            serverNameInput.value = '';
            return;
        }

        serverIdInput.value = server.id || '';
        serverNameInput.value = server.name || '';
        document.getElementById('host').value = server.host || '';
        document.getElementById('port').value = server.port || '623';
        document.getElementById('user').value = server.user || '';
        document.getElementById('password').value = server.password || '';
        document.getElementById('interface').value = server.interface || '';
        document.getElementById('privilege_level').value = server.privilege_level || '';
        document.getElementById('kg_key').value = server.kg_key || '';
        document.getElementById('extra').value = server.extra || '';
    };

    const renderSensors = (result) => {
        const label = escapeHtml(result.action_label || 'Fetch sensors');
        if (!result.success) {
            return `<div class="banner error">${escapeHtml(result.message || 'Failed to fetch sensors.')}</div>`
                + (result.debug ? `<details><summary>Debug output</summary><pre>${escapeHtml(result.debug)}</pre></details>` : '');
        }

        const sensors = result.sensors && typeof result.sensors === 'object' ? result.sensors : {};
        const states = result.states && typeof result.states === 'object' ? result.states : {};
        let html = `<div class="banner ok">${label} completed successfully.</div>`;
        let hasRows = false;

        Object.keys(sensors).forEach((type) => {
            const items = sensors[type];
            if (!items || typeof items !== 'object' || Object.keys(items).length === 0) {
                return;
            }
            hasRows = true;
            html += `<div class="sensor-group"><h2>${escapeHtml(type)}</h2><table><thead><tr><th>Sensor</th><th>Value</th></tr></thead><tbody>`;
            Object.keys(items).forEach((id) => {
                const value = Object.prototype.hasOwnProperty.call(states, id) ? states[id] : '—';
                html += `<tr><td>${escapeHtml(items[id])}</td><td class="value">${escapeHtml(value)}</td></tr>`;
            });
            html += '</tbody></table></div>';
        });

        if (!hasRows) {
            html += '<p class="empty">Connected, but no sensors were returned.</p>';
        }
        if (result.debug) {
            html += `<details><summary>Debug output</summary><pre>${escapeHtml(result.debug)}</pre></details>`;
        }
        return html;
    };

    const renderResult = (result) => {
        const action = result.action || 'sensors';
        const label = escapeHtml(result.action_label || 'Action');

        if (action === 'save_server' || action === 'delete_server') {
            return result.success
                ? `<div class="banner ok">${escapeHtml(result.message || (label + ' completed successfully.'))}</div>`
                : `<div class="banner error">${escapeHtml(result.message || (label + ' failed.'))}</div>`;
        }

        if (action === 'sensors') {
            return renderSensors(result);
        }

        if (action === 'command') {
            const banner = result.success
                ? `<div class="banner ok">${label} completed successfully.</div>`
                : `<div class="banner error">${label} failed.</div>`;
            return `${banner}<pre>${escapeHtml(result.output || '')}</pre>`;
        }

        return result.success
            ? `<div class="banner ok">${label} completed successfully.</div>`
            : `<div class="banner error">${label} failed. Check connection details and IPMI privileges.</div>`;
    };

    const loadingLabels = {
        sensors: 'Fetching sensors…',
        power_on: 'Sending power on…',
        power_off: 'Sending power off…',
        power_cycle: 'Sending power cycle…',
        power_reset: 'Sending power reset…',
        soft_shutdown: 'Sending soft shutdown…',
        command: 'Running command…',
        save_server: 'Saving server…',
        delete_server: 'Deleting server…',
    };

    serverPicker.addEventListener('change', () => {
        const id = serverPicker.value;
        if (!id) {
            serverIdInput.value = '';
            serverNameInput.value = '';
            return;
        }

        const server = servers.find((item) => item.id === id);
        applyServer(server || null);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submitter = event.submitter;
        if (!(submitter instanceof HTMLButtonElement)) {
            return;
        }

        const action = submitter.value || 'sensors';
        const confirmMessage = submitter.getAttribute('data-confirm');
        if (confirmMessage && !window.confirm(confirmMessage)) {
            return;
        }

        if (action === 'delete_server' && !serverIdInput.value) {
            resultPanel.innerHTML = '<div class="banner error">Select a saved server to delete.</div>';
            resultPanel.hidden = false;
            return;
        }

        const formData = new FormData(form);
        formData.set('ui_action', action);

        setLoading(true, loadingLabels[action] || 'Working…');
        if (action !== 'save_server' && action !== 'delete_server') {
            resultPanel.hidden = true;
        }

        try {
            // Always post to the current UI URL. Do not read form.action: a control
            // named "action" would shadow HTMLFormElement.action and break fetch().
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
                credentials: 'same-origin',
            });

            const raw = await response.text();
            let result = null;
            try {
                result = raw ? JSON.parse(raw) : null;
            } catch {
                result = null;
            }

            if (!response.ok) {
                const detail = result && (result.output || result.message)
                    ? String(result.output || result.message)
                    : extractErrorText(raw);
                resultPanel.innerHTML =
                    `<div class="banner error">Request failed (${response.status})</div>`
                    + (detail ? `<pre>${escapeHtml(detail)}</pre>` : '');
                resultPanel.hidden = false;
                resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }

            if (!result || typeof result !== 'object') {
                resultPanel.innerHTML =
                    `<div class="banner error">Invalid JSON response</div>`
                    + `<pre>${escapeHtml(raw.slice(0, 4000))}</pre>`;
                resultPanel.hidden = false;
                return;
            }

            if (Array.isArray(result.servers)) {
                servers = result.servers;
            }

            if (action === 'save_server' && result.success && result.server) {
                applyServer(result.server);
                refreshServerPicker(result.server.id);
            } else if (action === 'delete_server' && result.success) {
                serverIdInput.value = '';
                serverNameInput.value = '';
                refreshServerPicker('');
            } else {
                refreshServerPicker(serverIdInput.value);
            }

            resultPanel.innerHTML = renderResult(result);
            resultPanel.hidden = false;
            resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            resultPanel.innerHTML =
                `<div class="banner error">Request failed</div>`
                + `<pre>${escapeHtml(error.message || 'Request failed.')}</pre>`;
            resultPanel.hidden = false;
        } finally {
            setLoading(false);
        }
    });

    refreshServerPicker(serverIdInput.value);
})();
</script>
</body>
</html>
