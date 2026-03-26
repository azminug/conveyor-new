<!-- app/Views/device_monitor.php -->
<?= $this->extend('layouts/main') ?>

<?= $this->section('css') ?>
<style>
    .device-card {
        border: 1px solid #dfe6ee;
        border-radius: 12px;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .device-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }
    .device-card.active {
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.12);
        background: #f4f9ff;
    }
    .device-card.offline {
        opacity: 0.78;
    }
    .device-card .device-id {
        font-family: 'Courier New', Courier, monospace;
        font-size: 12px;
        color: #5c6773;
        word-break: break-all;
    }
    .device-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }
    .device-chip.online {
        background: #e8fff1;
        color: #18794e;
    }
    .device-chip.offline {
        background: #fff1f1;
        color: #b42318;
    }
    .device-meta {
        font-size: 12px;
        color: #6c757d;
    }
    #terminal {
        background: #0d1117;
        min-height: 560px;
        max-height: 680px;
        overflow-y: auto;
        font-family: 'Courier New', Courier, monospace;
        font-size: 13px;
        padding: 12px 16px;
        color: #c9d1d9;
    }
    #terminal .log-line {
        margin: 0;
        padding: 1px 0;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-all;
    }
    .term-header {
        background: #161b22;
        border-bottom: 1px solid #30363d;
        padding: 8px 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .term-footer {
        background: #161b22;
        border-top: 1px solid #30363d;
        padding: 6px 14px;
        font-size: 12px;
        font-family: monospace;
        color: #6e7681;
    }
    .dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
    .dot-red    { background: #f85149; }
    .dot-yellow { background: #d29922; }
    .dot-green  { background: #3fb950; }
    .gap-8 { gap: 8px; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row">
    <div class="col-12 mb-3">
        <div class="card card-outline card-primary mb-0">
            <div class="card-body">
                <div class="row align-items-end">
                    <div class="col-md-5 mb-2">
                        <label for="device-filter" class="mb-1">Scope Monitor</label>
                        <select id="device-filter" class="form-control">
                            <option value="all">
                                <?= $isAdmin ? 'Semua device semua DC' : 'Semua device DC ' . esc($userDC) ?>
                            </option>
                            <?php foreach (($devices ?? []) as $device): ?>
                                <option value="<?= esc($device['id']) ?>">
                                    <?= esc(($device['display_name'] ?? $device['id'] ?? '-') . ' - ' . ($device['dcName'] ?? '-')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="small text-muted">Hak akses</div>
                        <div>
                            <?= $isAdmin ? 'Admin dapat monitor semua device lintas DC.' : 'User DC hanya dapat monitor device milik DC sendiri.' ?>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2 text-md-right">
                        <div class="small text-muted">Device terdaftar</div>
                        <div><strong><?= count($devices ?? []) ?></strong> device dalam scope</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 mb-3">
        <div class="row">
            <?php if (!empty($devices ?? [])): ?>
                <?php foreach (($devices ?? []) as $device): ?>
                    <div class="col-12 col-md-6 col-xl-4 mb-3">
                        <div class="card device-card <?= !($device['is_online'] ?? false) ? 'offline' : '' ?>" data-device-id="<?= esc($device['id']) ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <div class="font-weight-bold mb-1" data-role="display-name"><?= esc($device['display_name'] ?? $device['dcName'] ?? '-') ?></div>
                                        <div class="device-id" data-role="device-id"><?= esc($device['id'] ?? '-') ?></div>
                                    </div>
                                    <span class="device-chip <?= ($device['is_online'] ?? false) ? 'online' : 'offline' ?>">
                                        <span>●</span>
                                        <span><?= ($device['is_online'] ?? false) ? 'Online' : 'Offline' ?></span>
                                    </span>
                                </div>

                                <div class="device-meta mb-1">DC: <?= esc($device['dcName'] ?? '-') ?></div>
                                <div class="device-meta mb-1" data-role="location">Lokasi: <?= esc($device['location'] ?? '-') ?></div>
                                <div class="device-meta mb-1">IP: <?= esc($device['ip'] ?? '-') ?></div>
                                <div class="device-meta mb-3">SSID: <?= esc($device['ssid'] ?? '-') ?></div>

                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted" data-role="last-seen">Last seen: <?= esc($device['last_seen'] ?? '-') ?></small>
                                    <div style="display:flex; gap:6px;">
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-edit-device"
                                            title="Edit Metadata"
                                            data-device='<?= esc(json_encode($device), 'attr') ?>'>
                                            <i class="fas fa-pen fa-xs"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary js-focus-device" data-device-id="<?= esc($device['id']) ?>">
                                            Monitor
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="alert alert-warning mb-0">
                        Belum ada device ESP32 yang terdaftar pada scope user ini.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Legenda warna -->
    <div class="col-12 mb-2">
        <div class="card card-outline card-secondary mb-0">
            <div class="card-body py-2 d-flex flex-wrap" style="gap: 6px 20px; font-size:13px">
                <span><span style="color:#3fb950; font-size:16px">■</span> Scan OK</span>
                <span><span style="color:#f85149; font-size:16px">■</span> Error / Gagal</span>
                <span><span style="color:#58a6ff; font-size:16px">■</span> Tersortir</span>
                <span><span style="color:#d29922; font-size:16px">■</span> Proses</span>
                <span><span style="color:#bc8cff; font-size:16px">■</span> Auto-Bind</span>
                <span><span style="color:#79c0ff; font-size:16px">■</span> WiFi</span>
                <span><span style="color:#ffa657; font-size:16px">■</span> System / Boot</span>
                <span><span style="color:#8b949e; font-size:16px">■</span> Cache</span>
            </div>
        </div>
    </div>

    <!-- Edit metadata modal -->
    <div class="modal fade" id="deviceModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form id="device-meta-form" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Metadata Device</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="device_id" id="dm_device_id">
                    <div class="form-group">
                        <label for="dm_display_name">Nama Conveyor</label>
                        <input type="text" class="form-control" id="dm_display_name" name="display_name" required>
                    </div>
                    <div class="form-group">
                        <label for="dm_location">Lokasi</label>
                        <input type="text" class="form-control" id="dm_location" name="location" placeholder="Contoh: Gate A, Jalur Barat">
                    </div>
                    <div class="form-group">
                        <label for="dm_notes">Catatan</label>
                        <textarea class="form-control" id="dm_notes" name="notes" rows="3"></textarea>
                    </div>
                    <div class="form-group mb-0">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="dm_is_active" name="is_active" value="1">
                            <label class="custom-control-label" for="dm_is_active">Device aktif</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Terminal card -->
    <div class="col-12">
        <div class="card mb-0" style="border: 1px solid #30363d; border-radius: 6px; overflow: hidden;">
            <div class="term-header">
                <span class="dot dot-red"></span>
                <span class="dot dot-yellow"></span>
                <span class="dot dot-green"></span>
                <span class="ml-2" style="color:#8b949e; font-family:monospace; font-size:13px">
                    Device Monitor —
                    <strong id="monitor-title" style="color:#c9d1d9">memuat...</strong>
                </span>
                <span class="ml-auto d-flex align-items-center gap-8">
                    <span id="poll-badge" class="badge badge-secondary">● Connecting</span>
                    <button id="btn-pause" class="btn btn-sm btn-outline-secondary py-0 px-2">Pause</button>
                    <button id="btn-clear" class="btn btn-sm btn-outline-danger py-0 px-2">Clear</button>
                </span>
            </div>

            <div id="terminal">
                <p class="log-line" id="placeholder" style="color:#484f58"># Menghubungkan ke Firebase... Menunggu log dari ESP32...</p>
            </div>

            <div class="term-footer">
                <span id="log-count">0</span> baris &nbsp;|&nbsp;
                Refresh tiap <strong>2</strong> detik &nbsp;|&nbsp;
                Scroll ke bawah untuk log terbaru &nbsp;|&nbsp;
                Update: <span id="last-update">-</span>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    const LOGS_URL   = '<?= base_url("device_monitor/logs") ?>';
    const STATUS_URL  = '<?= base_url("device_monitor/status") ?>';
    const SAVE_URL    = '<?= base_url("devices/save") ?>';
    const $modal      = $('#deviceModal');
    const metaForm    = document.getElementById('device-meta-form');
    const INTERVAL  = 2000;
    const STATUS_INTERVAL = 5000;
    const MAX_LINES = 500;
    const DEVICE_FILTER = document.getElementById('device-filter');
    const DEVICE_CARDS = Array.from(document.querySelectorAll('[data-device-id].device-card'));

    let lastWaktu  = '';
    let lastKey    = '';
    const seenIds  = new Set();
    let paused     = false;
    let logCount   = 0;
    let firstLoad  = true;
    let timer      = null;
    let statusTimer = null;

    // ── Color mapping ─────────────────────────────────────────────
    function getColor(log) {
        const aksi   = (log.aksi   || '').toLowerCase();
        const result = (log.result || '').toLowerCase();
        const status = (log.status || '').toLowerCase();

        if (aksi === 'log') {
            const level = (log.level || 'I').toUpperCase();
            if (level === 'E') return '#f85149';
            if (level === 'W') return '#d29922';

            const tag = (log.tag || '').toLowerCase();
            if (tag.includes('barcode')) return '#3fb950';
            if (tag.includes('rfid'))    return '#3fb950';
            if (tag.includes('control')) return '#58a6ff';
            if (tag.includes('wifi'))    return '#79c0ff';
            if (tag.includes('system') || tag.includes('boot')) return '#ffa657';
            if (tag.includes('cache'))   return '#8b949e';
            return '#c9d1d9';
        }

        if (aksi === 'scan') {
            return result === 'found' ? '#3fb950' : '#f85149';
        }
        if (aksi === 'update_status') {
            if (status === 'tersortir') return '#58a6ff';
            if (status === 'gagal')     return '#f85149';
            if (status === 'proses')    return '#d29922';
            return '#8b949e';
        }
        if (aksi === 'auto_bind') return result === 'success' ? '#bc8cff' : '#f85149';
        if (aksi === 'wifi')      return '#79c0ff';
        if (aksi === 'system')    return '#ffa657';
        if (aksi === 'cache')     return '#8b949e';
        return '#c9d1d9';
    }

    // ── Format a single log line ───────────────────────────────────
    function formatLine(log) {
        const waktu    = (log.waktu       || '-');
        const deviceLabel = `${log.display_name || log.esp32_id || '-'} / ${log.dcName || '-'}`;
        const device   = deviceLabel.padEnd(34);

        const aksiRaw = (log.aksi || '-').toLowerCase();
        if (aksiRaw === 'log') {
            const tag = (log.tag || 'LOG').toUpperCase();
            const level = (log.level || 'I').toUpperCase();
            const aksi = (`${tag}:${level}`).padEnd(13);
            const line = (log.line || log.result || '-');
            return `[${waktu}]  [${aksi}]  [${device}]  -                    →  -            |  ${line}`;
        }

        const aksi     = (log.aksi        || '-').toUpperCase().padEnd(13);
        const paketId  = (log.paket_id    || '-').padEnd(20);
        const status   = (log.status      || '-').padEnd(12);
        const result   = (log.result      || '-');
        const method   = log.scan_method ? ` (${log.scan_method})` : '';
        return `[${waktu}]  [${aksi}]  [${device}]  ${paketId}  →  ${status}  |  ${result}${method}`;
    }

    function resetTerminal(message) {
        const term = document.getElementById('terminal');
        term.innerHTML = '';
        logCount = 0;
        lastWaktu = '';
        lastKey = '';
        seenIds.clear();
        firstLoad = true;
        document.getElementById('log-count').textContent = '0';
        const ph = document.createElement('p');
        ph.id = 'placeholder';
        ph.className = 'log-line';
        ph.style.color = '#484f58';
        ph.textContent = message;
        term.appendChild(ph);
    }

    function syncActiveCard() {
        const selectedDevice = DEVICE_FILTER ? DEVICE_FILTER.value : 'all';
        DEVICE_CARDS.forEach(function (card) {
            card.classList.toggle('active', selectedDevice !== 'all' && card.dataset.deviceId === selectedDevice);
        });
    }

    function applyDeviceStatus(devices) {
        if (devices.length !== DEVICE_CARDS.length) {
            window.location.reload();
            return;
        }

        devices.forEach(function (device) {
            const card = document.querySelector(`.device-card[data-device-id="${CSS.escape(device.id)}"]`);
            if (!card) {
                window.location.reload();
                return;
            }

            card.classList.toggle('offline', !device.is_online);
            const chip = card.querySelector('.device-chip');
            if (chip) {
                chip.className = 'device-chip ' + (device.is_online ? 'online' : 'offline');
                chip.innerHTML = '<span>●</span><span>' + (device.is_online ? 'Online' : 'Offline') + '</span>';
            }

            const titleEl = card.querySelector('[data-role="display-name"]');
            if (titleEl) titleEl.textContent = device.display_name || device.id;

            const idEl = card.querySelector('[data-role="device-id"]');
            if (idEl) idEl.textContent = device.id;

            const locationEl = card.querySelector('[data-role="location"]');
            if (locationEl) locationEl.textContent = 'Lokasi: ' + (device.location || '-');

            const seenEl = card.querySelector('[data-role="last-seen"]');
            if (seenEl) seenEl.textContent = 'Last seen: ' + (device.last_seen || '-');

            // Keep edit button data fresh
            const editBtn = card.querySelector('.js-edit-device');
            if (editBtn) editBtn.dataset.device = JSON.stringify(device);
        });
    }

    // ── Edit metadata modal ────────────────────────────────────────
    function bindEditButtons() {
        document.querySelectorAll('.js-edit-device').forEach(function (button) {
            button.onclick = function () {
                const device = JSON.parse(this.dataset.device || '{}');
                document.getElementById('dm_device_id').value = device.id || '';
                document.getElementById('dm_display_name').value = device.display_name || device.id || '';
                document.getElementById('dm_location').value = (device.location && device.location !== '-') ? device.location : '';
                document.getElementById('dm_notes').value = device.notes || '';
                document.getElementById('dm_is_active').checked = device.is_active !== false;
                $modal.modal('show');
            };
        });
    }

    if (metaForm) {
        metaForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const formData = new FormData(metaForm);
            if (!document.getElementById('dm_is_active').checked) {
                formData.set('is_active', '0');
            }
            fetch(SAVE_URL, { method: 'POST', body: formData })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) throw new Error(data.message || 'Gagal menyimpan.');
                    $modal.modal('hide');
                    pollDeviceStatus();
                    Swal.fire({ icon: 'success', title: 'Tersimpan', text: data.message, timer: 1800, showConfirmButton: false });
                })
                .catch(function (err) {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: err.message || 'Terjadi kesalahan.' });
                });
        });
    }

    function pollDeviceStatus() {
        fetch(STATUS_URL)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (Array.isArray(data.devices)) {
                    applyDeviceStatus(data.devices);
                }
            })
            .catch(function () {
                // Ignore transient status polling errors
            });
    }

    function selectDevice(deviceId) {
        if (!DEVICE_FILTER) {
            return;
        }
        DEVICE_FILTER.value = deviceId || 'all';
        syncActiveCard();
        resetTerminal('# Scope monitor berubah. Mengambil log sesuai device yang dipilih...');
        poll();
    }

    // ── Append new log entries to terminal ─────────────────────────
    function appendLogs(logs) {
        const term = document.getElementById('terminal');
        const atBottom = term.scrollTop + term.clientHeight >= term.scrollHeight - 60;
        const selectedDevice = DEVICE_FILTER ? DEVICE_FILTER.value : 'all';

        // Remove placeholder on first real data
        if (firstLoad && logs.length > 0) {
            const ph = document.getElementById('placeholder');
            if (ph) ph.remove();
            firstLoad = false;
        }

        logs.forEach(function (log) {
            const key = log._key || '';
            const id = `${log.esp32_id || 'unknown'}:${key}`;
            if (key && seenIds.has(id)) {
                return;
            }
            if (key) {
                seenIds.add(id);
            }

            const p = document.createElement('p');
            p.className = 'log-line';
            p.style.color = getColor(log);
            p.textContent = formatLine(log);
            term.appendChild(p);
            logCount++;

            const w = (log.waktu || '');
            if (!lastWaktu || w > lastWaktu) {
                lastWaktu = w;
                lastKey = key;
            } else if (selectedDevice !== 'all' && w && w === lastWaktu && key) {
                const kInt = parseInt(key, 10);
                const lastInt = parseInt(lastKey || '0', 10);
                if (!lastKey || (Number.isFinite(kInt) && kInt > lastInt)) {
                    lastKey = key;
                }
            }
        });

        // Prune oldest lines beyond MAX_LINES
        const lines = term.querySelectorAll('.log-line');
        if (lines.length > MAX_LINES) {
            for (let i = 0; i < lines.length - MAX_LINES; i++) {
                lines[i].remove();
            }
        }

        document.getElementById('log-count').textContent = logCount;

        if (!paused && atBottom) {
            term.scrollTop = term.scrollHeight;
        }
    }

    // ── Poll Firebase logs via PHP endpoint ────────────────────────
    function poll() {
        if (paused) return;

        let url = LOGS_URL;
        const params = new URLSearchParams();
        const selectedDevice = DEVICE_FILTER ? DEVICE_FILTER.value : 'all';
        if (lastWaktu) {
            params.set('after', lastWaktu);
        }
        if (selectedDevice && selectedDevice !== 'all') {
            params.set('device', selectedDevice);
            if (lastKey) {
                params.set('after_key', lastKey);
            }
        }
        const queryString = params.toString();
        if (queryString) {
            url += '?' + queryString;
        }

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setBadge('live');
                document.getElementById('last-update').textContent =
                    new Date().toLocaleTimeString('id-ID');

                if (data.scope_label) {
                    document.getElementById('monitor-title').textContent = data.scope_label;
                }

                if (data.logs && data.logs.length > 0) {
                    appendLogs(data.logs);
                }
            })
            .catch(function () {
                setBadge('error');
            });
    }

    // ── Badge helper ───────────────────────────────────────────────
    function setBadge(state) {
        const el = document.getElementById('poll-badge');
        if (state === 'live')  { el.className = 'badge badge-success'; el.textContent = '● Live';   }
        if (state === 'pause') { el.className = 'badge badge-warning'; el.textContent = '● Paused'; }
        if (state === 'error') { el.className = 'badge badge-danger';  el.textContent = '● Error';  }
    }

    // ── Pause / Resume ─────────────────────────────────────────────
    document.getElementById('btn-pause').addEventListener('click', function () {
        paused = !paused;
        this.textContent = paused ? 'Resume' : 'Pause';
        this.className   = paused
            ? 'btn btn-sm btn-warning py-0 px-2'
            : 'btn btn-sm btn-outline-secondary py-0 px-2';
        setBadge(paused ? 'pause' : 'live');

        if (!paused) poll(); // immediate refresh on resume
    });

    // ── Clear ──────────────────────────────────────────────────────
    document.getElementById('btn-clear').addEventListener('click', function () {
        resetTerminal('# Log dibersihkan. Menunggu data baru...');
    });

    if (DEVICE_FILTER) {
        DEVICE_FILTER.addEventListener('change', function () {
            syncActiveCard();
            resetTerminal('# Scope monitor berubah. Mengambil log sesuai device yang dipilih...');
            poll();
        });
    }

    document.querySelectorAll('.js-focus-device').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            selectDevice(this.dataset.deviceId);
        });
    });

    DEVICE_CARDS.forEach(function (card) {
        card.addEventListener('click', function () {
            selectDevice(this.dataset.deviceId);
        });
    });

    // ── Start ──────────────────────────────────────────────────────
    bindEditButtons();
    syncActiveCard();
    poll();
    timer = setInterval(poll, INTERVAL);
    statusTimer = setInterval(pollDeviceStatus, STATUS_INTERVAL);
}());
</script>
<?= $this->endSection() ?>
