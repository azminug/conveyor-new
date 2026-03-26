<!-- ci4_app/app/Views/daftar_paket.php -->
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<!-- Filter Section -->
<div class="card card-outline card-primary collapsed-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-filter mr-1"></i> Filter Data</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                <i class="fas fa-plus"></i>
            </button>
        </div>
    </div>
    <div class="card-body">
        <form id="filterForm">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="filterJalur">Filter Jalur</label>
                        <select id="filterJalur" class="form-control">
                            <option value="">Semua Jalur</option>
                            <?php foreach (($jalurOptions ?? []) as $jalurName): ?>
                                <option value="<?= esc($jalurName) ?>"><?= esc($jalurName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="filterStatus">Filter Status</label>
                        <select id="filterStatus" class="form-control">
                            <option value="">Semua Status</option>
                            <option value="Datang">Datang</option>
                            <option value="Tersortir">Tersortir</option>
                            <option value="Proses">Proses</option>
                            <option value="Gagal">Gagal</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="filterDC">Filter DC</label>
                        <select id="filterDC" class="form-control">
                            <option value="">Semua DC</option>
                            <option value="DC-A">DC-A</option>
                            <option value="DC-B">DC-B</option>
                            <option value="DC-C">DC-C</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="filterID">Cari ID</label>
                        <input type="text" id="filterID" class="form-control" placeholder="Masukkan ID">
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Add Button -->
<div class="mb-3">
    <button class="btn btn-success" data-toggle="modal" data-target="#addPaketModal">
        <i class="fas fa-plus mr-1"></i> Tambah Paket
    </button>
    <span class="ml-2 text-muted">Total: <strong id="totalCount"><?= count($paketData ?? []) ?></strong> paket</span>
</div>

<!-- Data Cards Section -->
<div class="row" id="paketContainer">
    <?php if ($paketData): ?>
        <?php foreach ($paketData as $key => $paket): ?>
            <?php
            $paketId = $paket['id'] ?? '-';
            $status = $paket['status'] ?? '-';
            $damage = $paket['damage'] ?? 'Tidak Rusak';
            $scanType = $paket['scan_method'] ?? $paket['scanType'] ?? '-';
            // Support both rfid_epc (from ESP32) and rfid_tag (from web form)
            $rfidEpc = $paket['rfid_epc'] ?? $paket['rfid_tag'] ?? $paket['rfidTag'] ?? '';
            $qrCode = $paket['qr_code'] ?? $paket['qrCode'] ?? $paketId;
            $dcName = $paket['DC_name'] ?? $paket['dcName'] ?? '-';
            
            // Determine card color based on status
            $cardColor = match($status) {
                'Datang' => 'primary',
                'Tersortir' => 'success',
                'Proses' => 'warning',
                'Gagal' => 'danger',
                default => 'info'
            };
            ?>
            <div class="col-lg-4 col-md-6 mb-3 paket-item"
                data-key="<?= esc($key) ?>"
                data-jalur="<?= esc($paket['jalur'] ?? '') ?>"
                data-status="<?= esc($status) ?>"
                data-id="<?= esc($paketId) ?>"
                data-dc="<?= esc($dcName) ?>">
                <div class="card card-<?= $cardColor ?> card-outline">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-box mr-1"></i> <?= esc($paketId) ?>
                        </h3>
                        <div class="card-tools">
                            <?php if ($scanType === 'rfid'): ?>
                                <span class="badge badge-primary mr-1" title="RFID Scan"><i class="fas fa-wifi"></i></span>
                            <?php elseif ($scanType === 'barcode'): ?>
                                <span class="badge badge-secondary mr-1" title="Barcode Scan"><i class="fas fa-barcode"></i></span>
                            <?php endif; ?>
                            <button class="btn btn-tool editPaket"
                                data-toggle="modal"
                                data-target="#editPaketModal"
                                data-key="<?= esc($key) ?>"
                                data-id="<?= esc($paketId) ?>"
                                data-pengirim="<?= esc($paket['pengirim'] ?? '') ?>"
                                data-alamat_pengirim="<?= esc($paket['alamat_pengirim'] ?? '') ?>"
                                data-telepon_pengirim="<?= esc($paket['telepon_pengirim'] ?? '') ?>"
                                data-penerima="<?= esc($paket['penerima'] ?? '') ?>"
                                data-alamat_penerima="<?= esc($paket['alamat_penerima'] ?? '') ?>"
                                data-telepon_penerima="<?= esc($paket['telepon_penerima'] ?? '') ?>"
                                data-kode_pos="<?= esc($paket['kode_pos'] ?? '') ?>"
                                data-esp32_id="<?= esc($paket['esp32_id'] ?? '') ?>"
                                data-status="<?= esc($status) ?>"
                                data-damage="<?= esc($damage) ?>"
                                data-jalur="<?= esc($paket['jalur'] ?? '') ?>"
                                data-rfid_epc="<?= esc($rfidEpc) ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-tool deletePaket" data-key="<?= esc($key) ?>">
                                <i class="fas fa-trash text-danger"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body pt-2 pb-2">
                        <div class="row">
                            <!-- Left: Info -->
                            <div class="col-8">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td class="text-muted" style="width:80px"><small>Pengirim</small></td>
                                        <td><small><strong><?= esc($paket['pengirim'] ?? '-') ?></strong></small></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><small>Penerima</small></td>
                                        <td><small><strong><?= esc($paket['penerima'] ?? '-') ?></strong></small></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><small>Alamat</small></td>
                                        <td><small><?= esc(strlen($paket['alamat_penerima'] ?? '') > 30 ? substr($paket['alamat_penerima'], 0, 30) . '...' : ($paket['alamat_penerima'] ?? '-')) ?></small></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><small>DC/Jalur</small></td>
                                        <td>
                                            <span class="badge badge-info"><?= esc($dcName) ?></span>
                                            <small class="ml-1"><?= esc($paket['jalur'] ?? '-') ?></small>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <!-- Right: QR Code -->
                            <div class="col-4 text-center">
                                <div class="qr-container mb-1" style="background:#fff;padding:5px;border-radius:4px;display:inline-block">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=70x70&data=<?= urlencode($qrCode) ?>" 
                                         alt="QR" width="70" height="70" loading="lazy">
                                </div>
                                <?php if ($rfidEpc): ?>
                                    <div><small class="text-muted"><i class="fas fa-wifi"></i> <?= esc(substr($rfidEpc, -8)) ?></small></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer pt-2 pb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge badge-<?= $cardColor ?>"><?= esc($status) ?></span>
                                <?php if ($damage !== 'Tidak Rusak' && $damage !== '-'): ?>
                                    <span class="badge badge-danger ml-1"><?= esc($damage) ?></span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted"><?= esc($paket['updated_at'] ?? $paket['waktu_sortir'] ?? '-') ?></small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-1"></i> Tidak ada data paket.
            </div>
        </div>
    <?php endif; ?>
</div>

    <!-- Modal Tambah Paket -->
    <div class="modal fade" id="addPaketModal" tabindex="-1" aria-labelledby="addPaketModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle mr-1"></i> Tambah Paket Baru</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form id="createPaketForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3"><i class="fas fa-user mr-1"></i> Data Pengirim</h6>
                                <div class="form-group">
                                    <label for="pengirim">Nama Pengirim</label>
                                    <input type="text" id="pengirim" name="pengirim" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label for="alamat_pengirim">Alamat Pengirim</label>
                                    <textarea id="alamat_pengirim" name="alamat_pengirim" class="form-control" rows="2" required></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="telepon_pengirim">Telepon Pengirim</label>
                                    <input type="text" id="telepon_pengirim" name="telepon_pengirim" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3"><i class="fas fa-user-check mr-1"></i> Data Penerima</h6>
                                <div class="form-group">
                                    <label for="penerima">Nama Penerima</label>
                                    <input type="text" id="penerima" name="penerima" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label for="alamat_penerima">Alamat Penerima</label>
                                    <textarea id="alamat_penerima" name="alamat_penerima" class="form-control" rows="2" required></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="telepon_penerima">Telepon Penerima</label>
                                    <input type="text" id="telepon_penerima" name="telepon_penerima" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="kode_pos">Kode Pos</label>
                                    <input type="text" id="kode_pos" name="kode_pos" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="esp32_id">ESP32 ID</label>
                                    <input type="text" id="esp32_id" name="esp32_id" class="form-control" placeholder="Opsional">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="rfid_epc"><i class="fas fa-wifi mr-1"></i> RFID EPC</label>
                                    <input type="text" id="rfid_epc" name="rfid_epc" class="form-control" placeholder="Contoh: E28069950000500B91D28E93">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-plus mr-1"></i> Tambah Paket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Edit Paket -->
    <div class="modal fade" id="editPaketModal" tabindex="-1" aria-labelledby="editPaketModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Paket</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form id="editPaketForm">
                    <div class="modal-body">
                        <input type="hidden" id="editKey" name="key">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3"><i class="fas fa-user mr-1"></i> Data Pengirim</h6>
                                <div class="form-group">
                                    <label for="editPengirim">Nama Pengirim</label>
                                    <input type="text" id="editPengirim" name="pengirim" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label for="editAlamatPengirim">Alamat Pengirim</label>
                                    <textarea id="editAlamatPengirim" name="alamat_pengirim" class="form-control" rows="2" required></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="editTeleponPengirim">Telepon Pengirim</label>
                                    <input type="text" id="editTeleponPengirim" name="telepon_pengirim" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3"><i class="fas fa-user-check mr-1"></i> Data Penerima</h6>
                                <div class="form-group">
                                    <label for="editPenerima">Nama Penerima</label>
                                    <input type="text" id="editPenerima" name="penerima" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label for="editAlamatPenerima">Alamat Penerima</label>
                                    <textarea id="editAlamatPenerima" name="alamat_penerima" class="form-control" rows="2" required></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="editTeleponPenerima">Telepon Penerima</label>
                                    <input type="text" id="editTeleponPenerima" name="telepon_penerima" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3"><i class="fas fa-cog mr-1"></i> Data Sistem</h6>
                                <div class="form-group">
                                    <label for="editKodePos">Kode Pos</label>
                                    <input type="text" id="editKodePos" name="kode_pos" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label for="editEsp32Id">ESP32 ID</label>
                                    <input type="text" id="editEsp32Id" name="esp32_id" class="form-control" placeholder="Opsional">
                                </div>
                                <div class="form-group">
                                    <label for="editRfidEpc"><i class="fas fa-wifi mr-1"></i> RFID EPC</label>
                                    <input type="text" id="editRfidEpc" name="rfid_epc" class="form-control" placeholder="Contoh: E28069950000500B91D28E93">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3"><i class="fas fa-info-circle mr-1"></i> Status Paket</h6>
                                <div class="form-group">
                                    <label for="editStatus">Status</label>
                                    <select id="editStatus" name="status" class="form-control" required>
                                        <option value="Datang">Datang</option>
                                        <option value="Proses">Proses</option>
                                        <option value="Tersortir">Tersortir</option>
                                        <option value="Gagal">Gagal</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="editDamage">Status Kerusakan</label>
                                    <select id="editDamage" name="damage" class="form-control">
                                        <option value="">-</option>
                                        <option value="Tidak Rusak">Tidak Rusak</option>
                                        <option value="Rusak Ringan">Rusak Ringan</option>
                                        <option value="Rusak">Rusak</option>
                                        <option value="Sangat Rusak">Sangat Rusak</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="editJalur">Jalur</label>
                                    <select id="editJalur" name="jalur" class="form-control">
                                        <option value="">-</option>
                                        <?php foreach (($jalurOptions ?? []) as $jalurName): ?>
                                            <option value="<?= esc($jalurName) ?>"><?= esc($jalurName) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        // Filter functionality
        function filterPaket() {
            const jalur = $('#filterJalur').val().toLowerCase();
            const status = $('#filterStatus').val().toLowerCase();
            const dc = $('#filterDC').val().toLowerCase();
            const id = $('#filterID').val().toLowerCase();
            let visibleCount = 0;

            $('#paketContainer .paket-item').each(function() {
                const paketJalur = ($(this).data('jalur') || '').toString().toLowerCase();
                const paketStatus = ($(this).data('status') || '').toString().toLowerCase();
                const paketDC = ($(this).data('dc') || '').toString().toLowerCase();
                const paketID = ($(this).data('id') || '').toString().toLowerCase();

                const matchJalur = jalur === '' || paketJalur.includes(jalur);
                const matchStatus = status === '' || paketStatus.includes(status);
                const matchDC = dc === '' || paketDC.includes(dc);
                const matchID = id === '' || paketID.includes(id);

                if (matchJalur && matchStatus && matchDC && matchID) {
                    $(this).show();
                    visibleCount++;
                } else {
                    $(this).hide();
                }
            });

            // Update total count
            $('#totalCount').text(visibleCount);
        }

        $('#filterForm').on('input change', 'select, input', filterPaket);

        // Edit Paket functionality
        $('.editPaket').on('click', function() {
            const key = $(this).data('key');
            const id = $(this).data('id');
            $('#editKey').val(key);
            $('#editPengirim').val($(this).data('pengirim'));
            $('#editAlamatPengirim').val($(this).data('alamat_pengirim'));
            $('#editTeleponPengirim').val($(this).data('telepon_pengirim'));
            $('#editPenerima').val($(this).data('penerima'));
            $('#editAlamatPenerima').val($(this).data('alamat_penerima'));
            $('#editTeleponPenerima').val($(this).data('telepon_penerima'));
            $('#editKodePos').val($(this).data('kode_pos'));
            $('#editEsp32Id').val($(this).data('esp32_id'));
            $('#editRfidEpc').val($(this).data('rfid_epc'));
            $('#editStatus').val($(this).data('status'));
            $('#editDamage').val($(this).data('damage'));
            $('#editJalur').val($(this).data('jalur'));
            // Update modal title with package ID
            $('#editPaketModal .modal-title').html('<i class="fas fa-edit mr-1"></i> Edit Paket: ' + id);
        });

        $('#editPaketForm').on('submit', function(e) {
            e.preventDefault();

            const key = $('#editKey').val();
            const pengirim = $('#editPengirim').val();
            const alamat_pengirim = $('#editAlamatPengirim').val();
            const telepon_pengirim = $('#editTeleponPengirim').val();
            const penerima = $('#editPenerima').val();
            const alamat_penerima = $('#editAlamatPenerima').val();
            const telepon_penerima = $('#editTeleponPenerima').val();
            const kode_pos = $('#editKodePos').val();
            const esp32_id = $('#editEsp32Id').val();
            const rfid_epc = $('#editRfidEpc').val();
            const status = $('#editStatus').val();
            const damage = $('#editDamage').val();
            const jalur = $('#editJalur').val();

            $.ajax({
                url: '<?= base_url('update_paket') ?>',
                method: 'POST',
                data: {
                    key,
                    pengirim,
                    alamat_pengirim,
                    telepon_pengirim,
                    penerima,
                    alamat_penerima,
                    telepon_penerima,
                    kode_pos,
                    esp32_id,
                    rfid_epc,
                    status,
                    damage,
                    jalur
                },
                success: function(response) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: response.message
                    }).then(() => location.reload());
                },
                error: function(xhr) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Kesalahan',
                        text: xhr.responseJSON?.error || 'Gagal mengedit data.'
                    });
                }
            });
        });

        // Delete Paket functionality
        $('.deletePaket').on('click', function() {
            const key = $(this).data('key');

            Swal.fire({
                title: 'Apakah Anda yakin?',
                text: "Data tidak dapat dikembalikan!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, hapus!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '<?= base_url('delete_paket') ?>',
                        method: 'POST',
                        data: {
                            key
                        },
                        success: function(response) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil',
                                text: response.message
                            }).then(() => location.reload());
                        },
                        error: function(xhr) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Kesalahan',
                                text: xhr.responseJSON?.error || 'Gagal menghapus data.'
                            });
                        }
                    });
                }
            });
        });

        // Create Paket functionality
        $('#createPaketForm').on('submit', function(e) {
            e.preventDefault();

            const pengirim = $('#pengirim').val();
            const alamat_pengirim = $('#alamat_pengirim').val();
            const telepon_pengirim = $('#telepon_pengirim').val();
            const penerima = $('#penerima').val();
            const alamat_penerima = $('#alamat_penerima').val();
            const telepon_penerima = $('#telepon_penerima').val();
            const kode_pos = $('#kode_pos').val();
            const esp32_id = $('#esp32_id').val();
            const rfid_epc = $('#rfid_epc').val();

            $.ajax({
                url: '<?= base_url('create_paket') ?>',
                method: 'POST',
                data: {
                    pengirim,
                    alamat_pengirim,
                    telepon_pengirim,
                    penerima,
                    alamat_penerima,
                    telepon_penerima,
                    kode_pos,
                    esp32_id,
                    rfid_epc
                },
                success: function(response) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: response.message
                    }).then(() => location.reload());
                },
                error: function(xhr) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Kesalahan',
                        text: xhr.responseJSON?.error || 'Gagal menambahkan data.'
                    });
                }
            });
        });
    });
</script>
<?= $this->endSection() ?>