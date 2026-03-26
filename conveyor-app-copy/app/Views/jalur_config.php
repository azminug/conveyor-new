<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="row">
    <div class="col-12 mb-3">
        <div class="card card-outline card-primary mb-0">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center" style="gap: 12px;">
                <div>
                    <h3 class="card-title mb-1">Konfigurasi Jalur dan Kode Pos</h3>
                    <div class="text-muted">
                        Atur mapping kode pos ke jalur conveyor per DC. Admin dapat mengelola semua DC, user DC hanya DC miliknya.
                    </div>
                </div>
                <span class="badge badge-info"><?= ($isAdmin ?? false) ? 'Mode Admin' : 'Mode DC' ?></span>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5 mb-3">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Simpan Mapping</h3>
            </div>
            <form id="jalur-form" class="card-body">
                <div class="form-group">
                    <label for="dc_name">DC</label>
                    <?php if (($isAdmin ?? false) === true): ?>
                        <select id="dc_name" name="dcName" class="form-control">
                            <?php foreach (($dcOptions ?? []) as $dc): ?>
                                <option value="<?= esc($dc) ?>" <?= (($selectedDc ?? '') === $dc) ? 'selected' : '' ?>><?= esc($dc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" id="dc_name" class="form-control" value="<?= esc($selectedDc ?? '-') ?>" readonly>
                        <input type="hidden" name="dcName" value="<?= esc($selectedDc ?? '') ?>">
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="jalur">Jalur</label>
                    <select id="jalur" name="jalur" class="form-control" required>
                        <option value="">Pilih jalur</option>
                        <option value="Jalur 1">Jalur 1</option>
                        <option value="Jalur 2">Jalur 2</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="kodepos">Kode Pos</label>
                    <textarea id="kodepos" name="kodepos" class="form-control" rows="6" placeholder="Pisahkan dengan koma atau baris baru, contoh:
40111, 40112
40113" required></textarea>
                    <small class="text-muted">Kode pos duplikat akan otomatis dipindahkan ke jalur yang baru disimpan.</small>
                </div>
                <button type="submit" class="btn btn-primary">Simpan Konfigurasi</button>
            </form>
        </div>
    </div>

    <div class="col-12 col-lg-7 mb-3">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Mapping Aktif</h3>
            </div>
            <div class="card-body" id="jalur-map-container">
                <?php if (!empty($jalurMap)): ?>
                    <?php foreach ($jalurMap as $jalurName => $kodePosList): ?>
                        <div class="mb-3" data-jalur-block="<?= esc($jalurName) ?>">
                            <h6 class="mb-2"><?= esc($jalurName) ?></h6>
                            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                <?php foreach ($kodePosList as $kodePos): ?>
                                    <span class="badge badge-light border px-2 py-1 d-inline-flex align-items-center" style="gap:8px;">
                                        <span><?= esc($kodePos) ?></span>
                                        <button type="button" class="btn btn-xs btn-link text-danger p-0 js-remove-kodepos" data-jalur="<?= esc($jalurName) ?>" data-kodepos="<?= esc($kodePos) ?>" title="Hapus">x</button>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">Belum ada konfigurasi jalur.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    const SAVE_URL = '<?= base_url('jalur/save') ?>';
    const REMOVE_URL = '<?= base_url('jalur/remove-kodepos') ?>';
    const form = document.getElementById('jalur-form');
    const container = document.getElementById('jalur-map-container');
    const dcSelect = document.getElementById('dc_name');
    const isAdmin = <?= (($isAdmin ?? false) === true) ? 'true' : 'false' ?>;

    function escHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderMap(jalurMap) {
        const keys = Object.keys(jalurMap || {});
        if (keys.length === 0) {
            container.innerHTML = '<div class="alert alert-warning mb-0">Belum ada konfigurasi jalur.</div>';
            return;
        }

        const html = keys.map(function (jalurName) {
            const list = Array.isArray(jalurMap[jalurName]) ? jalurMap[jalurName] : [];
            const safeJalurName = escHtml(jalurName);
            const badges = list.map(function (kodePos) {
                const safeKodePos = escHtml(kodePos);
                return '<span class="badge badge-light border px-2 py-1 d-inline-flex align-items-center" style="gap:8px;">'
                    + '<span>' + safeKodePos + '</span>'
                    + '<button type="button" class="btn btn-xs btn-link text-danger p-0 js-remove-kodepos" data-jalur="' + safeJalurName + '" data-kodepos="' + safeKodePos + '" title="Hapus">x</button>'
                    + '</span>';
            }).join('');

            return '<div class="mb-3" data-jalur-block="' + safeJalurName + '">'
                + '<h6 class="mb-2">' + safeJalurName + '</h6>'
                + '<div style="display:flex; flex-wrap:wrap; gap:8px;">' + badges + '</div>'
                + '</div>';
        }).join('');

        container.innerHTML = html;
    }

    function bindRemoveButtons() {
        container.querySelectorAll('.js-remove-kodepos').forEach(function (button) {
            button.onclick = function () {
                const fd = new FormData();
                fd.set('jalur', this.dataset.jalur || '');
                fd.set('kodepos', this.dataset.kodepos || '');
                if (isAdmin && dcSelect) {
                    fd.set('dcName', dcSelect.value || '');
                }

                fetch(REMOVE_URL, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            throw new Error(data.message || 'Gagal menghapus kode pos.');
                        }
                        renderMap(data.jalurMap || {});
                        bindRemoveButtons();
                    })
                    .catch(function (err) {
                        Swal.fire({ icon: 'error', title: 'Gagal', text: err.message || 'Terjadi kesalahan.' });
                    });
            };
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const fd = new FormData(form);

        fetch(SAVE_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.message || 'Gagal menyimpan konfigurasi.');
                }
                renderMap(data.jalurMap || {});
                bindRemoveButtons();
                Swal.fire({ icon: 'success', title: 'Tersimpan', text: data.message, timer: 1600, showConfirmButton: false });
            })
            .catch(function (err) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: err.message || 'Terjadi kesalahan.' });
            });
    });

    bindRemoveButtons();

    if (isAdmin && dcSelect) {
        dcSelect.addEventListener('change', function () {
            const params = new URLSearchParams(window.location.search);
            params.set('dc', dcSelect.value || '');
            window.location.search = params.toString();
        });
    }
}());
</script>
<?= $this->endSection() ?>