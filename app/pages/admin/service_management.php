<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/upload_security.php';

requireLogin();
$user = currentUser();
requirePermission('manage_services', $user);
$isSuperAdminUser = isSuperAdmin($user);
$isDepartmentScopedUser = isDepartmentUser($user) && !$isSuperAdminUser;
$ownDepartmentId = (int) ($user['department_id'] ?? 0);

$error = '';
$success = '';
$allDepartments = $pdo->query('SELECT * FROM departments WHERE deleted_at IS NULL ORDER BY name ASC')->fetchAll();

function handleServiceDocumentUpload(array $file): array
{
    $allowedMimes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
    ];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Service file must be 10 MB or smaller.');
    }
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($file['tmp_name']);
    if (!isset($allowedMimes[$mimeType])) {
        throw new InvalidArgumentException('Service file must be a PDF or JPG/JPEG file.');
    }
    $uploadDirectory = APP_ROOT . '/uploads/services';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to prepare service file storage.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mimeType];
    if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . '/' . $storedName)) {
        throw new RuntimeException('Unable to save the service file.');
    }
    return [
        'original_name' => basename((string) ($file['name'] ?? 'service-file')),
        'stored_name' => $storedName,
        'mime_type' => $mimeType,
        'file_size' => (int) $file['size'],
    ];
}

function saveServiceDocument(PDO $pdo, int $serviceId, array $fileData, int $userId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO service_documents (service_id, original_name, stored_name, mime_type, file_size, uploaded_by)
         VALUES (:service_id, :original_name, :stored_name, :mime_type, :file_size, :uploaded_by)'
    );
    $stmt->execute([
        'service_id' => $serviceId,
        'original_name' => $fileData['original_name'],
        'stored_name' => $fileData['stored_name'],
        'mime_type' => $fileData['mime_type'],
        'file_size' => $fileData['file_size'],
        'uploaded_by' => $userId,
    ]);
}

function validateActivityName(string $name): ?string
{
    $name = trim($name);
    $lettersOnly = preg_replace('/[^\p{L}]/u', '', $name) ?? '';
    $wordCount = preg_match_all('/\p{L}+/u', $name, $unusedMatches);
    if ($name === '' || mb_strlen($name) < 5 || mb_strlen($name) > 150 || mb_strlen($lettersOnly) < 5) {
        return 'Service name must be between 5 and 150 characters and contain at least 5 letters.';
    }
    if (!preg_match('/\p{L}/u', $name)) {
        return 'Activity name must contain letters and cannot be numbers only.';
    }
    $vowelCount = preg_match_all('/[aeiou]/iu', $lettersOnly, $unusedVowels);
    $isShortAcronym = preg_match('/^[A-Z]{3,10}$/', str_replace(' ', '', $name)) === 1;
    if ((!$isShortAcronym && ($wordCount < 2 || $vowelCount < 2))
        || preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/iu', $lettersOnly)
        || preg_match('/(.)\1\1/iu', $lettersOnly)
        || preg_match('/(?:qwerty|asdf|zxcv|qaz|wsx)/i', $lettersOnly)) {
        return 'Please enter a meaningful service name, such as "Birth Certificate Request".';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceFile = $_FILES['service_file'] ?? null;
    $retryAfter = hasUploadAttempt($serviceFile)
        ? enforceUploadCooldown($pdo, (int) $user['id'])
        : 0;
    $action = $retryAfter > 0 ? '' : ($_POST['action'] ?? '');
    if ($retryAfter > 0) {
        $error = uploadCooldownMessage($retryAfter);
    }

    if ($action === 'create_service') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
        if ($isDepartmentScopedUser) {
            $departmentId = $ownDepartmentId;
        }
        $serviceName = trim($_POST['service_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $detailLabels = $_POST['detail_label'] ?? [];
        $detailValues = $_POST['detail_value'] ?? [];
        $detailRequired = $_POST['detail_required'] ?? [];
        $detailTypes = $_POST['detail_type'] ?? [];
        $detailRows = [];
        foreach ($detailLabels as $index => $label) {
            $label = trim((string) $label);
            $value = trim((string) ($detailValues[$index] ?? ''));
            if ($label !== '' || $value !== '') {
                $type = (($detailTypes[$index] ?? 'text') === 'file') ? 'file' : 'text';
                $detailRows[] = ['label' => $label, 'value' => $value, 'type' => $type, 'required' => isset($detailRequired[$index])];
            }
        }
        $detailRowsJson = json_encode($detailRows, JSON_UNESCAPED_UNICODE);
        $slaDays = max(0, (int) ($_POST['sla_days'] ?? 0));
        $status = trim($_POST['status'] ?? 'Active');
        $priority = trim($_POST['priority'] ?? 'Medium');
        $workflowDepartments = $_POST['create_workflow_department_id'] ?? [];
        if (!is_array($workflowDepartments)) {
            $workflowDepartments = [$workflowDepartments];
        }
        $workflowDepartments = array_values(array_filter(
            array_map('intval', $workflowDepartments),
            static fn (int $workflowDepartmentId): bool => $workflowDepartmentId > 0
        ));
        $nameError = validateActivityName($serviceName);
        if ($nameError !== null) {
            $error = $nameError;
        }

        if (empty($error) && $departmentId > 0 && $serviceName !== '') {
            $assignedUserId = $assignedUserId > 0 ? $assignedUserId : null;
            if ($assignedUserId !== null) {
                $staffCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id AND department_id = :department_id AND role IN ('department_staff', 'staff') AND account_status = 'active' LIMIT 1");
                $staffCheck->execute(['id' => $assignedUserId, 'department_id' => $departmentId]);
                if (!$staffCheck->fetchColumn()) {
                    $error = 'The selected staff member does not belong to this department.';
                }
            }
        }

        if (empty($error)) {
            $validDepartmentIds = array_map('intval', array_column($allDepartments, 'id'));
            if (empty($workflowDepartments)) {
                $error = 'Add at least one department to the activity workflow.';
            } elseif ($workflowDepartments[0] !== $departmentId) {
                $error = 'The first workflow department must match the activity department.';
            } elseif (array_diff($workflowDepartments, $validDepartmentIds)) {
                $error = 'The workflow contains an invalid department.';
            }
        }

        if (empty($error) && $departmentId > 0 && $serviceName !== '') {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO department_services (department_id, assigned_user_id, service_name, description, detail_rows, sla_days, status, priority) VALUES (:department_id, :assigned_user_id, :service_name, :description, :detail_rows, :sla_days, :status, :priority)');
                $stmt->execute([
                    'department_id' => $departmentId,
                    'assigned_user_id' => $assignedUserId,
                    'service_name' => $serviceName,
                    'description' => $description,
                    'detail_rows' => $detailRowsJson,
                    'sla_days' => $slaDays > 0 ? $slaDays : null,
                    'status' => $status,
                    'priority' => $priority,
                ]);
                $serviceId = (int) $pdo->lastInsertId();
                $workflowStmt = $pdo->prepare('INSERT INTO service_workflow_steps (service_id, step_number, department_id, required_action, status) VALUES (:service_id, :step_number, :department_id, :required_action, "Active")');
                foreach ($workflowDepartments as $stepNumber => $workflowDepartmentId) {
                    $workflowStmt->execute([
                        'service_id' => $serviceId,
                        'step_number' => $stepNumber + 1,
                        'department_id' => $workflowDepartmentId,
                        'required_action' => $stepNumber === count($workflowDepartments) - 1 ? 'Approve and Complete' : 'Process and Forward',
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Unable to create the service workflow.';
            }
        }
        if (empty($error) && isset($serviceId)) {
            if (isset($_FILES['service_file']) && ($_FILES['service_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    saveServiceDocument($pdo, $serviceId, handleServiceDocumentUpload($_FILES['service_file']), (int) $user['id']);
                } catch (InvalidArgumentException | RuntimeException | PDOException $exception) {
                    $error = $exception->getMessage();
                }
            }
            if (empty($error)) {
                $success = 'Service created successfully.';
            }
        } elseif (empty($error)) {
            $error = 'Department and service name are required.';
        }
    } elseif ($action === 'update_service') {
        $id = (int) ($_POST['service_id'] ?? 0);
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);
        $serviceName = trim($_POST['service_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $detailLabels = $_POST['detail_label'] ?? [];
        $detailValues = $_POST['detail_value'] ?? [];
        $detailRequired = $_POST['detail_required'] ?? [];
        $detailTypes = $_POST['detail_type'] ?? [];
        $detailRows = [];
        foreach ($detailLabels as $index => $label) {
            $label = trim((string) $label);
            $value = trim((string) ($detailValues[$index] ?? ''));
            if ($label !== '' || $value !== '') {
                $type = (($detailTypes[$index] ?? 'text') === 'file') ? 'file' : 'text';
                $detailRows[] = ['label' => $label, 'value' => $value, 'type' => $type, 'required' => isset($detailRequired[$index])];
            }
        }
        $detailRowsJson = json_encode($detailRows, JSON_UNESCAPED_UNICODE);
        $slaDays = max(0, (int) ($_POST['sla_days'] ?? 0));
        $status = trim($_POST['status'] ?? 'Active');
        $priority = trim($_POST['priority'] ?? 'Medium');
        $nameError = validateActivityName($serviceName);
        if ($nameError !== null) {
            $error = $nameError;
        }

        if ($isDepartmentScopedUser) {
            $ownershipStmt = $pdo->prepare('SELECT id FROM department_services WHERE id = :id AND department_id = :department_id LIMIT 1');
            $ownershipStmt->execute(['id' => $id, 'department_id' => $ownDepartmentId]);
            if (!$ownershipStmt->fetchColumn()) {
                $error = 'You can only edit activities belonging to your department.';
            }
            $departmentId = $ownDepartmentId;
        }

        $assignedUserId = $assignedUserId > 0 ? $assignedUserId : null;
        if (empty($error) && $assignedUserId !== null) {
            $staffCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id AND department_id = :department_id AND role IN ('department_staff', 'staff') AND account_status = 'active' LIMIT 1");
            $staffCheck->execute(['id' => $assignedUserId, 'department_id' => $departmentId]);
            if (!$staffCheck->fetchColumn()) {
                $error = 'The selected staff member does not belong to this department.';
            }
        }

        if (empty($error) && $id > 0 && $departmentId > 0 && $serviceName !== '') {
            $stmt = $pdo->prepare('UPDATE department_services SET department_id = :department_id, assigned_user_id = :assigned_user_id, service_name = :service_name, description = :description, detail_rows = :detail_rows, sla_days = :sla_days, status = :status, priority = :priority WHERE id = :id');
            $stmt->execute([
                'department_id' => $departmentId,
                'assigned_user_id' => $assignedUserId,
                'service_name' => $serviceName,
                'description' => $description,
                'detail_rows' => $detailRowsJson,
                'sla_days' => $slaDays > 0 ? $slaDays : null,
                'status' => $status,
                'priority' => $priority,
                'id' => $id,
            ]);
            if (isset($_FILES['service_file']) && ($_FILES['service_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    saveServiceDocument($pdo, $id, handleServiceDocumentUpload($_FILES['service_file']), (int) $user['id']);
                } catch (InvalidArgumentException | RuntimeException | PDOException $exception) {
                    $error = $exception->getMessage();
                }
            }
            if (empty($error)) {
                $success = 'Service updated successfully.';
            }
        } else {
            $error = 'Service data is invalid.';
        }
    } elseif ($action === 'delete_service_document') {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $documentStmt = $pdo->prepare(
            'SELECT sd.id, sd.stored_name, ds.department_id
             FROM service_documents sd
             INNER JOIN department_services ds ON ds.id = sd.service_id
             WHERE sd.id = :document_id AND sd.service_id = :service_id
             LIMIT 1'
        );
        $documentStmt->execute(['document_id' => $documentId, 'service_id' => $serviceId]);
        $serviceDocument = $documentStmt->fetch();

        if (!$serviceDocument) {
            $error = 'The selected activity file was not found.';
        } elseif ($isDepartmentScopedUser && (int) $serviceDocument['department_id'] !== $ownDepartmentId) {
            $error = 'You can only remove files from activities belonging to your department.';
        } else {
            $filePath = APP_ROOT . '/uploads/services/' . basename((string) $serviceDocument['stored_name']);
            if (is_file($filePath) && !unlink($filePath)) {
                $error = 'Unable to remove the uploaded file. Please try again.';
            } else {
                $deleteDocumentStmt = $pdo->prepare(
                    'DELETE FROM service_documents WHERE id = :document_id AND service_id = :service_id'
                );
                $deleteDocumentStmt->execute(['document_id' => $documentId, 'service_id' => $serviceId]);
                if ($deleteDocumentStmt->rowCount() !== 1) {
                    $error = 'The activity file could not be removed from the database.';
                } else {
                    $success = 'Activity file removed successfully.';
                }
            }
        }
    } elseif ($action === 'delete_service') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($isDepartmentScopedUser) {
            $ownershipStmt = $pdo->prepare('SELECT id FROM department_services WHERE id = :id AND department_id = :department_id LIMIT 1');
            $ownershipStmt->execute(['id' => $id, 'department_id' => $ownDepartmentId]);
            if (!$ownershipStmt->fetchColumn()) {
                $error = 'You can only delete activities belonging to your department.';
            }
        }
        if (empty($error) && $id > 0) {
            $stmt = $pdo->prepare('UPDATE department_services SET deleted_at = NOW(), deleted_by = :deleted_by, deleted_reason = :deleted_reason WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute([
                'id' => $id,
                'deleted_by' => (int) $user['id'],
                'deleted_reason' => 'Moved to Trash Bin by administrator',
            ]);
            $success = 'Department activity moved to Trash Bin.';
        } else {
            $error = 'Invalid service selected.';
        }
    } elseif ($action === 'save_workflow') {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $workflowDepartments = $_POST['workflow_department_id'] ?? [];
        if (!is_array($workflowDepartments)) {
            $workflowDepartments = [$workflowDepartments];
        }
        $workflowDepartments = array_values(array_filter(array_map('intval', $workflowDepartments)));

        $serviceScope = 'SELECT department_id FROM department_services WHERE id = :id';
        $serviceScopeParams = ['id' => $serviceId];
        if ($isDepartmentScopedUser) {
            $serviceScope .= ' AND department_id = :department_id';
            $serviceScopeParams['department_id'] = $ownDepartmentId;
        }
        $serviceScopeStmt = $pdo->prepare($serviceScope);
        $serviceScopeStmt->execute($serviceScopeParams);
        $serviceDepartmentId = (int) $serviceScopeStmt->fetchColumn();

        if ($serviceId <= 0 || $serviceDepartmentId <= 0 || empty($workflowDepartments)) {
            $error = 'Select at least one workflow department for this activity.';
        } elseif ((int) $workflowDepartments[0] !== $serviceDepartmentId) {
            $error = 'The first workflow department must match the activity department.';
        } else {
            $validDepartmentIds = array_map('intval', array_column($allDepartments, 'id'));
            if (array_diff($workflowDepartments, $validDepartmentIds)) {
                $error = 'The workflow contains an invalid department.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM service_workflow_steps WHERE service_id = :service_id')->execute(['service_id' => $serviceId]);
                    $workflowInsert = $pdo->prepare('INSERT INTO service_workflow_steps (service_id, step_number, department_id, required_action, status) VALUES (:service_id, :step_number, :department_id, :required_action, "Active")');
                    foreach ($workflowDepartments as $stepNumber => $workflowDepartmentId) {
                        $workflowInsert->execute([
                            'service_id' => $serviceId,
                            'step_number' => $stepNumber + 1,
                            'department_id' => $workflowDepartmentId,
                            'required_action' => $stepNumber === count($workflowDepartments) - 1 ? 'Approve and Complete' : 'Process and Forward',
                        ]);
                    }
                    $pdo->commit();
                    $success = 'Activity workflow saved successfully.';
                } catch (Exception $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Unable to save the activity workflow.';
                }
            }
        }
    }
}

$departments = $allDepartments;
$staffSql = "SELECT id, name, department_id FROM users WHERE role IN ('department_staff', 'staff') AND account_status = 'active'";
$staffParams = [];
if ($isDepartmentScopedUser) {
    $staffSql .= ' AND department_id = :department_id';
    $staffParams['department_id'] = $ownDepartmentId;
}
$staffSql .= ' ORDER BY name ASC';
$staffStmt = $pdo->prepare($staffSql);
$staffStmt->execute($staffParams);
$staffUsers = $staffStmt->fetchAll();
$departmentFilter = (int) ($_GET['department_id'] ?? 0);
if ($isDepartmentScopedUser) {
    $departmentFilter = $ownDepartmentId;
    $departments = array_values(array_filter($departments, static fn ($department) => (int) $department['id'] === $ownDepartmentId));
}
$serviceSql = 'SELECT ds.*, d.name AS department_name, su.name AS assigned_staff_name FROM department_services ds LEFT JOIN departments d ON d.id = ds.department_id LEFT JOIN users su ON su.id = ds.assigned_user_id WHERE d.deleted_at IS NULL AND ds.deleted_at IS NULL';
$serviceParams = [];
if ($departmentFilter > 0) {
    $serviceSql .= ' AND ds.department_id = :department_id';
    $serviceParams['department_id'] = $departmentFilter;
}
$serviceSql .= ' ORDER BY d.name ASC, ds.service_name ASC';
$serviceStmt = $pdo->prepare($serviceSql);
foreach ($serviceParams as $paramName => $paramValue) {
    $serviceStmt->bindValue(':' . $paramName, $paramValue);
}
$serviceStmt->execute();
$services = $serviceStmt->fetchAll();
$serviceDocuments = [];
if (!empty($services)) {
    $serviceIds = implode(',', array_map(static fn ($service): int => (int) $service['id'], $services));
    $documentRows = $pdo->query("SELECT * FROM service_documents WHERE service_id IN ($serviceIds) ORDER BY created_at DESC, id DESC")->fetchAll();
    foreach ($documentRows as $documentRow) {
        $serviceDocuments[(int) $documentRow['service_id']][] = $documentRow;
    }
}
$workflowByService = [];
if (!empty($services)) {
    $serviceIds = implode(',', array_map(static fn ($service) => (int) $service['id'], $services));
    $workflowRows = $pdo->query("SELECT sws.*, d.name AS department_name FROM service_workflow_steps sws INNER JOIN departments d ON d.id = sws.department_id WHERE sws.service_id IN ($serviceIds) ORDER BY sws.service_id, sws.step_number ASC")->fetchAll();
    foreach ($workflowRows as $workflowRow) {
        $workflowByService[(int) $workflowRow['service_id']][] = $workflowRow;
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="management-toolbar">
    <div>
        <h1>Department Service Management</h1>
        <p style="color: #64748b; margin: 0;">Manage services and agency offerings assigned to each municipal department or office.</p>
    </div>
    <div style="display:flex; gap:0.65rem; align-items:center; flex-wrap:wrap;">
        <button type="button" class="admin-primary-button add-create-control" title="Add Service" aria-label="Add service" data-activity-modal-open="add-service-modal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Service</button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card">
    <?php if ($departmentFilter > 0): ?>
        <h3 style="margin-top:0;">Activities for <?= htmlspecialchars($services[0]['department_name'] ?? 'Selected Department') ?></h3>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table" data-datatable-responsive="false" data-datatable-search="true" data-datatable-paging="true" data-datatable-ordering="true" data-datatable-info="true">
            <thead>
                <tr>
                    <th>Service Name</th>
                    <th>Department / Agency</th>
                    <th>Assigned Staff</th>
                    <th>Activity Details</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($services)): ?>
                    <tr><td colspan="7" style="text-align:center; color:#64748b; padding:2rem;">No services or activities configured for this department.</td></tr>
                <?php endif; ?>
                <?php foreach ($services as $service): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($service['service_name']) ?></strong></td>
                        <td>
                            <a href="service_management.php?department_id=<?= (int) $service['department_id'] ?>" title="View activities for this department">
                                <?= htmlspecialchars($service['department_name'] ?? '—') ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($service['assigned_staff_name'] ?? 'All department staff') ?></td>
                        <td>
                            <?php $tableDetailRows = json_decode((string) ($service['detail_rows'] ?? ''), true); ?>
                            <?php if (is_array($tableDetailRows) && $tableDetailRows): ?>
                                <div class="activity-details-summary">
                                    <?php foreach (array_slice($tableDetailRows, 0, 3) as $detailRow): ?>
                                        <div><strong><?= htmlspecialchars((string) ($detailRow['label'] ?? '')) ?>:</strong> <?= htmlspecialchars((string) ($detailRow['value'] ?? '')) ?></div>
                                    <?php endforeach; ?>
                                    <?php if (count($tableDetailRows) > 3): ?><small>+<?= count($tableDetailRows) - 3 ?> more details</small><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#94a3b8;">No details added</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-priority"><?= htmlspecialchars($service['priority'] ?? 'Medium') ?></span></td>
                        <td><span class="badge badge-approved"><?= htmlspecialchars($service['status'] ?? 'Active') ?></span></td>
                        <td>
                            <div class="action-menu-shell">
                                <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <div class="action-menu-panel">
                                    <button type="button" class="action-menu-item" aria-label="View service" data-activity-modal-open="view-service-<?= (int)$service['id'] ?>">
                                        <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                        View
                                    </button>
                                    <button type="button" class="action-menu-item" aria-label="Configure workflow" data-activity-modal-open="workflow-service-<?= (int)$service['id'] ?>">
                                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                        Workflow
                                    </button>
                                    <button type="button" class="action-menu-item" aria-label="Edit service" data-activity-modal-open="edit-service-<?= (int)$service['id'] ?>">
                                        <i class="bi bi-pencil-fill" aria-hidden="true"></i>
                                        Edit
                                    </button>
                                    <form method="POST" action="service_management.php" onsubmit="return confirm('Delete this service? It will be removed from the department listing.');" style="margin: 0;">
                                        <input type="hidden" name="action" value="delete_service">
                                        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                                        <button type="submit" class="action-menu-item" style="color: #dc2626; width: 100%;" aria-label="Delete service">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <tr class="activity-modal-row"><td colspan="7"><div id="view-service-<?= (int)$service['id'] ?>" data-activity-modal style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100000; align-items: center; justify-content: center; padding: 1rem;">
                        <div style="background: white; border-radius: 12px; width: min(420px, 90%); padding: 1.5rem;">
                            <h3 style="margin-top: 0;">Service Details</h3>
                            <p><strong>Name:</strong> <?= htmlspecialchars($service['service_name']) ?></p>
                            <p><strong>Department:</strong> <?= htmlspecialchars($service['department_name'] ?? '—') ?></p>
                            <p><strong>Priority:</strong> <?= htmlspecialchars($service['priority'] ?? 'Medium') ?></p>
                            <p><strong>Status:</strong> <?= htmlspecialchars($service['status'] ?? 'Active') ?></p>
                            <p><strong>Description:</strong> <?= htmlspecialchars($service['description'] ?? '—') ?></p>
                            <?php $viewDetailRows = json_decode((string) ($service['detail_rows'] ?? ''), true); ?>
                            <?php if (is_array($viewDetailRows) && $viewDetailRows): ?>
                                <p><strong>Activity Details:</strong></p>
                                <table class="table" data-datatable="off" style="margin-bottom:1rem;"><thead><tr><th>Detail</th><th>Information</th></tr></thead><tbody>
                                    <?php foreach ($viewDetailRows as $detailRow): ?><tr><td><?= htmlspecialchars((string) ($detailRow['label'] ?? '')) ?></td><td><?= nl2br(htmlspecialchars((string) ($detailRow['value'] ?? ''))) ?></td></tr><?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                            <p><strong>Forms / Requirements:</strong><br>
                                <?php foreach ($serviceDocuments[(int) $service['id']] ?? [] as $serviceDocument): ?>
                                    <span style="display:inline-flex;align-items:center;gap:.5rem;margin:.2rem 0;">
                                        <a href="service_file_download.php?id=<?= (int) $serviceDocument['id'] ?>"><?= htmlspecialchars($serviceDocument['original_name']) ?></a>
                                        <form method="POST" action="service_management.php<?= $departmentFilter > 0 ? '?department_id=' . $departmentFilter : '' ?>" style="display:inline;margin:0;" onsubmit="return confirm('Delete this uploaded form or requirement file?');">
                                            <input type="hidden" name="action" value="delete_service_document">
                                            <input type="hidden" name="document_id" value="<?= (int) $serviceDocument['id'] ?>">
                                            <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                            <button type="submit" class="btn-action btn-action-danger" aria-label="Delete service file: <?= htmlspecialchars($serviceDocument['original_name'], ENT_QUOTES) ?>" title="Delete file"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                        </form>
                                    </span><br>
                                <?php endforeach; ?>
                                <?php if (empty($serviceDocuments[(int) $service['id']] ?? [])): ?>—<?php endif; ?>
                            </p>
                            <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
                                <button type="button" class="btn btn-light" title="Close details" aria-label="Close activity details" data-activity-modal-close><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </div></td></tr>

                    <tr class="activity-modal-row"><td colspan="7"><div id="edit-service-<?= (int)$service['id'] ?>" data-activity-modal style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100000; align-items: center; justify-content: center; padding: 1rem;">
                        <div class="activity-form-modal" style="background: white; border-radius: 12px; width: min(720px, 94vw); max-height: 90vh; overflow-y: auto; padding: 1.5rem;">
                            <h3 style="margin-top: 0;">Edit Service</h3>
                            <p style="color:#64748b;font-size:.85rem;">Update the activity details, client requirements, processing deadline, and reference form.</p>
                            <form method="POST" action="service_management.php" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_service">
                                <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                                <div class="form-group">
                                    <label for="edit_service_name_<?= (int)$service['id'] ?>">Service Name</label>
                                    <input id="edit_service_name_<?= (int)$service['id'] ?>" type="text" name="service_name" class="form-control activity-name-input" value="<?= htmlspecialchars($service['service_name']) ?>" minlength="5" maxlength="150" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_service_dept_<?= (int)$service['id'] ?>">Department</label>
                                    <select id="edit_service_dept_<?= (int)$service['id'] ?>" name="department_id" class="form-control" <?= $isDepartmentScopedUser ? 'disabled' : '' ?>>
                                        <?php foreach ($allDepartments as $department): ?>
                                            <option value="<?= (int)$department['id'] ?>" <?= ((int)$department['id'] === (int)($service['department_id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($isDepartmentScopedUser): ?><input type="hidden" name="department_id" value="<?= (int) $service['department_id'] ?>"><?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label for="edit_service_staff_<?= (int)$service['id'] ?>">Assigned Staff</label>
                                    <select id="edit_service_staff_<?= (int)$service['id'] ?>" name="assigned_user_id" class="form-control">
                                        <option value="0">All department staff</option>
                                        <?php foreach ($staffUsers as $staff): ?>
                                            <?php if ((int) $staff['department_id'] === (int) $service['department_id']): ?>
                                                <option value="<?= (int) $staff['id'] ?>" <?= ((int) ($service['assigned_user_id'] ?? 0) === (int) $staff['id']) ? 'selected' : '' ?>><?= htmlspecialchars($staff['name']) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_service_priority_<?= (int)$service['id'] ?>">Priority</label>
                                    <select id="edit_service_priority_<?= (int)$service['id'] ?>" name="priority" class="form-control">
                                        <option value="Low" <?= (($service['priority'] ?? 'Medium') === 'Low') ? 'selected' : '' ?>>Low</option>
                                        <option value="Medium" <?= (($service['priority'] ?? 'Medium') === 'Medium') ? 'selected' : '' ?>>Medium</option>
                                        <option value="High" <?= (($service['priority'] ?? 'Medium') === 'High') ? 'selected' : '' ?>>High</option>
                                        <option value="Urgent" <?= (($service['priority'] ?? 'Medium') === 'Urgent') ? 'selected' : '' ?>>Urgent</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_service_sla_<?= (int)$service['id'] ?>">Processing Deadline (Days)</label>
                                    <input id="edit_service_sla_<?= (int)$service['id'] ?>" type="number" name="sla_days" class="form-control" min="0" max="365" value="<?= (int) ($service['sla_days'] ?? 0) ?>">
                                    <small>Leave 0 for no deadline. Clients and staff will see overdue alerts after this period.</small>
                                </div>
                                <div class="form-group">
                                    <label for="edit_service_status_<?= (int)$service['id'] ?>">Status</label>
                                    <select id="edit_service_status_<?= (int)$service['id'] ?>" name="status" class="form-control">
                                        <option value="Active" <?= (($service['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>Active</option>
                                        <option value="Maintenance" <?= (($service['status'] ?? 'Active') === 'Maintenance') ? 'selected' : '' ?>>Maintenance</option>
                                        <option value="Inactive" <?= (($service['status'] ?? 'Active') === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_service_desc_<?= (int)$service['id'] ?>">Description</label>
                                    <textarea id="edit_service_desc_<?= (int)$service['id'] ?>" name="description" class="form-control" rows="3"><?= htmlspecialchars($service['description'] ?? '') ?></textarea>
                                </div>
                                <?php $editDetailRows = json_decode((string) ($service['detail_rows'] ?? ''), true); if (!is_array($editDetailRows) || !$editDetailRows) $editDetailRows = [['label' => '', 'value' => '']]; ?>
                                <div class="form-group detail-rows-editor">
                                    <label>Activity Details Table</label>
                                    <div data-detail-rows>
                                        <?php foreach ($editDetailRows as $detailIndex => $detailRow): ?><div class="detail-row">
                                            <input type="text" name="detail_label[]" class="form-control" placeholder="Requirement name" value="<?= htmlspecialchars((string) ($detailRow['label'] ?? '')) ?>">
                                            <input type="text" name="detail_value[]" class="form-control" placeholder="Instructions / information" value="<?= htmlspecialchars((string) ($detailRow['value'] ?? '')) ?>">
                                            <select name="detail_type[]" class="form-control"><option value="text" <?= (($detailRow['type'] ?? 'text') === 'text') ? 'selected' : '' ?>>Client text</option>
                                            <option value="file" <?= (($detailRow['type'] ?? 'text') === 'file') ? 'selected' : '' ?>>Client file</option></select><label class="detail-required">
                                                <input type="checkbox" name="detail_required[<?= $detailIndex ?>]" <?= !array_key_exists('required', $detailRow) || !empty($detailRow['required']) ? 'checked' : '' ?>> Required</label>
                                                <button type="button" class="btn btn-outline-danger" data-remove-detail title="Remove row"><i class="bi bi-x-lg"></i></button></div><?php endforeach; ?>
                                    </div>
                                    <button type="button" class="admin-secondary-button add-create-control" data-add-detail><i class="bi bi-plus-lg" aria-hidden="true"></i> Add detail row</button>
                                </div>
                                <div class="form-group">
                                    <label for="edit_service_file_<?= (int)$service['id'] ?>">Add Form / Requirement File</label>
                                    <input id="edit_service_file_<?= (int)$service['id'] ?>" type="file" name="service_file" class="form-control" accept=".pdf,.jpg,.jpeg">
                                    <small>Optional. PDF or JPG/JPEG up to 10 MB.</small>
                                </div>
                                <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                                    <button type="button" class="btn btn-light" title="Cancel and close" aria-label="Cancel and close activity editor" data-activity-modal-close><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                    <button type="submit" class="icon-circle-button primary" title="Save Changes" aria-label="Save changes"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                                </div>
                            </form>
                        </div>
                    </div></td></tr>

                    <tr class="activity-modal-row"><td colspan="7"><div id="workflow-service-<?= (int)$service['id'] ?>" data-activity-modal style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100000; align-items: center; justify-content: center; padding: 1rem;">
                        <div class="workflow-config-modal" style="background: white; border-radius: 12px; width: min(520px, 92%); max-height: 90vh; overflow-y: auto; padding: 1.5rem;">
                            <h3 style="margin-top: 0;">Configure <?= htmlspecialchars($service['service_name']) ?> Workflow</h3>
                            <p style="color:#64748b;">The Resident is added automatically as the final destination.</p>
                            <form method="POST" action="service_management.php" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="save_workflow">
                                <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
                                <?php $workflowRowsForService = $workflowByService[(int) $service['id']] ?? []; ?>
                                <div class="workflow-step-list" data-workflow-step-list>
                                <?php for ($workflowIndex = 0; $workflowIndex < max(3, count($workflowRowsForService)); $workflowIndex++): ?>
                                    <?php $selectedWorkflowDepartment = (int) ($workflowRowsForService[$workflowIndex]['department_id'] ?? 0); ?>
                                    <div class="form-group workflow-config-step">
                                        <label for="workflow_<?= (int) $service['id'] ?>_<?= $workflowIndex ?>">Step <?= $workflowIndex + 1 ?></label>
                                        <div class="workflow-step-control">
                                            <select id="workflow_<?= (int) $service['id'] ?>_<?= $workflowIndex ?>" name="workflow_department_id[]" class="form-control" <?= $workflowIndex === 0 ? 'required' : '' ?>>
                                                <option value="">No step</option>
                                                <?php foreach ($allDepartments as $department): ?>
                                                    <option value="<?= (int) $department['id'] ?>" <?= $selectedWorkflowDepartment === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($workflowIndex > 0): ?>
                                                <button type="button" class="btn btn-light" title="Remove step" aria-label="Remove step" data-remove-workflow-step><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                                </div>
                                <template data-workflow-step-template>
                                    <div class="form-group workflow-config-step">
                                        <label>Step</label>
                                        <div class="workflow-step-control">
                                            <select name="workflow_department_id[]" class="form-control">
                                                <option value="">No step</option>
                                                <?php foreach ($allDepartments as $department): ?>
                                                    <option value="<?= (int) $department['id'] ?>"><?= htmlspecialchars($department['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-light" title="Remove step" aria-label="Remove step" data-remove-workflow-step><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                </template>
                                <button type="button" class="admin-secondary-button add-create-control" data-add-workflow-step><i class="bi bi-plus-lg" aria-hidden="true"></i> Add department</button>
                                <div class="workflow-actions">
                                    <button type="button" class="btn btn-light" title="Cancel and close" aria-label="Cancel and close workflow editor" data-activity-modal-close><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                    <button type="submit" class="icon-circle-button primary" title="Save Workflow" aria-label="Save workflow"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                                </div>
                            </form>
                        </div>
                    </div></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="add-service-modal" data-activity-modal style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 100000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="activity-form-modal" style="background: white; border-radius: 12px; width: min(720px, 94vw); max-height: 90vh; overflow-y: auto; padding: 1.5rem;">
        <h3 style="margin-top: 0;">Add Service</h3>
        <form method="POST" action="service_management.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_service">
            <div class="form-group">
                <label for="add_service_name">Service Name</label>
                <input id="add_service_name" type="text" name="service_name" class="form-control activity-name-input" placeholder="e.g. PSA Birth Certificate Request, Scholarship Assistance" minlength="5" maxlength="150" required>
                <small>Enter the activity or service that clients may request.</small>
            </div>
            <div class="form-group">
                <label for="add_service_dept">Department</label>
                <select id="add_service_dept" name="department_id" class="form-control" <?= $isDepartmentScopedUser ? 'disabled' : '' ?>>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int)$department['id'] ?>" <?= (int) $department['id'] === ($isDepartmentScopedUser ? $ownDepartmentId : (int) ($departments[0]['id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Workflow Departments</label>
                <p style="color:#64748b;font-size:.8rem;">Choose the departments that will process this activity, in order. The activity department is always the first step, and Resident is added automatically as the final destination.</p>
                <div class="workflow-step-list" data-create-workflow-step-list>
                    <div class="form-group workflow-config-step">
                        <label for="create_workflow_0">Step 1</label>
                        <div class="workflow-step-control">
                            <select id="create_workflow_0" name="create_workflow_department_id[]" class="form-control" required>
                                <option value="">Select department</option>
                                <?php foreach ($allDepartments as $department): ?>
                                    <option value="<?= (int) $department['id'] ?>" <?= (int) $department['id'] === ($isDepartmentScopedUser ? $ownDepartmentId : (int) ($departments[0]['id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <template data-create-workflow-step-template>
                    <div class="form-group workflow-config-step">
                        <label>Step</label>
                        <div class="workflow-step-control">
                            <select name="create_workflow_department_id[]" class="form-control">
                                <option value="">Select department</option>
                                <?php foreach ($allDepartments as $department): ?>
                                    <option value="<?= (int) $department['id'] ?>"><?= htmlspecialchars($department['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-light" title="Remove department" aria-label="Remove workflow department" data-remove-create-workflow-step><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        </div>
                    </div>
                </template>
                <button type="button" class="admin-secondary-button add-create-control" data-add-create-workflow-step><i class="bi bi-plus-lg" aria-hidden="true"></i> Add department</button>
            </div>
            <div class="form-group">
                <label for="add_service_staff">Assigned Staff</label>
                <select id="add_service_staff" name="assigned_user_id" class="form-control">
                    <option value="0">All department staff</option>
                    <?php foreach ($staffUsers as $staff): ?>
                        <option value="<?= (int) $staff['id'] ?>"><?= htmlspecialchars($staff['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="add_service_priority">Priority</label>
                <select id="add_service_priority" name="priority" class="form-control">
                    <option value="Low">Low</option>
                    <option value="Medium" selected>Medium</option>
                    <option value="High">High</option>
                    <option value="Urgent">Urgent</option>
                </select>
            </div>
            <div class="form-group">
                <label for="add_service_status">Status</label>
                <select id="add_service_status" name="status" class="form-control">
                    <option value="Active" selected>Active</option>
                    <option value="Maintenance">Maintenance</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            <div class="form-group">
                <label for="add_service_sla">Processing Deadline (Days)</label>
                <input id="add_service_sla" type="number" name="sla_days" class="form-control" min="0" max="365" value="0">
                <small>Set the expected number of days to complete this activity. Use 0 for no deadline.</small>
            </div>
            <div class="form-group">
                <label for="add_service_desc">Description</label>
                <textarea id="add_service_desc" name="description" class="form-control" rows="3"></textarea>
            </div>
            <div class="form-group detail-rows-editor">
                <label>Activity Details Table</label>
                <p style="color:#64748b;font-size:.8rem;">Add any number of details, such as PSA requirements, processing time, fees, valid IDs, and release instructions.</p>
                <div data-detail-rows>
                    <div class="detail-row"><input type="text" name="detail_label[]" class="form-control" placeholder="Requirement name"><input type="text" name="detail_value[]" class="form-control" placeholder="Instructions / information">
                    <select name="detail_type[]" class="form-control"><option value="text">Client text</option>
                    <option value="file">Client file</option></select><label class="detail-required">
                    <input type="checkbox" name="detail_required[0]" checked> Required</label>
                    <button type="button" class="btn btn-outline-danger" data-remove-detail title="Remove row"><i class="bi bi-x-lg"></i></button></div>
                </div>
                <button type="button" class="admin-secondary-button add-create-control" data-add-detail><i class="bi bi-plus-lg" aria-hidden="true"></i> Add detail row</button>
            </div>
            <div class="form-group">
                <label for="add_service_file">Form / Requirement File</label>
                <input id="add_service_file" type="file" name="service_file" class="form-control" accept=".pdf,.jpg,.jpeg">
                <small>Optional. Upload the client form, checklist, sample document, or requirements file as PDF or JPG/JPEG up to 10 MB.</small>
            </div>
            <style>
            .detail-row{display:grid;grid-template-columns:1fr 1.5fr 1fr auto auto;gap:.5rem;margin-bottom:.5rem;align-items:center}.detail-required{display:flex;align-items:center;gap:.25rem;white-space:nowrap;color:#475569;font-size:.78rem}.detail-rows-editor [data-add-detail]{margin-top:.35rem}@media(max-width:600px){.detail-row{grid-template-columns:1fr}.detail-required{justify-self:start}}
            .activity-details-summary{min-width:180px;max-width:300px;color:#475569;font-size:.78rem;line-height:1.45}.activity-details-summary div{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.activity-details-summary strong{color:#1e293b}.activity-details-summary small{display:block;color:#2563eb;margin-top:.2rem}
            [data-create-workflow-step-list]{display:grid;gap:.75rem;max-height:55vh;overflow-y:auto;padding:.15rem .35rem .15rem 0}[data-create-workflow-step-list] .workflow-config-step{display:grid;gap:.35rem;margin:0;padding:.75rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}[data-create-workflow-step-list] .workflow-config-step>label{margin:0;color:#334155;font-size:.85rem;font-weight:700}[data-create-workflow-step-list] .workflow-step-control{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.6rem;align-items:center}[data-create-workflow-step-list] .workflow-step-control select{width:100%;min-width:0;min-height:42px;padding:.55rem 2rem .55rem .7rem;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#0f172a;font-size:.9rem}[data-create-workflow-step-list] [data-remove-create-workflow-step]{width:38px;min-width:38px;height:38px;padding:0;display:inline-flex;align-items:center;justify-content:center}[data-add-create-workflow-step]{margin-top:.35rem}@media(max-width:600px){[data-create-workflow-step-list] .workflow-config-step{padding:.65rem}}
            .workflow-config-modal h3{line-height:1.3;overflow-wrap:anywhere}.workflow-config-modal .workflow-step-list{display:grid;gap:.75rem;max-height:55vh;overflow-y:auto;padding:.15rem .35rem .15rem 0}.workflow-config-modal .workflow-config-step{display:grid;gap:.35rem;margin:0;padding:.75rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}.workflow-config-modal .workflow-config-step>label{margin:0;color:#334155;font-size:.85rem!important;font-weight:700}.workflow-config-modal .workflow-step-control{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.6rem;align-items:center}.workflow-config-modal .workflow-step-control select{display:block;width:100%;min-width:0;min-height:42px;padding:.55rem 2rem .55rem .7rem;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#0f172a;font-family:inherit!important;font-size:.9rem!important;white-space:normal}.workflow-config-modal .workflow-step-control [data-remove-workflow-step]{width:38px;min-width:38px;height:38px;min-height:38px;padding:0;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}.workflow-config-modal [data-add-workflow-step]{margin-top:.75rem}.workflow-config-modal .workflow-actions{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid #e2e8f0}@media(max-width:600px){.workflow-config-modal{padding:1rem!important}.workflow-config-modal .workflow-config-step{padding:.65rem}.workflow-config-modal .workflow-step-control{grid-template-columns:minmax(0,1fr) auto}.workflow-config-modal .workflow-step-control [data-remove-workflow-step]{justify-self:end}}
            </style>
            <script>
            (function () {
                var departmentSelect = document.getElementById('add_service_dept');
                var workflowList = document.querySelector('[data-create-workflow-step-list]');
                var workflowTemplate = document.querySelector('[data-create-workflow-step-template]');
                var firstWorkflowSelect = workflowList && workflowList.querySelector('select');
                var addWorkflowStepButton = document.querySelector('[data-add-create-workflow-step]');

                if (departmentSelect && firstWorkflowSelect) {
                    departmentSelect.addEventListener('change', function () {
                        firstWorkflowSelect.value = departmentSelect.value;
                    });
                }

                if (addWorkflowStepButton && workflowList && workflowTemplate) {
                    addWorkflowStepButton.addEventListener('click', function () {
                        var row = workflowTemplate.content.firstElementChild.cloneNode(true);
                        var index = workflowList.querySelectorAll('.workflow-config-step').length;
                        var select = row.querySelector('select');
                        var fieldId = 'create_workflow_' + index;
                        select.id = fieldId;
                        row.querySelector('label').textContent = 'Step ' + (index + 1);
                        row.querySelector('label').htmlFor = fieldId;
                        workflowList.appendChild(row);
                        select.focus();
                    });
                }

                document.addEventListener('click', function (event) {
                    var removeButton = event.target.closest('[data-remove-create-workflow-step]');
                    if (!removeButton) return;
                    var row = removeButton.closest('.workflow-config-step');
                    if (row) row.remove();
                    workflowList.querySelectorAll('.workflow-config-step').forEach(function (step, index) {
                        var label = step.querySelector('label');
                        var select = step.querySelector('select');
                        var fieldId = 'create_workflow_' + index;
                        label.textContent = 'Step ' + (index + 1);
                        label.htmlFor = fieldId;
                        select.id = fieldId;
                    });
                });
            })();

            document.addEventListener('click', function (event) {
                var openButton = event.target.closest('[data-activity-modal-open]');
                if (openButton) {
                    var modal = document.getElementById(openButton.dataset.activityModalOpen);
                    if (modal) {
                        if (modal.parentElement !== document.body) document.body.appendChild(modal);
                        modal.style.display = 'flex';
                    }
                    return;
                }

                var closeButton = event.target.closest('[data-activity-modal-close]');
                if (closeButton) {
                    var modalToClose = closeButton.closest('[data-activity-modal]');
                    if (modalToClose) modalToClose.style.display = 'none';
                    return;
                }

                if (event.target.matches('[data-activity-modal]')) {
                    event.target.style.display = 'none';
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    document.querySelectorAll('[data-activity-modal]').forEach(function (modal) {
                        modal.style.display = 'none';
                    });
                }
            });

            document.querySelectorAll('[data-add-workflow-step]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var form = button.closest('form');
                    var list = form.querySelector('[data-workflow-step-list]');
                    var template = form.querySelector('[data-workflow-step-template]');
                    var row = template.content.firstElementChild.cloneNode(true);
                    var index = list.querySelectorAll('.workflow-config-step').length;
                    var serviceId = form.querySelector('[name="service_id"]').value;
                    var select = row.querySelector('select');
                    var fieldId = 'workflow_' + serviceId + '_' + index;
                    select.id = fieldId;
                    row.querySelector('label').htmlFor = fieldId;
                    list.appendChild(row);
                    list.scrollTop = list.scrollHeight;
                    select.focus();
                });
            });
            document.addEventListener('click', function (event) {
                if (event.target.matches('[data-remove-workflow-step]')) {
                    var row = event.target.closest('.workflow-config-step');
                    var list = row.closest('[data-workflow-step-list]');
                    row.remove();
                    list.querySelectorAll('.workflow-config-step').forEach(function (step, index) {
                        var label = step.querySelector('label');
                        var select = step.querySelector('select');
                        var fieldId = 'workflow_' + select.closest('form').querySelector('[name="service_id"]').value + '_' + index;
                        label.textContent = 'Step ' + (index + 1);
                        label.htmlFor = fieldId;
                        select.id = fieldId;
                    });
                }
            });
            document.querySelectorAll('[data-add-detail]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var rows = button.previousElementSibling;
                    var row = document.createElement('div');
                    row.className = 'detail-row';
                    var index = rows.children.length;
                    row.innerHTML = '<input type="text" name="detail_label[]" class="form-control" placeholder="Requirement name"><input type="text" name="detail_value[]" class="form-control" placeholder="Instructions / information"><select name="detail_type[]" class="form-control"><option value="text">Client text</option><option value="file">Client file</option></select><label class="detail-required"><input type="checkbox" name="detail_required[' + index + ']" checked> Required</label><button type="button" class="btn btn-outline-danger" data-remove-detail title="Remove row"><i class="bi bi-x-lg"></i></button>';
                    rows.appendChild(row);
                });
            });
            document.addEventListener('click', function (event) {
                if (event.target.matches('[data-remove-detail]')) {
                    var row = event.target.closest('.detail-row');
                    var container = row.parentElement;
                    if (container.children.length > 1) row.remove();
                    else row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                }
            });
            function validateActivityNameInput(input) {
                var value = input.value.trim();
                var lettersOnly = value.replace(/[^\p{L}]/gu, '');
                var words = value.match(/[\p{L}]+/gu) || [];
                var vowelCount = (lettersOnly.match(/[aeiou]/gi) || []).length;
                var isShortAcronym = /^[A-Z]{3,10}$/.test(value.replace(/\s/g, ''));
                var hasLongConsonantRun = /[bcdfghjklmnpqrstvwxyz]{5,}/iu.test(lettersOnly);
                var message = '';
                if (value.length < 5 || value.length > 150 || lettersOnly.length < 5) {
                    message = 'Service name must be between 5 and 150 characters and contain at least 5 letters.';
                } else if (!/[\p{L}]/u.test(value)) {
                    message = 'Activity name must contain letters and cannot be numbers only.';
                } else if ((!isShortAcronym && (words.length < 2 || vowelCount < 2))
                    || hasLongConsonantRun
                    || /(.)(\1){2}/iu.test(lettersOnly)
                    || /(?:qwerty|asdf|zxcv|qaz|wsx)/i.test(lettersOnly)) {
                    message = 'Please enter a meaningful service name, such as "Birth Certificate Request".';
                }
                input.setCustomValidity(message);
                return message === '';
            }
            document.querySelectorAll('.activity-name-input').forEach(function (input) {
                input.addEventListener('input', function () {
                    validateActivityNameInput(input);
                });
                input.form.addEventListener('submit', function (event) {
                    if (!validateActivityNameInput(input)) {
                        event.preventDefault();
                        input.reportValidity();
                    }
                });
            });
            </script>
            <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                <button type="button" class="btn btn-light" title="Cancel and close" aria-label="Cancel and close activity form" data-activity-modal-close><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                <button type="submit" class="icon-circle-button primary" title="Save Service" aria-label="Save service">✓</button>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
