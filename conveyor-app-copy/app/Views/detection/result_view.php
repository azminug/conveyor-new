<!-- ci4_app/app/Views/detection/result_view.php -->
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <div class="row justify-content-center mt-5">
        <div class="col-md-8">
            <div class="card result-card">
                <div class="card-header">
                    <h4 class="card-title">Hasil Deteksi Paket</h4>
                </div>
                <div class="card-body text-center">
                    <?php if (isset($result['success']) && $result['success']): ?>
                        <p class="text-success">Deteksi berhasil!</p>
                        <p>Level Kerusakan: <strong><?= esc($result['damage_level']) ?></strong></p>
                        <!-- Tampilkan gambar atau informasi lainnya jika diperlukan -->
                    <?php else: ?>
                        <p class="text-danger">Deteksi gagal. <?= esc($result['message'] ?? '') ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>