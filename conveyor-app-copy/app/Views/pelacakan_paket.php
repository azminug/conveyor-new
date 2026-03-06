<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container-fluid">
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Pelacakan Paket: <?= esc($paket['id']) ?></h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <b>Pengirim:</b> <?= esc($paket['pengirim'] ?? '-') ?><br>
                    <b>Penerima:</b> <?= esc($paket['penerima'] ?? '-') ?><br>
                    <b>Kode Pos:</b> <?= esc($paket['kode_pos'] ?? '-') ?><br>
                    <b>Jalur:</b> <?= esc($paket['jalur'] ?? '-') ?><br>
                </div>
                <div class="col-md-6">
                    <?php
                    $currentStatus = $paket['status'] ?? '-';
                    $statusBadge = match($currentStatus) {
                        'Datang' => 'primary',
                        'Tersortir' => 'success',
                        'Proses' => 'warning',
                        'Gagal' => 'danger',
                        default => 'secondary'
                    };
                    $scanMethod = $paket['scan_method'] ?? $paket['scanType'] ?? '-';
                    ?>
                    <b>Status Saat Ini:</b> <span class="badge badge-<?= $statusBadge ?>"><?= esc($currentStatus) ?></span><br>
                    <b>Kerusakan Saat Ini:</b> <?= esc($paket['damage'] ?? '-') ?><br>
                    <b>Disortir oleh:</b> <?= esc($paket['dcName'] ?? '-') ?><br>
                    <b>Metode Scan:</b>
                    <?php if ($scanMethod === 'rfid'): ?>
                        <span class="badge badge-primary"><i class="fas fa-wifi"></i> RFID</span>
                    <?php elseif ($scanMethod === 'barcode'): ?>
                        <span class="badge badge-secondary"><i class="fas fa-barcode"></i> Barcode</span>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?><br>
                    <b>Waktu Sortir Terakhir:</b> <?= esc($paket['waktu_sortir'] ?? '-') ?><br>
                </div>
            </div>
            <hr>
            <h6>Riwayat Sortir/Pelacakan</h6>
            <?php if (!empty($sortirLog)): ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Waktu</th>
                            <th>DC</th>
                            <th>Status</th>
                            <th>Kerusakan</th>
                            <th>Jalur</th>
                            <th>Metode Scan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sortirLog as $i => $log): ?>
                        <?php $logScanMethod = $log['scan_method'] ?? $log['scanType'] ?? '-'; ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= esc($log['waktu'] ?? '-') ?></td>
                            <td><?= esc($log['dcName'] ?? '-') ?></td>
                            <td>
                                <?php
                                $logStatus = $log['status'] ?? '-';
                                $logBadge = match($logStatus) {
                                    'Datang' => 'primary',
                                    'Tersortir' => 'success',
                                    'Proses' => 'warning',
                                    'Gagal' => 'danger',
                                    default => 'secondary'
                                };
                                ?>
                                <span class="badge badge-<?= $logBadge ?>"><?= esc($logStatus) ?></span>
                            </td>
                            <td><?= esc($log['damage'] ?? '-') ?></td>
                            <td><?= esc($log['jalur'] ?? '-') ?></td>
                            <td>
                                <?php if ($logScanMethod === 'rfid'): ?>
                                    <span class="badge badge-primary"><i class="fas fa-wifi"></i> RFID</span>
                                <?php elseif ($logScanMethod === 'barcode'): ?>
                                    <span class="badge badge-secondary"><i class="fas fa-barcode"></i></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="text-muted">Belum ada riwayat sortir.</p>
            <?php endif; ?>
            <a href="<?= base_url('daftar_paket') ?>" class="btn btn-secondary mt-3">Kembali ke Daftar Paket</a>
        </div>
    </div>
</div>
<?= $this->endSection() ?> 