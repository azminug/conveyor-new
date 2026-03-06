<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container-fluid d-flex justify-content-center align-items-center" style="min-height:60vh;">
    <div class="card shadow" style="max-width:400px;width:100%;">
        <div class="card-header bg-primary text-white text-center">
            <h5 class="mb-0">Pelacakan Paket</h5>
        </div>
        <div class="card-body">
            <form method="post" action="<?= base_url('pelacakan_paket') ?>">
                <div class="mb-3">
                    <label for="id" class="form-label">Masukkan ID Paket / No Resi</label>
                    <input type="text" class="form-control" id="id" name="id" placeholder="Contoh: PKT-001" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100">Lacak Paket</button>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?> 