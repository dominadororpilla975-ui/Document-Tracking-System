<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';

requireLogin();
$user = currentUser();
requirePermission('manage_departments', $user);

$error = '';
$success = '';
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$allowedStatuses = ['active', 'inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_department') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['department_code'] ?? '');
        $email = trim($_POST['official_email'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        if ($name === '' || !in_array($status, $allowedStatuses, true) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $error = 'Enter a department name, a valid status, and a valid email address.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO departments (name, department_code, official_email, status) VALUES (:name, :code, :email, :status)');
                $stmt->execute([
                    'name' => $name,
                    'code' => $code !== '' ? $code : null,
                    'email' => $email !== '' ? $email : null,
                    'status' => $status,
                ]);
                $success = 'Department created successfully.';
            } catch (PDOException $exception) {
                if ((int) $exception->errorInfo[1] === 1062) {
                    $error = 'The department name or department code already exists.';
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'update_department') {
        $id = (int) ($_POST['department_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['department_code'] ?? '');
        $email = trim($_POST['official_email'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        if ($id > 0 && $name !== '' && in_array($status, $allowedStatuses, true) && ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL))) {
            try {
                $stmt = $pdo->prepare('UPDATE departments SET name = :name, department_code = :code, official_email = :email, status = :status WHERE id = :id');
                $stmt->execute([
                    'name' => $name,
                    'code' => $code !== '' ? $code : null,
                    'email' => $email !== '' ? $email : null,
                    'status' => $status,
                    'id' => $id,
                ]);
                $success = 'Department updated successfully.';
            } catch (PDOException $exception) {
                if ((int) $exception->errorInfo[1] === 1062) {
                    $error = 'The department name or department code already exists.';
                } else {
                    throw $exception;
                }
            }
        } else {
            $error = 'Department data is invalid.';
        }
    } elseif ($action === 'delete_department') {
        $id = (int) ($_POST['department_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('UPDATE departments SET deleted_at = NOW(), deleted_by = :deleted_by, deleted_reason = :deleted_reason WHERE id = :id AND deleted_at IS NULL');
                $stmt->execute([
                    'id' => $id,
                    'deleted_by' => (int) $user['id'],
                    'deleted_reason' => 'Moved to Trash Bin by administrator',
                ]);
                $success = $stmt->rowCount() > 0 ? 'Department moved to Trash Bin.' : 'Department was not found.';
            } catch (PDOException $exception) {
                $error = 'This department cannot be deleted because it is still linked to users or documents. Set it to inactive instead.';
            }
        } else {
            $error = 'Invalid department selected.';
        }
    }
}

$where = ['d.deleted_at IS NULL'];
$params = [];
if ($search !== '') {
    $where[] = '(d.name LIKE :department_search_name OR d.department_code LIKE :department_search_code OR d.official_email LIKE :department_search_email)';
    $searchValue = '%' . $search . '%';
    $params['department_search_name'] = $searchValue;
    $params['department_search_code'] = $searchValue;
    $params['department_search_email'] = $searchValue;
}
if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'd.status = :status';
    $params['status'] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$departmentQuery = $pdo->prepare(
    'SELECT d.*, COUNT(u.id) AS assigned_user_count
     FROM departments d
     LEFT JOIN users u ON u.department_id = d.id
     ' . $whereSql . '
     GROUP BY d.id
     ORDER BY d.name ASC'
);
$departmentQuery->execute($params);
$departments = $departmentQuery->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<div class="management-toolbar">
    <div>
        <h1>Department Management</h1>
        <p style="color: #64748b; margin: 0;">Manage municipal departments, office codes, and assigned administration.</p>
    </div>
    <button type="button" class="admin-primary-button add-create-control" title="Add Department" aria-label="Add department" onclick="document.getElementById('add-department-modal').style.display='flex';"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Department</button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table" data-datatable-search="true" data-datatable-paging="true" data-datatable-ordering="true" data-datatable-info="true">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Code</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Assigned Users</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($dept['name']) ?></strong></td>
                        <td><?= htmlspecialchars($dept['department_code'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($dept['official_email'] ?? '—') ?></td>
                        <td><span class="badge badge-approved"><?= htmlspecialchars(ucfirst($dept['status'] ?? 'active')) ?></span></td>
                        <td><?= (int) $dept['assigned_user_count'] ?></td>
                        <td>
                            <div class="action-menu-shell">
                                <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <div class="action-menu-panel">
                                    <button type="button" class="action-menu-item" aria-label="View department" data-department-modal-open="view-department-<?= (int)$dept['id'] ?>">
                                        <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                        View
                                    </button>
                                    <button type="button" class="action-menu-item" aria-label="Edit department" data-department-modal-open="edit-department-<?= (int)$dept['id'] ?>">
                                        <i class="bi bi-pencil-fill" aria-hidden="true"></i>
                                        Edit
                                    </button>
                                    <form method="POST" action="department_management.php" data-confirmation-icon-color="blue" onsubmit="return confirm('Move this department to the Trash Bin?');" style="margin: 0;">
                                        <input type="hidden" name="action" value="delete_department">
                                        <input type="hidden" name="department_id" value="<?= (int)$dept['id'] ?>">
                                        <button type="submit" class="action-menu-item" style="color: #dc2626; width: 100%;" aria-label="Delete department">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <div id="view-department-<?= (int)$dept['id'] ?>" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 999; align-items: center; justify-content: center;">
                        <div style="background: white; border-radius: 12px; width: min(420px, 90%); padding: 1.5rem;">
                            <h3 style="margin-top: 0;">Department Details</h3>
                            <p><strong>Name:</strong> <?= htmlspecialchars($dept['name']) ?></p>
                            <p><strong>Code:</strong> <?= htmlspecialchars($dept['department_code'] ?? '—') ?></p>
                            <p><strong>Email:</strong> <?= htmlspecialchars($dept['official_email'] ?? '—') ?></p>
                            <p><strong>Status:</strong> <?= htmlspecialchars(ucfirst($dept['status'] ?? 'active')) ?></p>
                            <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
                                <button type="button" class="btn btn-light" title="Close" aria-label="Close" onclick="document.getElementById('view-department-<?= (int)$dept['id'] ?>').style.display='none';">
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="edit-department-<?= (int)$dept['id'] ?>" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 999; align-items: center; justify-content: center;">
                        <div style="background: white; border-radius: 12px; width: min(500px, 90%); padding: 1.5rem;">
                            <h3 style="margin-top: 0;">Edit Department</h3>
                            <form method="POST" action="department_management.php">
                                <input type="hidden" name="action" value="update_department">
                                <input type="hidden" name="department_id" value="<?= (int)$dept['id'] ?>">
                                <div class="form-group">
                                    <label for="edit_name_<?= (int)$dept['id'] ?>">Department Name</label>
                                    <input id="edit_name_<?= (int)$dept['id'] ?>" type="text" name="name" class="form-control" value="<?= htmlspecialchars($dept['name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_code_<?= (int)$dept['id'] ?>">Code</label>
                                    <input id="edit_code_<?= (int)$dept['id'] ?>" type="text" name="department_code" class="form-control" value="<?= htmlspecialchars($dept['department_code'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="edit_email_<?= (int)$dept['id'] ?>">Official Email</label>
                                    <input id="edit_email_<?= (int)$dept['id'] ?>" type="email" name="official_email" class="form-control" value="<?= htmlspecialchars($dept['official_email'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="edit_status_<?= (int)$dept['id'] ?>">Status</label>
                                    <select id="edit_status_<?= (int)$dept['id'] ?>" name="status" class="form-control">
                                        <option value="active" <?= (($dept['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= (($dept['status'] ?? 'active') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                                    <button type="button" class="icon-circle-button secondary" title="Cancel" aria-label="Cancel" onclick="document.getElementById('edit-department-<?= (int)$dept['id'] ?>').style.display='none';">✕</button>
                                    <button type="submit" class="icon-circle-button primary" title="Save Changes" aria-label="Save changes">✓</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($departments)): ?>
                    <tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 2rem;">No departments match the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="add-department-modal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 999; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; width: min(500px, 90%); padding: 1.5rem;">
        <h3 style="margin-top: 0;">Add Department</h3>
        <form method="POST" action="department_management.php">
            <input type="hidden" name="action" value="create_department">
            <div class="form-group">
                <label for="add_name">Department Name</label>
                <input id="add_name" type="text" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="add_code">Department Code</label>
                <input id="add_code" type="text" name="department_code" class="form-control">
            </div>
            <div class="form-group">
                <label for="add_email">Official Email</label>
                <input id="add_email" type="email" name="official_email" class="form-control">
            </div>
            <div class="form-group">
                <label for="add_status">Status</label>
                <select id="add_status" name="status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;">
                <button type="button" class="icon-circle-button secondary" title="Cancel" aria-label="Cancel" onclick="document.getElementById('add-department-modal').style.display='none';">✕</button>
                <button type="submit" class="icon-circle-button primary" title="Save Department" aria-label="Save department">✓</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    var actionItem = event.target.closest('.action-menu-panel .action-menu-item');
    if (actionItem) {
        var menu = actionItem.closest('.action-menu-shell');
        if (menu) {
            menu.classList.remove('is-open');
            var toggle = menu.querySelector('.action-more-toggle');
            var panel = menu.querySelector('.action-menu-panel');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
            if (panel) {
                ['display', 'visibility', 'position', 'top', 'bottom', 'left', 'right', 'zIndex'].forEach(function (property) {
                    panel.style[property] = '';
                });
            }
        }
    }

    var openButton = event.target.closest('[data-department-modal-open]');
    if (openButton) {
        var modal = document.getElementById(openButton.dataset.departmentModalOpen);
        if (modal) modal.style.display = 'flex';
        return;
    }

    if (event.target.matches('[id^="view-department-"], [id^="edit-department-"], #add-department-modal')) {
        event.target.style.display = 'none';
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('[id^="view-department-"], [id^="edit-department-"], #add-department-modal').forEach(function (modal) {
            modal.style.display = 'none';
        });
    }
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
