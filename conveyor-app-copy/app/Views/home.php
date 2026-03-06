<!-- ci4_app/app/Views/home.php -->
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<!-- Info boxes -->
<div class="row">
    <div class="col-12 col-sm-6 col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-info elevation-1"><i class="fas fa-boxes"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Paket</span>
                <span class="info-box-number"><?= $stats['total'] ?? 0 ?></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-primary elevation-1"><i class="fas fa-inbox"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Datang</span>
                <span class="info-box-number"><?= $stats['datang'] ?? 0 ?></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-success elevation-1"><i class="fas fa-check-circle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Tersortir</span>
                <span class="info-box-number"><?= $stats['tersortir'] ?? 0 ?></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-spinner"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Proses</span>
                <span class="info-box-number"><?= $stats['proses'] ?? 0 ?></span>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-12 col-sm-6 col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-times-circle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Gagal</span>
                <span class="info-box-number"><?= $stats['gagal'] ?? 0 ?></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-secondary elevation-1"><i class="fas fa-exclamation-triangle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Rusak</span>
                <span class="info-box-number"><?= $stats['rusak'] ?? 0 ?></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-purple elevation-1"><i class="fas fa-wifi"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Via RFID</span>
                <span class="info-box-number"><?= $stats['rfid'] ?? 0 ?></span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Chart Area -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header border-0">
                <h3 class="card-title">Status Paket per DC</h3>
            </div>
            <div class="card-body">
                <canvas id="dcChart" height="200"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Pie Chart -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header border-0">
                <h3 class="card-title">Distribusi Status</h3>
            </div>
            <div class="card-body">
                <canvas id="statusChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Activity -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header border-0">
                <h3 class="card-title">Aktivitas Terbaru</h3>
                <div class="card-tools">
                    <a href="<?= base_url('daftar_paket_sort') ?>" class="btn btn-tool btn-sm">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>
            <div class="card-body table-responsive p-0" style="height: 300px;">
                <table class="table table-head-fixed text-nowrap">
                    <thead>
                        <tr>
                            <th>ID Paket</th>
                            <th>DC</th>
                            <th>Status</th>
                            <th>Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentPakets)): ?>
                            <?php foreach (array_slice($recentPakets, 0, 8) as $paket): ?>
                            <tr>
                                <td><?= esc($paket['id'] ?? '-') ?></td>
                                <td><?= esc($paket['dcName'] ?? '-') ?></td>
                                <td>
                                    <?php 
                                    $status = $paket['status'] ?? '-';
                                    $badge = match($status) {
                                        'Datang' => 'primary',
                                        'Tersortir' => 'success',
                                        'Proses' => 'warning',
                                        'Gagal' => 'danger',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge badge-<?= $badge ?>"><?= esc($status) ?></span>
                                </td>
                                <td><small><?= esc($paket['waktu_sortir'] ?? $paket['updated_at'] ?? '-') ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center text-muted">Tidak ada data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DC Summary -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header border-0">
                <h3 class="card-title">Summary per DC</h3>
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>DC</th>
                            <th class="text-center">Datang</th>
                            <th class="text-center">Tersortir</th>
                            <th class="text-center">Proses</th>
                            <th class="text-center">Gagal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['DC-A', 'DC-B', 'DC-C'] as $dc): 
                            $dcData = $dcStats[$dc] ?? ['datang' => 0, 'tersortir' => 0, 'proses' => 0, 'gagal' => 0];
                        ?>
                        <tr>
                            <td><strong><?= $dc ?></strong></td>
                            <td class="text-center"><span class="badge badge-primary"><?= $dcData['datang'] ?? 0 ?></span></td>
                            <td class="text-center"><span class="badge badge-success"><?= $dcData['tersortir'] ?? 0 ?></span></td>
                            <td class="text-center"><span class="badge badge-warning"><?= $dcData['proses'] ?? 0 ?></span></td>
                            <td class="text-center"><span class="badge badge-danger"><?= $dcData['gagal'] ?? 0 ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Bar Chart
var dcCtx = document.getElementById('dcChart').getContext('2d');
new Chart(dcCtx, {
    type: 'bar',
    data: {
        labels: ['DC-A', 'DC-B', 'DC-C'],
        datasets: [{
            label: 'Datang',
            data: [<?= $dcStats['DC-A']['datang'] ?? 0 ?>, <?= $dcStats['DC-B']['datang'] ?? 0 ?>, <?= $dcStats['DC-C']['datang'] ?? 0 ?>],
            backgroundColor: '#007bff'
        }, {
            label: 'Tersortir',
            data: [<?= $dcStats['DC-A']['tersortir'] ?? 0 ?>, <?= $dcStats['DC-B']['tersortir'] ?? 0 ?>, <?= $dcStats['DC-C']['tersortir'] ?? 0 ?>],
            backgroundColor: '#28a745'
        }, {
            label: 'Proses',
            data: [<?= $dcStats['DC-A']['proses'] ?? 0 ?>, <?= $dcStats['DC-B']['proses'] ?? 0 ?>, <?= $dcStats['DC-C']['proses'] ?? 0 ?>],
            backgroundColor: '#ffc107'
        }, {
            label: 'Gagal',
            data: [<?= $dcStats['DC-A']['gagal'] ?? 0 ?>, <?= $dcStats['DC-B']['gagal'] ?? 0 ?>, <?= $dcStats['DC-C']['gagal'] ?? 0 ?>],
            backgroundColor: '#dc3545'
        }]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true } }
    }
});

// Pie Chart
var statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Datang', 'Tersortir', 'Proses', 'Gagal'],
        datasets: [{
            data: [<?= $stats['datang'] ?? 0 ?>, <?= $stats['tersortir'] ?? 0 ?>, <?= $stats['proses'] ?? 0 ?>, <?= $stats['gagal'] ?? 0 ?>],
            backgroundColor: ['#007bff', '#28a745', '#ffc107', '#dc3545']
        }]
    },
    options: { responsive: true }
});

// Auto refresh setiap 30 detik
setInterval(function() {
    location.reload();
}, 30000);
</script>
<?= $this->endSection() ?>