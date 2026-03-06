<!-- ci4_app/app/Views/profile.php -->
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="row">
    <div class="col-md-6">
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">Profil Pengguna</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="form-control" value="<?= esc($email) ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <input type="text" class="form-control" value="<?= esc(session()->get('role') ?? '-') ?>" disabled>
                </div>
                <?php if (session()->get('dcName')): ?>
                <div class="form-group">
                    <label>DC Name</label>
                    <input type="text" class="form-control" value="<?= esc(session()->get('dcName')) ?>" disabled>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card card-warning card-outline">
            <div class="card-header">
                <h3 class="card-title">Ubah Password</h3>
            </div>
            <form action="<?= base_url('profile/updatePassword') ?>" method="POST" id="changePasswordForm">
                <div class="card-body">
                    <div class="form-group">
                        <label for="currentPassword">Password Lama</label>
                        <input type="password" id="currentPassword" class="form-control" name="currentPassword" placeholder="Masukkan password lama" required>
                    </div>
                    <div class="form-group">
                        <label for="newPassword">Password Baru</label>
                        <input type="password" id="newPassword" class="form-control" name="newPassword" placeholder="Masukkan password baru" required>
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword">Konfirmasi Password Baru</label>
                        <input type="password" id="confirmPassword" class="form-control" name="confirmPassword" placeholder="Konfirmasi password baru" required>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-warning">Ubah Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        $('#changePasswordForm').on('submit', function(e) {
            e.preventDefault();

            const currentPassword = $('#currentPassword').val();
            const newPassword = $('#newPassword').val();
            const confirmPassword = $('#confirmPassword').val();

            if (newPassword !== confirmPassword) {
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan',
                    text: 'Password baru dan konfirmasi password tidak cocok.',
                });
                return;
            }

            $.ajax({
                url: '<?= base_url('profile/updatePassword') ?>',
                method: 'POST',
                data: {
                    currentPassword: currentPassword,
                    newPassword: newPassword,
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil',
                            text: 'Password berhasil diubah.',
                        });
                        $('#changePasswordForm')[0].reset();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Kesalahan',
                            text: response.message,
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Kesalahan',
                        text: 'Terjadi kesalahan saat mengubah password.',
                    });
                }
            });
        });
    });
</script>
<?= $this->endSection() ?>