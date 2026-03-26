<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<!-- Filters -->
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title">Filter Data</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    <label>Filter Status</label>
                    <select name="filter" id="filter" class="form-control">
                        <option value="" <?= !$filter ? 'selected' : '' ?>>Semua</option>
                        <option value="Datang" <?= $filter == 'Datang' ? 'selected' : '' ?>>Datang</option>
                        <option value="Tersortir" <?= $filter == 'Tersortir' ? 'selected' : '' ?>>Tersortir</option>
                        <option value="Proses" <?= $filter == 'Proses' ? 'selected' : '' ?>>Proses</option>
                        <option value="Gagal" <?= $filter == 'Gagal' ? 'selected' : '' ?>>Gagal</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Filter Kerusakan</label>
                    <select name="damageFilter" id="damageFilter" class="form-control">
                        <option value="" <?= !$damageFilter ? 'selected' : '' ?>>Semua</option>
                        <option value="Tidak Rusak" <?= $damageFilter == 'Tidak Rusak' ? 'selected' : '' ?>>Tidak Rusak</option>
                        <option value="Rusak Ringan" <?= $damageFilter == 'Rusak Ringan' ? 'selected' : '' ?>>Rusak Ringan</option>
                        <option value="Rusak" <?= $damageFilter == 'Rusak' ? 'selected' : '' ?>>Rusak</option>
                        <option value="Sangat Rusak" <?= $damageFilter == 'Sangat Rusak' ? 'selected' : '' ?>>Sangat Rusak</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Filter DC</label>
                    <select name="dcFilter" id="dcFilter" class="form-control">
                        <option value="" <?= empty($dcFilter) ? 'selected' : '' ?>>Semua DC</option>
                        <option value="DC-A" <?= ($dcFilter ?? '') === 'DC-A' ? 'selected' : '' ?>>DC-A</option>
                        <option value="DC-B" <?= ($dcFilter ?? '') === 'DC-B' ? 'selected' : '' ?>>DC-B</option>
                        <option value="DC-C" <?= ($dcFilter ?? '') === 'DC-C' ? 'selected' : '' ?>>DC-C</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Cari</label>
                    <form method="get" action="">
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" placeholder="Cari..." value="<?= esc($search ?? '') ?>">
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Summary Info Boxes -->
<div id="paket-sort-summary">
    <?= view('paket_sort/_summary', get_defined_vars()) ?>
</div>

<!-- Data Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Daftar Paket Tersortir</h3>
        <div class="card-tools">
            <span class="badge badge-light">Auto-refresh 5s</span>
            <span class="badge badge-secondary" id="paket-sort-updated">Update: <?= esc($lastUpdated ?? '-') ?></span>
        </div>
    </div>
    <div id="paket-sort-table">
        <?= view('paket_sort/_table', get_defined_vars()) ?>
    </div>
    <div id="paket-sort-pagination">
        <?= view('paket_sort/_pagination', get_defined_vars()) ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    const LIVE_URL = '<?= base_url('daftar_paket_sort/live') ?>';

    // Filter change handlers
    document.getElementById('filter').addEventListener('change', function() {
        updateFilters();
    });

    document.getElementById('damageFilter').addEventListener('change', function() {
        updateFilters();
    });

    document.getElementById('dcFilter').addEventListener('change', function() {
        updateFilters();
    });

    function updateFilters() {
        const params = new URLSearchParams(window.location.search);
        params.set('filter', document.getElementById('filter').value);
        params.set('damageFilter', document.getElementById('damageFilter').value);
        params.set('dcFilter', document.getElementById('dcFilter').value);
        params.set('page', '1'); // Reset to page 1 on filter change
        window.location.search = params.toString();
    }

    function pollPaketSort() {
        const params = new URLSearchParams(window.location.search);
        fetch(LIVE_URL + '?' + params.toString())
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.summary_html) {
                    document.getElementById('paket-sort-summary').innerHTML = data.summary_html;
                }
                if (data.table_html) {
                    document.getElementById('paket-sort-table').innerHTML = data.table_html;
                }
                if (data.pagination_html) {
                    document.getElementById('paket-sort-pagination').innerHTML = data.pagination_html;
                }
                if (data.lastUpdated) {
                    document.getElementById('paket-sort-updated').textContent = 'Update: ' + data.lastUpdated;
                }
            })
            .catch(function() {
                // Ignore transient polling failures
            });
    }

    setInterval(function() {
        if (!document.hidden) {
            pollPaketSort();
        }
    }, 5000);
</script>
<?= $this->endSection() ?>