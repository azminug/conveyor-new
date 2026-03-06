<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">Tambah User Baru</h3>
        </div>
        <div class="card-body">
            <!-- Flash Messages -->
            <?php if (session()->getFlashdata('success')): ?>
                <div class="alert alert-success"><?= session()->getFlashdata('success') ?></div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('error')): ?>
                <div class="alert alert-danger"><?= session()->getFlashdata('error') ?></div>
            <?php endif; ?>

            <!-- Form Tambah User -->
            <form id="tambahUserForm">
                <div class="form-group mb-3">
                    <label for="email">Email:</label>
                    <input type="email" name="email" class="form-control" id="email" placeholder="Masukkan email" required>
                </div>

                <div class="form-group mb-3">
                    <label for="password">Password:</label>
                    <input type="password" name="password" class="form-control" id="password" placeholder="Masukkan password" required>
                </div>

                <div class="form-group mb-3">
                    <label for="dc">Pilih DC:</label>
                    <select name="dc" class="form-control" id="dc" required>
                        <option value="">-- Pilih DC --</option>
                        <option value="DC-1">DC-1</option>
                        <option value="DC-2">DC-2</option>
                        <option value="DC-3">DC-3</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Tambah User</button>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        $('#tambahUserForm').on('submit', function(e) {
            e.preventDefault(); // Mencegah reload halaman
            const email = $('#email').val();
            const password = $('#password').val();
            const dc = $('#dc').val();

            // Validasi data di sisi klien
            if (!email || !password || !dc) {
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan',
                    text: 'Semua field harus diisi.'
                });
                return;
            }

            // Kirim data ke server menggunakan AJAX
            $.ajax({
                url: '<?= base_url('tambah_user_proses') ?>',
                method: 'POST',
                data: {
                    email,
                    password,
                    dc
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil',
                            text: response.success
                        }).then(() => {
                            $('#tambahUserForm')[0].reset(); // Reset form
                        });
                    } else if (response.error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Kesalahan',
                            text: response.error
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Kesalahan',
                        text: 'Terjadi kesalahan saat menambahkan user.'
                    });
                }
            });
        });
    });
</script>
<?= $this->endSection() ?>