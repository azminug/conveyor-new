<div class="card-body table-responsive p-0">
    <table class="table table-hover table-striped">
        <thead>
            <tr>
                <th style="width: 120px">ID Paket</th>
                <th>Alamat Tujuan</th>
                <th style="width: 80px">DC</th>
                <th style="width: 80px">Jalur</th>
                <th style="width: 80px">Scan</th>
                <th style="width: 100px">Status</th>
                <th style="width: 120px">Kerusakan</th>
                <th style="width: 140px">Waktu</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($paginatedData)): ?>
                <?php foreach ($paginatedData as $paket): ?>
                    <?php
                    $nodeUnik = $paket['key'] ?? 'UnknownKey';
                    $paketId  = $paket['id'] ?? '-';
                    $alamat   = $paket['alamat_penerima'] ?? '-';
                    $jalur    = $paket['jalur'] ?? '-';
                    $status   = $paket['status'] ?? '-';
                    $tanggal  = $paket['updated_at'] ?? $paket['waktu_sortir'] ?? '-';
                    $damage   = $paket['damage'] ?? 'Tidak Rusak';
                    $dcName   = $paket['dcName'] ?? 'Unknown DC';
                    $scanType = $paket['scan_method'] ?? $paket['scanType'] ?? '-';
                    ?>
                    <tr>
                        <td><code title="<?= esc($nodeUnik) ?>"><?= esc($paketId) ?></code></td>
                        <td><small><?= esc(strlen($alamat) > 50 ? substr($alamat, 0, 50) . '...' : $alamat) ?></small></td>
                        <td><span class="badge badge-info"><?= esc($dcName) ?></span></td>
                        <td><?= esc($jalur) ?></td>
                        <td>
                            <?php if ($scanType === 'rfid'): ?>
                                <span class="badge badge-primary" title="RFID"><i class="fas fa-wifi"></i> RFID</span>
                            <?php elseif ($scanType === 'barcode'): ?>
                                <span class="badge badge-secondary" title="Barcode"><i class="fas fa-barcode"></i></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($status === 'Tersortir'): ?>
                                <span class="badge badge-success"><?= esc($status) ?></span>
                            <?php elseif ($status === 'Datang'): ?>
                                <span class="badge badge-primary"><?= esc($status) ?></span>
                            <?php elseif ($status === 'Gagal'): ?>
                                <span class="badge badge-danger"><?= esc($status) ?></span>
                            <?php elseif ($status === 'Proses'): ?>
                                <span class="badge badge-warning"><?= esc($status) ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary"><?= esc($status) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($damage === 'Tidak Rusak'): ?>
                                <span class="text-success"><i class="fas fa-check-circle"></i> <?= esc($damage) ?></span>
                            <?php elseif ($damage === 'Rusak Ringan'): ?>
                                <span class="text-warning"><i class="fas fa-exclamation-circle"></i> <?= esc($damage) ?></span>
                            <?php elseif ($damage === 'Rusak'): ?>
                                <span class="text-orange"><i class="fas fa-exclamation-triangle"></i> <?= esc($damage) ?></span>
                            <?php else: ?>
                                <span class="text-danger font-weight-bold"><i class="fas fa-times-circle"></i> <?= esc($damage) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= esc($tanggal) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data paket.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
