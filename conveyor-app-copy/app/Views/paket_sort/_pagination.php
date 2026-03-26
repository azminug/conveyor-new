<div class="card-footer clearfix">
    <div class="float-left">
        <small class="text-muted">Menampilkan <?= count($paginatedData ?? []) ?> dari <?= $totalData ?? 0 ?> data</small>
    </div>
    <ul class="pagination pagination-sm m-0 float-right">
        <?php if (($currentPage ?? 1) > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?page=<?= $currentPage - 1 ?>&entries=<?= $entriesPerPage ?>&filter=<?= urlencode((string) ($filter ?? '')) ?>&damageFilter=<?= urlencode((string) ($damageFilter ?? '')) ?>&dcFilter=<?= urlencode((string) ($dcFilter ?? '')) ?>&search=<?= urlencode((string) ($search ?? '')) ?>">&laquo;</a>
            </li>
        <?php endif; ?>

        <?php
        $startPage = max(1, ($currentPage ?? 1) - 2);
        $endPage = min(($totalPages ?? 1), ($currentPage ?? 1) + 2);
        ?>

        <?php if ($startPage > 1): ?>
            <li class="page-item"><a class="page-link" href="?page=1&entries=<?= $entriesPerPage ?>&filter=<?= urlencode((string) ($filter ?? '')) ?>&damageFilter=<?= urlencode((string) ($damageFilter ?? '')) ?>&dcFilter=<?= urlencode((string) ($dcFilter ?? '')) ?>&search=<?= urlencode((string) ($search ?? '')) ?>">1</a></li>
            <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <li class="page-item <?= ($i == ($currentPage ?? 1)) ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>&entries=<?= $entriesPerPage ?>&filter=<?= urlencode((string) ($filter ?? '')) ?>&damageFilter=<?= urlencode((string) ($damageFilter ?? '')) ?>&dcFilter=<?= urlencode((string) ($dcFilter ?? '')) ?>&search=<?= urlencode((string) ($search ?? '')) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>

        <?php if ($endPage < ($totalPages ?? 1)): ?>
            <?php if ($endPage < ($totalPages ?? 1) - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="?page=<?= $totalPages ?>&entries=<?= $entriesPerPage ?>&filter=<?= urlencode((string) ($filter ?? '')) ?>&damageFilter=<?= urlencode((string) ($damageFilter ?? '')) ?>&dcFilter=<?= urlencode((string) ($dcFilter ?? '')) ?>&search=<?= urlencode((string) ($search ?? '')) ?>"><?= $totalPages ?></a></li>
        <?php endif; ?>

        <?php if (($currentPage ?? 1) < ($totalPages ?? 1)): ?>
            <li class="page-item">
                <a class="page-link" href="?page=<?= $currentPage + 1 ?>&entries=<?= $entriesPerPage ?>&filter=<?= urlencode((string) ($filter ?? '')) ?>&damageFilter=<?= urlencode((string) ($damageFilter ?? '')) ?>&dcFilter=<?= urlencode((string) ($dcFilter ?? '')) ?>&search=<?= urlencode((string) ($search ?? '')) ?>">&raquo;</a>
            </li>
        <?php endif; ?>
    </ul>
</div>
