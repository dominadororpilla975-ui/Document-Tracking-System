<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';

$trackingNo = trim($_GET['tracking_no'] ?? '');
$trackingError = $_SERVER['REQUEST_METHOD'] === 'GET'
    && array_key_exists('tracking_no', $_GET)
    && $trackingNo === ''
    ? 'Tracking Number is required.'
    : '';
$report = null;
$routes = [];
$logs = [];

if (!empty($trackingNo)) {
    $stmt = $pdo->prepare("SELECT r.*, d_send.name as sending_dept_name, d_recv.name as receiving_dept_name, d_orig.name as originating_dept_name
                          FROM reports r
                          LEFT JOIN departments d_send ON r.sender_department_id = d_send.id
                          LEFT JOIN departments d_recv ON r.department_id = d_recv.id
                          LEFT JOIN departments d_orig ON r.originating_department_id = d_orig.id
                          WHERE UPPER(r.tracking_number) = UPPER(:tn)
                            AND COALESCE(r.is_deleted, 0) = 0
                            AND COALESCE(r.is_suspended, 0) = 0
                          LIMIT 1");
    $stmt->execute(['tn' => $trackingNo]);
    $report = $stmt->fetch();

    if ($report) {
        $rStmt = $pdo->prepare("SELECT r.*, COALESCE(d.name, CASE WHEN r.destination_type = 'Resident' THEN 'Resident' ELSE 'Department' END) as dept_name 
                                FROM report_workflow_routes r
                                LEFT JOIN departments d ON r.department_id = d.id
                                WHERE r.report_id = :id ORDER BY r.step_order ASC, r.step_number ASC");
        $rStmt->execute(['id' => $report['id']]);
        $routes = $rStmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Document Tracking | Municipality of Carranglan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 0;
        }
        .header {
            background: #081427;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .container {
            max-width: 900px;
            margin: 2.5rem auto;
            padding: 0 1.5rem;
        }
        .card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }
        .search-box {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .tracking-field {
            position: relative;
            flex: 1;
        }
        .tracking-field label {
            position: absolute;
            top: -0.9rem;
            left: 0.7rem;
            color: #334155;
            font-size: 0.8rem;
        }
        .search-input {
            flex: 1;
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
        }
        .btn-search {
            background: #1d63ed;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
        }
        .badge {
            display: inline-block;
            padding: 0.35rem 0.8rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.82rem;
        }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-processing { background: #eff6ff; color: #1e40af; }
        .badge-pending { background: #fffbeb; color: #b45309; }
    </style>
    <link rel="stylesheet" href="css/status_visibility.css?v=<?= file_exists(APP_ROOT . '/css/status_visibility.css') ? (string) filemtime(APP_ROOT . '/css/status_visibility.css') : '1' ?>">
</head>
<body>
    <div class="header">
        <div style="font-weight: 800; font-size: 1.1rem; display: flex; align-items: center; gap: 0.6rem;">
            <span>📋</span> Municipality of Carranglan - Document Tracker
        </div>
        <a href="index.php" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem; font-weight: 600;">← Back to Home</a>
    </div>

    <div class="container">
        <div class="card">
            <h2 style="font-size: 1.3rem; margin: 0 0 1rem 0;">🔍 Quick Document Status Search</h2>
            <form action="track.php" method="GET" class="search-box">
                <div class="tracking-field">
                    <label for="tracking_no">Tracking Number <span style="color:#dc2626;font-weight:700;" aria-hidden="true">*</span></label>
                    <input id="tracking_no" type="text" name="tracking_no" class="search-input" value="<?= htmlspecialchars($trackingNo) ?>" placeholder="Enter your tracking number" required>
                </div>
                <button type="submit" class="btn-search">Track Now</button>
            </form>
            <?php if ($trackingError !== ''): ?>
                <div role="alert" style="margin-top:0.75rem; color:#b91c1c; font-size:0.9rem;"><?= htmlspecialchars($trackingError) ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($trackingNo)): ?>
            <?php if ($report): 
                $st = strtoupper($report['status']);
                $badgeClass = 'badge-processing';
                if ($st === 'COMPLETED') $badgeClass = 'badge-completed';
                elseif ($st === 'PENDING') $badgeClass = 'badge-pending';
            ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <span style="font-size: 0.85rem; color: #64748b; font-weight: 600;">Tracking Number</span>
                            <h1 style="font-size: 1.6rem; color: #1d63ed; margin: 0; font-family: monospace;"><?= htmlspecialchars($report['tracking_number']) ?></h1>
                        </div>
                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($report['status']) ?></span>
                    </div>

                    <h3 style="font-size: 1.15rem; color: #0f172a; margin: 0 0 0.5rem 0;"><?= htmlspecialchars($report['title']) ?></h3>
                    <p style="color: #475569; font-size: 0.92rem; line-height: 1.5; margin: 0 0 1.5rem 0;"><?= htmlspecialchars($report['description']) ?></p>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; background: #f8fafc; padding: 1rem; border-radius: 10px; border: 1px solid #e2e8f0; font-size: 0.88rem;">
                        <div>
                            <span style="color: #64748b; display: block; font-size: 0.78rem;">Originating Department</span>
                            <strong>🏢 <?= htmlspecialchars($report['originating_dept_name'] ?? $report['sending_dept_name'] ?? 'Main Office') ?></strong>
                        </div>
                        <div>
                            <span style="color: #64748b; display: block; font-size: 0.78rem;">Current Location</span>
                            <strong>🏢 <?= htmlspecialchars($report['receiving_dept_name'] ?? 'In Transit') ?></strong>
                        </div>
                        <div>
                            <span style="color: #64748b; display: block; font-size: 0.78rem;">Date Created</span>
                            <strong>📅 <?= date('m/d/Y - h:i A', strtotime($report['created_at'])) ?></strong>
                        </div>
                    </div>
                </div>

                <!-- ROUTING STEPS TIMELINE -->
                <div class="card">
                    <h3 style="font-size: 1.1rem; margin-bottom: 1.25rem;">📍 Routing &amp; Workflow Progress</h3>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <?php foreach ($routes as $idx => $r): 
                            $stepSt = strtolower($r['status']);
                            $stepIcon = '⏳';
                            $stepColor = '#f59e0b';
                            if ($stepSt === 'completed') { $stepIcon = '✓'; $stepColor = '#16a34a'; }
                            elseif ($stepSt === 'in progress') { $stepIcon = '⟳'; $stepColor = '#2563eb'; }
                        ?>
                            <div style="display: flex; gap: 1rem; align-items: flex-start;">
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: <?= $stepColor ?>; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; flex-shrink: 0;">
                                    <?= $stepIcon ?>
                                </div>
                                <div>
                                    <strong style="color: #0f172a; font-size: 0.95rem;">Step <?= $r['step_number'] ?>: <?= htmlspecialchars($r['dept_name']) ?></strong>
                                    <span style="display: block; font-size: 0.82rem; color: #64748b;">Status: <?= htmlspecialchars(ucwords($r['status'])) ?><?php if ($r['completed_date']): ?> • <?= date('m/d/Y h:i A', strtotime($r['completed_date'])) ?><?php endif; ?></span>
                                    <?php if ($r['approver_notes']): ?>
                                        <p style="margin: 0.25rem 0 0 0; font-size: 0.85rem; color: #475569; font-style: italic;">"<?= htmlspecialchars($r['approver_notes']) ?>"</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card" style="text-align: center; color: #94a3b8; padding: 3rem;">
                    <span style="font-size: 3rem; display: block; margin-bottom: 0.5rem;">🔍</span>
                    <h3 style="color: #0f172a; margin: 0 0 0.5rem 0;">Document Not Found</h3>
                    <p style="margin: 0; font-size: 0.9rem;">No document matching <strong><?= htmlspecialchars($trackingNo) ?></strong> was found in the tracking database.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
