<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="container-fluid">
    <!-- Baris Pertama: Live Video Stream -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header bg-success text-white d-flex align-items-center">
                    <i class="fas fa-video mr-2"></i>
                    <h3 class="card-title mb-0">Live Video Stream</h3>
                </div>
                <div class="card-body text-center">
                    <!-- Video Stream -->
                    <img
                        id="video-stream"
                        src=""
                        alt="Memulai deteksi..."
                        class="img-fluid rounded border"
                        style="max-height: 500px;" />
                </div>
                <div class="card-footer text-center">
                    <button id="restartBtn" class="btn btn-warning btn-sm mr-2">
                        <i class="fas fa-sync mr-1"></i> Restart Deteksi
                    </button>
                    <button id="stopBtn" class="btn btn-danger btn-sm">
                        <i class="fas fa-stop mr-1"></i> Berhenti
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Baris Kedua: 5 Data Terbaru -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header bg-info text-white d-flex align-items-center">
                    <i class="fas fa-list mr-2"></i>
                    <h3 class="card-title mb-0">Data Terbaru</h3>
                </div>
                <div class="card-body" id="recent-packages-container">
                    <p class="text-center text-muted">Memuat data...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const videoStream = document.getElementById('video-stream');
        const restartBtn = document.getElementById('restartBtn');
        const stopBtn = document.getElementById('stopBtn');
        const container = document.getElementById('recent-packages-container');
        const dcName = "<?= esc($dcName) ?>";

        // AUTO-START STREAMING ON PAGE LOAD
        function startStreaming() {
            // Add timestamp to prevent caching
            const timestamp = new Date().getTime();
            videoStream.src = `http://localhost:5000/video_feed?dcName=${encodeURIComponent(dcName)}&t=${timestamp}`;
            videoStream.onerror = () => {
                console.error('Error loading video stream');
                setTimeout(startStreaming, 2000); // Retry after 2 seconds
            };
        }

        // Start streaming immediately
        startStreaming();

        // Restart Detection
        restartBtn.addEventListener('click', function() {
            fetch(`http://localhost:5000/stop_video?dcName=${encodeURIComponent(dcName)}`)
                .then(() => {
                    return fetch(`http://localhost:5000/start_video?dcName=${encodeURIComponent(dcName)}`);
                })
                .then(() => {
                    startStreaming();
                    alert('Deteksi dimulai ulang');
                });
        });

        // Stop Detection
        stopBtn.addEventListener('click', function() {
            fetch(`http://localhost:5000/stop_video?dcName=${encodeURIComponent(dcName)}`)
                .then(() => {
                    videoStream.src = "";
                    alert('Deteksi dihentikan');
                });
        });

        // Load recent packages
        function loadRecentPackages() {
            fetch('<?= base_url('detection/get_recent_packages') ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.recentPackages && data.recentPackages.length > 0) {
                        container.innerHTML = '';
                        data.recentPackages.forEach(paket => {
                            const paketElement = `
                                <div class="card mb-2 border-left-info">
                                    <div class="card-body p-2">
                                        <h5 class="card-title mb-1 font-weight-bold">
                                            <i class="fas fa-barcode mr-1"></i>
                                            ID: <span class="text-primary">${paket.key}</span>
                                        </h5>
                                        <p class="card-text mb-1">
                                            <i class="fas fa-map-marker-alt mr-1"></i>
                                            <strong>Tujuan:</strong> ${paket.id}<br>
                                            <i class="far fa-clock mr-1"></i>
                                            <strong>Tanggal:</strong> ${paket.updated_at}<br>
                                            <i class="fas fa-route mr-1"></i>
                                            <strong>Jalur:</strong> ${paket.jalur}<br>
                                            <i class="fas fa-info-circle mr-1"></i>
                                            <strong>Status:</strong> ${paket.status}<br>
                                            <i class="fas fa-tools mr-1"></i>
                                            <strong>Damage:</strong> ${paket.damage}<br>
                                            <i class="fas fa-warehouse mr-1"></i>
                                            <strong>Disortir oleh:</strong>
                                            <span class="text-primary">${paket.dcName}</span>
                                        </p>
                                    </div>
                                </div>
                            `;
                            container.innerHTML += paketElement;
                        });
                    } else {
                        container.innerHTML = '<p class="text-center text-muted">Tidak ada data terbaru.</p>';
                    }
                })
                .catch(err => {
                    console.error('Error fetching recent packages:', err);
                    container.innerHTML = '<p class="text-center text-danger">Gagal memuat data</p>';
                });
        }

        // Load packages and refresh periodically
        setInterval(loadRecentPackages, 5000);
        loadRecentPackages();
    });
</script>
<?= $this->endSection() ?>