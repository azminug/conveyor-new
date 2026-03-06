<!-- ci4_app/app/Views/grafik/lihat_grafik.php -->
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="callout callout-info">
    <h5><i class="fas fa-info"></i> Info</h5>
    Grafik diambil dari data seluruh DC
</div>

<div class="row">
    <div class="col-lg-6">
        <!-- Grafik Status Paket -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-bar mr-1"></i> Status Paket per DC</h3>
            </div>
            <div class="card-body">
                <canvas id="statusPaketChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <!-- Grafik Kerusakan Paket -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-pie mr-1"></i> Kerusakan Paket per DC</h3>
            </div>
            <div class="card-body">
                <canvas id="kerusakanPaketChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <!-- DC dengan Kerusakan Terbanyak -->
        <div class="card card-danger card-outline">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-1"></i> DC Kerusakan Terbanyak: <?= esc($dcPalingBanyakKerusakan ?? '-') ?></h3>
            </div>
            <div class="card-body">
                <canvas id="dcBanyakKerusakanChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <!-- DC dengan Kerusakan Paling Sedikit -->
        <div class="card card-success card-outline">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-check-circle mr-1"></i> DC Kerusakan Paling Sedikit: <?= esc($dcPalingSedikitKerusakan ?? '-') ?></h3>
            </div>
            <div class="card-body">
                <canvas id="dcSedikitKerusakanChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Color palette
        const colors = {
            success: '#28a745',
            warning: '#ffc107',
            danger: '#dc3545',
            info: '#17a2b8',
            primary: '#007bff',
            orange: '#fd7e14'
        };
        
        // Grafik Status Paket dari Berbagai DC
        const statusPaketData = <?= json_encode($statusPaket) ?>;
        const statusLabels = Object.keys(statusPaketData);
        
        const statusChartData = {
            labels: statusLabels,
            datasets: [
                {
                    label: 'Tersortir',
                    data: statusLabels.map(dc => statusPaketData[dc]['Tersortir'] || 0),
                    backgroundColor: colors.success
                },
                {
                    label: 'Proses',
                    data: statusLabels.map(dc => statusPaketData[dc]['Proses'] || 0),
                    backgroundColor: colors.warning
                },
                {
                    label: 'Gagal',
                    data: statusLabels.map(dc => statusPaketData[dc]['Gagal'] || 0),
                    backgroundColor: colors.danger
                }
            ]
        };

        new Chart(document.getElementById('statusPaketChart'), {
            type: 'bar',
            data: statusChartData,
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });

        // Grafik Kerusakan Paket dari Berbagai DC
        const kerusakanPaketData = <?= json_encode($kerusakanPaket) ?>;
        const kerusakanLabels = Object.keys(kerusakanPaketData);
        
        const kerusakanChartData = {
            labels: kerusakanLabels,
            datasets: [
                {
                    label: 'Tidak Rusak',
                    data: kerusakanLabels.map(dc => kerusakanPaketData[dc]['Tidak Rusak'] || 0),
                    backgroundColor: colors.success
                },
                {
                    label: 'Rusak Ringan',
                    data: kerusakanLabels.map(dc => kerusakanPaketData[dc]['Rusak Ringan'] || 0),
                    backgroundColor: colors.warning
                },
                {
                    label: 'Rusak',
                    data: kerusakanLabels.map(dc => kerusakanPaketData[dc]['Rusak'] || 0),
                    backgroundColor: colors.orange
                },
                {
                    label: 'Sangat Rusak',
                    data: kerusakanLabels.map(dc => kerusakanPaketData[dc]['Sangat Rusak'] || 0),
                    backgroundColor: colors.danger
                }
            ]
        };

        new Chart(document.getElementById('kerusakanPaketChart'), {
            type: 'bar',
            data: kerusakanChartData,
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });

        // Grafik DC dengan Kerusakan Terbanyak
        <?php $dcBanyak = $dcPalingBanyakKerusakan ?? '-'; ?>
        new Chart(document.getElementById('dcBanyakKerusakanChart'), {
            type: 'doughnut',
            data: {
                labels: ['Rusak Ringan', 'Rusak', 'Sangat Rusak'],
                datasets: [{
                    data: [
                        <?= isset($kerusakanPaket[$dcBanyak]['Rusak Ringan']) ? $kerusakanPaket[$dcBanyak]['Rusak Ringan'] : 0 ?>,
                        <?= isset($kerusakanPaket[$dcBanyak]['Rusak']) ? $kerusakanPaket[$dcBanyak]['Rusak'] : 0 ?>,
                        <?= isset($kerusakanPaket[$dcBanyak]['Sangat Rusak']) ? $kerusakanPaket[$dcBanyak]['Sangat Rusak'] : 0 ?>
                    ],
                    backgroundColor: [colors.warning, colors.orange, colors.danger]
                }]
            },
            options: { responsive: true }
        });

        // Grafik DC dengan Kerusakan Paling Sedikit
        <?php $dcSedikit = $dcPalingSedikitKerusakan ?? '-'; ?>
        new Chart(document.getElementById('dcSedikitKerusakanChart'), {
            type: 'doughnut',
            data: {
                labels: ['Rusak Ringan', 'Rusak', 'Sangat Rusak'],
                datasets: [{
                    data: [
                        <?= isset($kerusakanPaket[$dcSedikit]['Rusak Ringan']) ? $kerusakanPaket[$dcSedikit]['Rusak Ringan'] : 0 ?>,
                        <?= isset($kerusakanPaket[$dcSedikit]['Rusak']) ? $kerusakanPaket[$dcSedikit]['Rusak'] : 0 ?>,
                        <?= isset($kerusakanPaket[$dcSedikit]['Sangat Rusak']) ? $kerusakanPaket[$dcSedikit]['Sangat Rusak'] : 0 ?>
                    ],
                    backgroundColor: [colors.warning, colors.orange, colors.danger]
                }]
            },
            options: { responsive: true }
        });
    });
</script>
<?= $this->endSection() ?>