<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<!-- Filters -->
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title">Filter Data</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    <label>Filter Status</label>
                    <select name="filter" id="filter" class="form-control">
                        <option value="" <?= !$filter ? 'selected' : '' ?>>Semua</option>
                        <option value="Datang" <?= $filter == 'Datang' ? 'selected' : '' ?>>Datang</option>
                        <option value="Tersortir" <?= $filter == 'Tersortir' ? 'selected' : '' ?>>Tersortir</option>
                        <option value="Proses" <?= $filter == 'Proses' ? 'selected' : '' ?>>Proses</option>
                        <option value="Gagal" <?= $filter == 'Gagal' ? 'selected' : '' ?>>Gagal</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Filter Kerusakan</label>
                    <select name="damageFilter" id="damageFilter" class="form-control">
                        <option value="" <?= !$damageFilter ? 'selected' : '' ?>>Semua</option>
                        <option value="Tidak Rusak" <?= $damageFilter == 'Tidak Rusak' ? 'selected' : '' ?>>Tidak Rusak</option>
                        <option value="Rusak Ringan" <?= $damageFilter == 'Rusak Ringan' ? 'selected' : '' ?>>Rusak Ringan</option>
                        <option value="Rusak" <?= $damageFilter == 'Rusak' ? 'selected' : '' ?>>Rusak</option>
                        <option value="Sangat Rusak" <?= $damageFilter == 'Sangat Rusak' ? 'selected' : '' ?>>Sangat Rusak</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Filter DC</label>
                    <select name="dcFilter" id="dcFilter" class="form-control">
                        <option value="">Semua DC</option>
                        <option value="DC-A">DC-A</option>
                        <option value="DC-B">DC-B</option>
                        <option value="DC-C">DC-C</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Cari</label>
                    <form method="get" action="">
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" placeholder="Cari..." value="<?= esc($search ?? '') ?>">
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Summary Info Boxes -->
<div class="row">
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-primary">
            <span class="info-box-icon"><i class="fas fa-inbox"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Datang</span>
                <span class="info-box-number"><?= esc($statusCount['Datang'] ?? 0) ?></span>
            </div>
        </div>
    </div>
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-success">
            <span class="info-box-icon"><i class="fas fa-check"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Tersortir</span>
                <span class="info-box-number"><?= esc($statusCount['Tersortir'] ?? 0) ?></span>
            </div>
        </div>
    </div>
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-warning">
            <span class="info-box-icon"><i class="fas fa-spinner"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Proses</span>
                <span class="info-box-number"><?= esc($statusCount['Proses'] ?? 0) ?></span>
            </div>
        </div>
    </div>
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-danger">
            <span class="info-box-icon"><i class="fas fa-times"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Gagal</span>
                <span class="info-box-number"><?= esc($statusCount['Gagal'] ?? 0) ?></span>
            </div>
        </div>
    </div>
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-info">
            <span class="info-box-icon"><i class="fas fa-boxes"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total</span>
                <span class="info-box-number"><?= esc(($statusCount['Datang'] ?? 0) + ($statusCount['Tersortir'] ?? 0) + ($statusCount['Proses'] ?? 0) + ($statusCount['Gagal'] ?? 0)) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Daftar Paket Tersortir</h3>
    </div>
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
                            <td>
                                <code title="<?= esc($nodeUnik) ?>"><?= esc($paketId) ?></code>
                            </td>
                            <td>
                                <small><?= esc(strlen($alamat) > 50 ? substr($alamat, 0, 50) . '...' : $alamat) ?></small>
                            </td>
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
    <div class="card-footer clearfix">
        <div class="float-left">
            <small class="text-muted">Menampilkan <?= count($paginatedData ?? []) ?> dari <?= $totalData ?? 0 ?> data</small>
        </div>
        <ul class="pagination pagination-sm m-0 float-right">
            <?php if ($currentPage > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= $currentPage - 1 ?>&entries=<?= $entriesPerPage ?>&filter=<?= $filter ?>&damageFilter=<?= $damageFilter ?>&search=<?= esc($search) ?>">&laquo;</a>
                </li>
            <?php endif; ?>
            
            <?php 
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            ?>
            
            <?php if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=1&entries=<?= $entriesPerPage ?>&filter=<?= $filter ?>&damageFilter=<?= $damageFilter ?>&search=<?= esc($search) ?>">1</a></li>
                <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?= ($i == $currentPage) ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&entries=<?= $entriesPerPage ?>&filter=<?= $filter ?>&damageFilter=<?= $damageFilter ?>&search=<?= esc($search) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $totalPages ?>&entries=<?= $entriesPerPage ?>&filter=<?= $filter ?>&damageFilter=<?= $damageFilter ?>&search=<?= esc($search) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            
            <?php if ($currentPage < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= $currentPage + 1 ?>&entries=<?= $entriesPerPage ?>&filter=<?= $filter ?>&damageFilter=<?= $damageFilter ?>&search=<?= esc($search) ?>">&raquo;</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    // Filter change handlers
    document.getElementById('filter').addEventListener('change', function() {
        updateFilters();
    });

    document.getElementById('damageFilter').addEventListener('change', function() {
        updateFilters();
    });

    document.getElementById('dcFilter').addEventListener('change', function() {
        updateFilters();
    });

    function updateFilters() {
        const params = new URLSearchParams(window.location.search);
        params.set('filter', document.getElementById('filter').value);
        params.set('damageFilter', document.getElementById('damageFilter').value);
        params.set('dcFilter', document.getElementById('dcFilter').value);
        params.set('page', '1'); // Reset to page 1 on filter change
        window.location.search = params.toString();
    }
</script>
<?= $this->endSection() ?>