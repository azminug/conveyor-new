<div class="row">
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-primary">
            <span class="info-box-icon"><i class="fas fa-inbox"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Datang</span>
                <span class="info-box-number"><?= esc($statusCount['Datang'] ?? 0) ?></span>
            </div>
        </div>
    </div>
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-success">
            <span class="info-box-icon"><i class="fas fa-check"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Tersortir</span>
                <span class="info-box-number"><?= esc($statusCount['Tersortir'] ?? 0) ?></span>
            </div>
        </div>
    </div>
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-warning">
            <span class="info-box-icon"><i class="fas fa-spinner"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Proses</span>
                <span class="info-box-number"><?= esc($statusCount['Proses'] ?? 0) ?></span>
            </div>
        </div>
    </div>
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-danger">
            <span class="info-box-icon"><i class="fas fa-times"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Gagal</span>
                <span class="info-box-number"><?= esc($statusCount['Gagal'] ?? 0) ?></span>
            </div>
        </div>
    </div>
    <div class="col col-sm-6" style="flex:0 0 20%;max-width:20%">
        <div class="info-box bg-info">
            <span class="info-box-icon"><i class="fas fa-boxes"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total</span>
                <span class="info-box-number"><?= esc(($statusCount['Datang'] ?? 0) + ($statusCount['Tersortir'] ?? 0) + ($statusCount['Proses'] ?? 0) + ($statusCount['Gagal'] ?? 0)) ?></span>
            </div>
        </div>
    </div>
</div>
