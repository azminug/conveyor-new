<!-- ci4_app/app/Views/auth/login.php -->
<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center mt-5">
    <div class="col-md-6">
        <div class="card login-card">
            <div class="card-body">
                <h4 class="text-center mb-4">Login</h4>
                <form action="<?= base_url('authenticate') ?>" method="POST">
                    <div class="form-group mb-3">
                        <label for="email">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="Masukkan email" required value="<?= old('email') ?>">
                    </div>
                    <div class="form-group mb-3">
                        <label for="password">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Masukkan password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </form>
            </div>
            <div class="card-footer text-center">
                <p>PaketPro Automation. Streamlining Tomorrow Deliveries <!-- <a href="<?= base_url('register') ?>" class="text-primary">Daftar di sini</a> --></p>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>