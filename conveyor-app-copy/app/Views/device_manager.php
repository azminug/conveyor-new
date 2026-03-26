<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="row mb-3">
    <div class="col-12">
        <div class="card card-outline card-primary mb-0">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center" style="gap: 12px;">
                <div>
                    <h3 class="card-title mb-1">Metadata Device Conveyor</h3>
                    <div class="text-muted">
                        <?= $isAdmin ? 'Admin dapat mengelola semua device lintas DC.' : 'Anda hanya dapat mengelola device pada DC ' . esc($userDC) . '.' ?>
                    </div>
                </div>
                <div>
                    <span class="badge badge-light">Auto-refresh status 5s</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row" id="device-manager-grid">
    <?php if (!empty($devices)): ?>
        <?php foreach ($devices as $device): ?>
            <div class="col-12 col-md-6 col-xl-4 mb-3">
                <div class="card h-100 border <?= ($device['is_online'] ?? false) ? 'border-success' : 'border-secondary' ?>" data-device-id="<?= esc($device['id']) ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h5 class="mb-1" data-role="display-name"><?= esc($device['display_name'] ?? $device['id']) ?></h5>
                                <div class="text-muted small" data-role="device-id"><?= esc($device['id']) ?></div>
                            </div>
                            <span class="badge <?= ($device['is_online'] ?? false) ? 'badge-success' : 'badge-secondary' ?>" data-role="online-badge">
                                <?= ($device['is_online'] ?? false) ? 'Online' : 'Offline' ?>
                            </span>
                        </div>
                        <div class="small mb-1">DC: <strong><?= esc($device['dcName'] ?? '-') ?></strong></div>
                        <div class="small mb-1" data-role="location">Lokasi: <?= esc($device['location'] ?? '-') ?></div>
                        <div class="small mb-1">IP: <?= esc($device['ip'] ?? '-') ?></div>
                        <div class="small mb-1">SSID: <?= esc($device['ssid'] ?? '-') ?></div>
                        <div class="small mb-2" data-role="active-status">Status device: <?= ($device['is_active'] ?? true) ? 'Aktif' : 'Nonaktif' ?></div>
                        <div class="small text-muted mb-3" data-role="last-seen">Last seen: <?= esc($device['last_seen'] ?? '-') ?></div>
                        <button
                            type="button"
                            class="btn btn-primary btn-sm js-edit-device"
                            data-device='<?= esc(json_encode($device), 'attr') ?>'>
                            Edit Metadata
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="alert alert-warning mb-0">Belum ada device dalam scope user ini.</div>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="deviceModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form id="device-form" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Metadata Device</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="device_id" id="device_id">
                <div class="form-group">
                    <label for="display_name">Nama Conveyor</label>
                    <input type="text" class="form-control" id="display_name" name="display_name" required>
                </div>
                <div class="form-group">
                    <label for="location">Lokasi</label>
                    <input type="text" class="form-control" id="location" name="location" placeholder="Contoh: Gate A, Jalur Barat">
                </div>
                <div class="form-group">
                    <label for="notes">Catatan</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                </div>
                <div class="form-group mb-0">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1">
                        <label class="custom-control-label" for="is_active">Device aktif</label>
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
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    const SAVE_URL = '<?= base_url('devices/save') ?>';
    const STATUS_URL = '<?= base_url('device_monitor/status') ?>';
    const modal = $('#deviceModal');
    const form = document.getElementById('device-form');

    function bindEditButtons() {
        document.querySelectorAll('.js-edit-device').forEach(function (button) {
            button.onclick = function () {
                const device = JSON.parse(this.dataset.device || '{}');
                document.getElementById('device_id').value = device.id || '';
                document.getElementById('display_name').value = device.display_name || device.id || '';
                document.getElementById('location').value = device.location === '-' ? '' : (device.location || '');
                document.getElementById('notes').value = device.notes || '';
                document.getElementById('is_active').checked = device.is_active !== false;
                modal.modal('show');
            };
        });
    }

    function applyStatus(devices) {
        const currentCards = document.querySelectorAll('[data-device-id]');
        if (devices.length !== currentCards.length) {
            window.location.reload();
            return;
        }

        devices.forEach(function (device) {
            const card = document.querySelector('[data-device-id="' + CSS.escape(device.id) + '"]');
            if (!card) {
                window.location.reload();
                return;
            }
            card.className = 'card h-100 border ' + (device.is_online ? 'border-success' : 'border-secondary');
            const onlineBadge = card.querySelector('[data-role="online-badge"]');
            const locationEl = card.querySelector('[data-role="location"]');
            const activeEl = card.querySelector('[data-role="active-status"]');
            const seenEl = card.querySelector('[data-role="last-seen"]');
            const displayEl = card.querySelector('[data-role="display-name"]');
            const button = card.querySelector('.js-edit-device');

            if (onlineBadge) {
                onlineBadge.className = 'badge ' + (device.is_online ? 'badge-success' : 'badge-secondary');
                onlineBadge.textContent = device.is_online ? 'Online' : 'Offline';
            }
            if (locationEl) locationEl.textContent = 'Lokasi: ' + (device.location || '-');
            if (activeEl) activeEl.textContent = 'Status device: ' + (device.is_active ? 'Aktif' : 'Nonaktif');
            if (seenEl) seenEl.textContent = 'Last seen: ' + (device.last_seen || '-');
            if (displayEl) displayEl.textContent = device.display_name || device.id;
            if (button) button.dataset.device = JSON.stringify(device);
        });
    }

    function pollStatus() {
        fetch(STATUS_URL)
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (Array.isArray(data.devices)) {
                    applyStatus(data.devices);
                }
            })
            .catch(function () {});
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const formData = new FormData(form);
        if (!document.getElementById('is_active').checked) {
            formData.set('is_active', '0');
        }
        fetch(SAVE_URL, {
            method: 'POST',
            body: formData,
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.message || 'Gagal menyimpan metadata device.');
                }
                modal.modal('hide');
                pollStatus();
                Swal.fire({ icon: 'success', title: 'Tersimpan', text: data.message, timer: 1800, showConfirmButton: false });
            })
            .catch(function (error) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: error.message || 'Terjadi kesalahan.' });
            });
    });

    bindEditButtons();
    setInterval(function () {
        if (!document.hidden) {
            pollStatus();
        }
    }, 5000);
}());
</script>
<?= $this->endSection() ?>
