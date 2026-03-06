<!-- ci4_app/app/Views/detection/upload_view.php -->
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <div class="row justify-content-center mt-5">
        <div class="col-md-6">
            <div class="card upload-card">
                <div class="card-body">
                    <h4 class="text-center mb-4">Unggah Gambar Paket</h4>
                    <form action="<?= base_url('paket/detect') ?>" method="POST" enctype="multipart/form-data">
                        <div class="form-group mb-3">
                            <label for="image">Pilih Gambar</label>
                            <input type="file" name="image" id="image" class="form-control" accept="image/*" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Deteksi</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>