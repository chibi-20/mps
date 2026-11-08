<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$session = require_login('admin');
$pdo = get_pdo();
$isAdmin = ($session['role'] ?? '') === 'admin';
$sql = "SELECT id, created_at, outcome, response_status, actor_username, actor_display, assessment_id, assessment_title, subject_name, section_id, section_name, student_identifier, score, total_items, submission_id, payload_json, payload_sha256 FROM scanner_audit_events WHERE event_type='scan'";
$params = [];
if (!$isAdmin) { $sql .= ' AND actor_id=?'; $params[] = (int)$session['user_id']; }
$sql .= ' ORDER BY id DESC LIMIT 250';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $events = $stmt->fetchAll();
$acceptedCount = count(array_filter($events, static fn($event) => in_array($event['outcome'], ['accepted', 'duplicate'], true)));
$failedCount = count($events) - $acceptedCount;
$successRate = $events ? round(($acceptedCount / count($events)) * 100, 1) : 0;
$displayName = (string)($session['display'] ?? '');
$dashboardUrl = BASE_URL . ($isAdmin ? 'admin-dashboard.php' : 'teacher-dashboard.php');
?>

<?php
$sess = $session;
$pageTitle = 'Scan Audit';
$activeNavPage = 'scan-audit';
$layoutBodyClass = 'scan-audit-page layout-fixed sidebar-expand-lg bg-body-tertiary';
require __DIR__ . '/includes/master_layout_start.php';
?>
                <div class="mps-page-heading">
                    <div><h1>Scan Audit</h1></div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="exportScanAuditCsv()"><i class="bi bi-download"></i> Export CSV Audit Log</button>
                    </div>
                </div>

                <div class="mps-metric-grid mps-audit-metrics">
                    <div class="mps-metric-card"><span>Recent Attempts</span><strong><?= count($events) ?></strong><small>Latest scan events loaded</small></div>
                    <div class="mps-metric-card mps-metric-success"><span>Accepted / Duplicate</span><strong><?= $acceptedCount ?></strong><small><?= number_format($successRate, 1) ?>% of recent attempts</small></div>
                    <div class="mps-metric-card mps-metric-critical"><span>Rejected / Flagged</span><strong><?= $failedCount ?></strong><small>Review submission details</small></div>
                    <div class="mps-metric-card"><span>Audit Window</span><strong>250</strong><small>Maximum events per view</small></div>
                </div>

                <section class="card mps-audit-card">
                    <div class="card-header">
                        <div class="mps-audit-toolbar">
                            <div><h2 class="card-title">Recent scanner attempts &amp; OMR verifications</h2><span id="scanAuditCount" class="small text-muted"><?= count($events) ?> recent records</span></div>
                            <div class="mps-audit-filters">
                                <input id="scanAuditSearch" type="search" class="form-control form-control-sm" placeholder="Search teacher, assessment, student..." oninput="filterScanAudit()" aria-label="Search scan audit records">
                                <select id="scanAuditOutcome" class="form-select form-select-sm" onchange="filterScanAudit()" aria-label="Filter scan audit by outcome">
                                    <option value="">All Outcomes</option><option value="accepted">Accepted</option><option value="duplicate">Duplicate</option><option value="rejected">Rejected / Flagged</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table data-table table-striped table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Time</th>
                                        <?php if ($isAdmin): ?><th scope="col">Teacher</th><?php endif; ?>
                                        <th scope="col">Result</th>
                                        <th scope="col">Assessment</th>
                                        <th scope="col">Student / Section</th>
                                        <th scope="col">Score</th>
                                        <th scope="col">Submission Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$events): ?>
                                    <tr class="scan-audit-empty-row"><td colspan="<?= $isAdmin ? 7 : 6 ?>" class="text-center text-muted py-4">No scanner attempts have been recorded yet.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($events as $event): ?>
                                    <?php $accepted = in_array($event['outcome'], ['accepted', 'duplicate'], true); ?>
                                    <tr data-outcome="<?= h((string)$event['outcome']) ?>">
                                        <td class="text-nowrap"><?= h((string)$event['created_at']) ?></td>
                                        <?php if ($isAdmin): ?><td><?= h((string)($event['actor_display'] ?: $event['actor_username'] ?: 'Unknown')) ?></td><?php endif; ?>
                                        <td><span class="badge <?= $accepted ? 'text-bg-success' : 'text-bg-danger' ?>"><?= h((string)$event['outcome']) ?></span><div class="small text-muted mt-1">HTTP <?= (int)$event['response_status'] ?></div></td>
                                        <td><?= h((string)($event['subject_name'] ?? '')) ?><br><?= h((string)($event['assessment_title'] ?? ('#' . ($event['assessment_id'] ?? 'unknown')))) ?></td>
                                        <td><?= h((string)($event['student_identifier'] ?? '—')) ?><br><small class="text-muted"><?= h((string)($event['section_name'] ?? ('#' . ($event['section_id'] ?? '—')))) ?></small></td>
                                        <td><?= $event['score'] === null ? '—' : (int)$event['score'] . '/' . (int)$event['total_items'] ?></td>
                                        <td>
                                            <small class="text-muted">Submission <?= h((string)($event['submission_id'] ?? '—')) ?><br>SHA-256 <?= h((string)($event['payload_sha256'] ?? '—')) ?></small>
                                            <?php if ($event['payload_json']): ?>
                                            <details class="mt-2">
                                                <summary>Recorded answers / request</summary>
                                                <pre class="small bg-body-secondary p-2 mt-1 mb-0 text-break"><?= h((string)$event['payload_json']) ?></pre>
                                            </details>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
</section>
<script>
function filterScanAudit() {
    const query = (document.getElementById('scanAuditSearch')?.value || '').toLowerCase().trim();
    const outcome = document.getElementById('scanAuditOutcome')?.value || '';
    const rows = [...document.querySelectorAll('.mps-audit-card tbody tr')].filter(row => !row.classList.contains('scan-audit-empty-row'));
    let visible = 0;
    rows.forEach(row => {
        const rowOutcome = row.dataset.outcome || '';
        const matches = (!query || row.textContent.toLowerCase().includes(query))
            && (!outcome || rowOutcome === outcome || (outcome === 'rejected' && !['accepted', 'duplicate'].includes(rowOutcome)));
        row.style.display = matches ? '' : 'none';
        if (matches) visible++;
    });
    const count = document.getElementById('scanAuditCount');
    if (count) count.textContent = `${visible} of ${rows.length} recent records`;
    window.MpsTablePagination?.refresh();
}
function exportScanAuditCsv() {
    const table = document.querySelector('.mps-audit-card table');
    if (!table) return;
    const quote = value => `"${String(value).replace(/\s+/g, ' ').trim().replace(/"/g, '""')}"`;
    const header = [...table.tHead.rows[0].cells].map(cell => quote(cell.innerText)).join(',');
    const rows = [...table.tBodies[0].rows]
        .filter(row => !row.classList.contains('scan-audit-empty-row') && row.style.display !== 'none')
        .map(row => [...row.cells].map(cell => quote(cell.innerText)).join(','));
    const blob = new Blob([header + '\r\n' + rows.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'mps-scan-audit.csv';
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}
</script>
<?php $layoutFooterScripts = '<script src="' . asset_url('table-pagination.js') . '"></script>'; require __DIR__ . '/includes/master_layout_end.php'; ?>
