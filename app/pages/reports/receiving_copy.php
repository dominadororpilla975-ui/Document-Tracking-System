<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/privacy_guard.php';
require_once APP_ROOT . '/config/audit_log.php';

requireLogin();
$user = currentUser();
requirePermission('view_documents', $user);

$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$reportId) {
    die("Invalid Report ID.");
}

// Fetch report details
$stmt = $pdo->prepare("SELECT r.*, u.name as client_name, u.email as client_email, d.name as dept_name 
                      FROM reports r 
                      LEFT JOIN users u ON r.client_id = u.id 
                      LEFT JOIN departments d ON r.department_id = d.id 
                      WHERE r.id = :id
                        AND COALESCE(r.is_deleted, 0) = 0
                        AND COALESCE(r.is_suspended, 0) = 0");
$stmt->execute(['id' => $reportId]);
$report = $stmt->fetch();

if (!$report) {
    die("Report not found.");
}
$permission = checkDocumentPrivacy($user, $report, $pdo);
if (!$permission['can_view_content']) {
    http_response_code(403);
    exit('You do not have permission to access this receiving copy.');
}
auditLog($pdo, (int)$user['id'], 'RECEIVING_COPY_PRINTED', 'Official receiving copy viewed/printed', $reportId, (int)($report['department_id'] ?? 0));

// Fetch workflow routes if any
$routesStmt = $pdo->prepare("SELECT rwf.*, COALESCE(d.name, CASE WHEN rwf.destination_type = 'Resident' THEN 'Resident' ELSE 'Department' END) as dept_name 
                            FROM report_workflow_routes rwf 
                            LEFT JOIN departments d ON rwf.department_id = d.id 
                            WHERE rwf.report_id = :id 
                            ORDER BY rwf.step_order ASC, rwf.step_number ASC");
$routesStmt->execute(['id' => $reportId]);
$workflowRoutes = $routesStmt->fetchAll();

$trackingNo = $report['tracking_number'];
$dateObj = new DateTime($report['created_at']);
$dayFormatted = $dateObj->format('jS');
$monthYearFormatted = $dateObj->format('F, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Receiving Copy - <?= $trackingNo ?></title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            background-color: #f1f5f9;
            color: #000000;
            margin: 0;
            padding: 30px 20px;
        }

        .no-print {
            max-width: 800px;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Inter', system-ui, sans-serif;
        }

        .btn {
            padding: 10px 20px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            font-size: 14px;
        }
        .btn-primary { background: #2563eb; color: white; }
        .btn-secondary { background: #e2e8f0; color: #334155; }

        /* Official Certificate Sheet Layout */
        .cert-paper {
            max-width: 800px;
            min-height: 950px;
            margin: 0 auto;
            background: #ffffff;
            padding: 50px 65px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            position: relative;
            box-sizing: border-box;
        }

        /* Header Layout matching image */
        .header-layout {
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-align: center;
            margin-bottom: 5px;
        }

        .header-logo-img {
            width: 95px;
            height: 95px;
            object-fit: contain;
        }

        .header-text {
            flex: 1;
            padding: 0 15px;
            line-height: 1.25;
        }

        .header-text .gov-title {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .header-text .province-title {
            font-size: 13px;
        }

        .header-text .muni-title {
            font-size: 15px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .header-text .office-title {
            font-size: 17px;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 6px;
            letter-spacing: 1px;
        }

        .header-divider {
            border: none;
            border-top: 2px solid #000000;
            border-bottom: 1px solid #000000;
            height: 3px;
            margin: 12px 0 35px 0;
        }

        /* Certificate Main Title */
        .cert-title {
            text-align: center;
            font-size: 26px;
            font-weight: bold;
            letter-spacing: 12px;
            text-transform: uppercase;
            margin-bottom: 40px;
        }

        /* Body Certification Content */
        .cert-body {
            font-size: 15px;
            line-height: 1.8;
            text-align: justify;
            text-justify: inter-word;
        }

        .cert-paragraph {
            margin-bottom: 22px;
            text-indent: 40px;
        }

        .highlight-name {
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
        }

        /* Meta Box */
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 14px;
            border: 1px solid #000000;
        }

        .meta-table td {
            padding: 8px 12px;
            border: 1px solid #000000;
        }

        .meta-table td.label-col {
            font-weight: bold;
            background: #f8fafc;
            width: 28%;
        }

        /* Routing Table */
        .routing-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 13px;
        }

        .routing-table th, .routing-table td {
            border: 1px solid #000000;
            padding: 6px 10px;
            text-align: left;
        }

        .routing-table th {
            background-color: #f1f5f9;
            text-transform: uppercase;
            font-size: 11px;
        }

        /* Signature Section matching image right align */
        .signature-section {
            margin-top: 50px;
            display: flex;
            justify-content: flex-end;
        }

        .signature-block {
            width: 320px;
            text-align: center;
        }

        .signatory-name {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
            margin-bottom: 4px;
        }

        .signatory-title {
            font-size: 14px;
            color: #333333;
        }

        @media print {
            body { background: white; padding: 0; }
            .no-print { display: none !important; }
            .cert-paper { border: none; box-shadow: none; padding: 40px 50px; width: 100%; max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a href="report_details.php?id=<?= $report['id'] ?>" class="btn btn-secondary">
        ← Back to Document Details
    </a>
    <button onclick="window.print()" class="btn btn-primary">
        🖨️ Print Official Receiving Copy
    </button>
</div>

<div class="cert-paper">
    <!-- Header Layout with Actual Image Logos -->
    <div class="header-layout">
        <img src="image/logo.jpg" alt="Bayan ng Carranglan Logo" class="header-logo-img">
        
        <div class="header-text">
            <div class="gov-title">Republic of the Philippines</div>
            <div class="province-title">Province of Nueva Ecija</div>
            <div class="muni-title">MUNICIPALITY OF CARRANGLAN</div>
            <div class="office-title">OFFICE OF THE RECEIVING DESK</div>
        </div>
        
        <img src="image/bagongpilipinas.jpg" alt="Bagong Pilipinas Logo" class="header-logo-img">
    </div>

    <div class="header-divider"></div>

    <!-- Document Certificate Title -->
    <div class="cert-title">
        RECEIVING &nbsp; COPY
    </div>

    <!-- Official Certification Body Wording -->
    <div class="cert-body">
        <div class="cert-paragraph">
            THIS IS TO CERTIFY that <span class="highlight-name"><?= htmlspecialchars($report['client_name'] ?? 'CITIZEN APPLICANT') ?></span> has officially submitted a formal document request titled <strong>"<?= htmlspecialchars($report['title']) ?>"</strong> registered under Tracking Number <strong style="font-family: monospace; font-size: 16px;"><?= $trackingNo ?></strong>.
        </div>

        <div class="cert-paragraph">
            I CERTIFY FURTHER that this document has been received, logged, and assigned to the <strong><?= htmlspecialchars($report['dept_name'] ?? 'Office of the Mayor') ?></strong> for evaluation, processing, and appropriate action.
        </div>

        <!-- Meta Summary Table -->
        <table class="meta-table">
            <tr>
                <td class="label-col">Tracking Number:</td>
                <td><strong style="font-family: monospace; font-size: 15px;"><?= $trackingNo ?></strong></td>
                <td class="label-col">Date Received:</td>
                <td><?= date('m/d/Y - h:i A', strtotime($report['created_at'])) ?></td>
            </tr>
            <tr>
                <td class="label-col">Primary Department:</td>
                <td><?= htmlspecialchars($report['dept_name'] ?? 'Unassigned') ?></td>
                <td class="label-col">Current Status:</td>
                <td><strong><?= htmlspecialchars($report['status']) ?></strong></td>
            </tr>
        </table>

        <!-- Routing Chain Table -->
        <div style="font-weight: bold; margin-top: 15px; font-size: 13px; text-transform: uppercase;">
            Department Routing Chain (Kung Saan Department Pupunta):
        </div>
        <table class="routing-table">
            <thead>
                <tr>
                    <th style="width: 50px;">Step</th>
                    <th>Target Department</th>
                    <th>Action / Purpose</th>
                    <th style="width: 100px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($workflowRoutes)): ?>
                    <tr>
                        <td>Step 1</td>
                        <td><strong><?= htmlspecialchars($report['dept_name'] ?? 'Office of the Mayor') ?></strong></td>
                        <td>Initial Evaluation & Department Action</td>
                        <td><?= htmlspecialchars($report['status']) ?></td>
                    </tr>
                    <tr>
                        <td>Step 2</td>
                        <td>Target Action Department Head</td>
                        <td>Detailed Processing & Review</td>
                        <td>Pending</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($workflowRoutes as $route): ?>
                        <tr>
                            <td>Step <?= $route['step_number'] ?></td>
                            <td><strong><?= htmlspecialchars($route['dept_name']) ?></strong></td>
                            <td><?= htmlspecialchars($route['approver_notes'] ?: 'Processing & Department Action') ?></td>
                            <td><?= htmlspecialchars($route['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="cert-paragraph" style="margin-top: 22px;">
            This <strong>RECEIVING COPY</strong> is being issued upon request of the interested party for reference, tracking, and for whatever legal intents and purposes it may serve.
        </div>

        <div class="cert-paragraph">
            Given this <strong><?= $dayFormatted ?></strong> day of <strong><?= $monthYearFormatted ?></strong> at Municipality of Carranglan, Nueva Ecija, Philippines.
        </div>
    </div>

</div>

</body>
</html>
