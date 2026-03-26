<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="<?= base_url('/') ?>" class="brand-link">
        <img src="<?= base_url('assets/img/Project_KWU.png') ?>" alt="PaketPro Logo" class="brand-image img-circle elevation-3" style="opacity: .8">
        <span class="brand-text font-weight-light">PaketPro</span>
    </a>

    <div class="sidebar">
        <!-- Sidebar user panel -->
        <div class="user-panel mt-3 pb-3 mb-3 d-flex">
            <div class="image">
                <img src="https://adminlte.io/themes/v3/dist/img/user2-160x160.jpg" class="img-circle elevation-2" alt="User Image">
            </div>
            <div class="info">
                <a href="<?= base_url('profile') ?>" class="d-block"><?= esc(session()->get('email') ?: 'Guest') ?></a>
                <span class="text-sm text-muted">
                    <?= session()->get('role') === 'admin' ? 'Administrator' : 'Operator' ?>
                </span>
            </div>
        </div>

        <?php $currentMenu = $currentMenu ?? 'home'; ?>
        
        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                
                <!-- Dashboard -->
                <li class="nav-item">
                    <a href="<?= base_url('/') ?>" class="nav-link <?= ($currentMenu === 'home') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>

                <!-- Paket Menu -->
                <li class="nav-item <?= in_array($currentMenu, ['daftar_paket_sort', 'daftar_paket']) ? 'menu-open' : '' ?>">
                    <a href="#" class="nav-link <?= in_array($currentMenu, ['daftar_paket_sort', 'daftar_paket']) ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-boxes"></i>
                        <p>
                            Paket
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="<?= base_url('daftar_paket_sort') ?>" class="nav-link <?= ($currentMenu === 'daftar_paket_sort') ? 'active' : '' ?>">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Paket Tersortir</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="<?= base_url('daftar_paket') ?>" class="nav-link <?= ($currentMenu === 'daftar_paket') ? 'active' : '' ?>">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Daftar Paket</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Deteksi -->
                <li class="nav-item">
                    <a href="<?= base_url('paket/stream') ?>" class="nav-link <?= ($currentMenu === 'stream') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-video"></i>
                        <p>Live Stream</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="<?= base_url('device_monitor') ?>" class="nav-link <?= ($currentMenu === 'device_monitor') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-terminal"></i>
                        <p>Device Monitor</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="<?= base_url('jalur') ?>" class="nav-link <?= ($currentMenu === 'jalur_config') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-random"></i>
                        <p>Konfigurasi Jalur</p>
                    </a>
                </li>

                <!-- Profil -->
                <li class="nav-item">
                    <a href="<?= base_url('profile') ?>" class="nav-link <?= ($currentMenu === 'profile') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-user"></i>
                        <p>Profil</p>
                    </a>
                </li>

                <!-- Admin Menu -->
                <?php if (session()->get('role') === 'admin'): ?>
                <li class="nav-header">ADMIN</li>
                <li class="nav-item">
                    <a href="<?= base_url('register') ?>" class="nav-link <?= ($currentMenu === 'register') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-user-plus"></i>
                        <p>Register User</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= base_url('lihat_grafik') ?>" class="nav-link <?= ($currentMenu === 'lihat_grafik') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-chart-pie"></i>
                        <p>Statistik</p>
                    </a>
                </li>
                <?php endif; ?>

                <li class="nav-header">AKUN</li>
                <li class="nav-item">
                    <a href="<?= base_url('logout') ?>" class="nav-link">
                        <i class="nav-icon fas fa-sign-out-alt"></i>
                        <p>Logout</p>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>