<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/notifications.php';

requireLogin();

$user = currentUser();
$canManageAnnouncements = hasAnyPermission(['manage_announcements', 'manage_settings'], $user);
$canPostAnnouncements = $canManageAnnouncements
    || (isDepartmentAdmin($user) && empty($user['custom_role_id']));

$successMsg = '';
$errorMsg = '';
$selectedCategory = 'General Announcement';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT IGNORE INTO announcement_categories (name) VALUES
        ('Executive Memo'),
        ('Work Suspension'),
        ('Public Advisory'),
        ('Department Notice'),
        ('General Announcement')");
} catch (Exception $e) {
    error_log('Failed to initialize announcement_categories: ' . $e->getMessage());
    throw $e;
}

// Handle POST actions (Create Announcement / Delete / Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_announcement_category' && !$canManageAnnouncements) {
        $errorMsg = 'You do not have permission to add an announcement category.';
    } elseif ($action === 'add_announcement_category') {
        $categoryName = trim($_POST['category_name'] ?? '');
        if ($categoryName === '' || mb_strlen($categoryName) > 50) {
            $errorMsg = 'Please enter a category that does not exceed 50 characters.';
        } else {
            try {
                $categoryStmt = $pdo->prepare('INSERT INTO announcement_categories (name) VALUES (:name)');
                $categoryStmt->execute(['name' => $categoryName]);
                $selectedCategory = $categoryName;
                $successMsg = 'Announcement category added.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $categoryExistsStmt = $pdo->prepare('SELECT name FROM announcement_categories WHERE name = :name');
                    $categoryExistsStmt->execute(['name' => $categoryName]);
                    $existingCategory = $categoryExistsStmt->fetchColumn();
                    if ($existingCategory !== false) {
                        $selectedCategory = (string) $existingCategory;
                        $errorMsg = 'This category is already on the list.';
                    } else {
                        error_log('Failed to add announcement category: ' . $e->getMessage());
                        $errorMsg = 'The announcement category could not be added. Please try again.';
                    }
                } else {
                    throw $e;
                }
            }
        }
    } elseif ($action === 'create_announcement' && !$canPostAnnouncements) {
        $errorMsg = 'You do not have permission to post an announcement.';
    } elseif ($action === 'create_announcement') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? 'General Announcement');
        $selectedCategory = $category;
        $deptId = !empty($user['department_id']) ? (int)$user['department_id'] : null;
        $targetRole = ($_POST['target_role'] ?? 'all') === 'department_admin' ? 'department_admin' : 'all';

        $categoryCheck = $pdo->prepare('SELECT COUNT(*) FROM announcement_categories WHERE name = :name');
        $categoryCheck->execute(['name' => $category]);
        if (empty($title) || empty($content)) {
            $errorMsg = 'Please enter the title and content of the announcement.';
        } elseif ($category === '' || (int) $categoryCheck->fetchColumn() === 0) {
            $errorMsg = 'Please select a valid announcement category.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO announcements (title, content, category, posted_by, department_id, target_role, is_active) VALUES (:title, :content, :category, :posted_by, :department_id, :target_role, 1)");
                $stmt->execute([
                    'title' => $title,
                    'content' => $content,
                    'category' => $category,
                    'posted_by' => $user['id'],
                    'department_id' => $deptId,
                    'target_role' => $targetRole
                ]);

                // Create system notifications for users
                $annId = $pdo->lastInsertId();
                $recipientStmt = $targetRole === 'department_admin'
                    ? $pdo->query("SELECT id FROM users WHERE role = 'department_admin' AND account_status = 'active'")
                    : $pdo->query("SELECT id FROM users WHERE account_status = 'active'");
                $allUsers = $recipientStmt->fetchAll();
                foreach ($allUsers as $u) {
                    if ((int)$u['id'] !== (int)$user['id']) {
                        sendUserNotification($pdo, $u['id'], "ANNOUNCEMENT-{$annId}", "📣 New Announcement: '{$title}' ({$category})");
                    }
                }

                $successMsg = 'Announcement successfully posted to all active users.';
            } catch (Exception $e) {
                $errorMsg = 'Error posting announcement: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_announcement') {
        $annId = (int)($_POST['announcement_id'] ?? 0);
        if ($annId > 0) {
            // Check ownership or super admin
            $ann = $pdo->prepare("SELECT posted_by FROM announcements WHERE id = :id");
            $ann->execute(['id' => $annId]);
            $item = $ann->fetch();

            if ($item && ($canManageAnnouncements || (int)$item['posted_by'] === (int)$user['id'])) {
                $pdo->prepare("UPDATE announcements SET deleted_at = NOW(), deleted_by = :deleted_by, deleted_reason = :deleted_reason WHERE id = :id")
                    ->execute([
                        'deleted_by' => (int) $user['id'],
                        'deleted_reason' => 'Removed from Announcements',
                        'id' => $annId,
                    ]);
                $successMsg = 'The announcement has been removed.';
            } else {
                $errorMsg = 'You do not have permission to delete this announcement.';
            }
        }
    } elseif ($action === 'toggle_status') {
        $annId = (int)($_POST['announcement_id'] ?? 0);
        if ($annId > 0 && $canManageAnnouncements) {
            $pdo->prepare("UPDATE announcements SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id")->execute(['id' => $annId]);
            $successMsg = 'Announcement status updated.';
        }
    }
}

$categoryRows = $pdo->query('SELECT name FROM announcement_categories ORDER BY name ASC')->fetchAll();
$announcementCategories = array_column($categoryRows, 'name');

// Fetch active announcements
$query = "SELECT a.*, u.name as poster_name, d.name as dept_name 
          FROM announcements a 
          LEFT JOIN users u ON a.posted_by = u.id 
          LEFT JOIN departments d ON a.department_id = d.id 
          WHERE a.deleted_at IS NULL
          ORDER BY a.created_at DESC";
$announcements = $pdo->query($query)->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<div class="content-wrapper announcements-page">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 0.6rem;">
                📣 System Announcements & Advisories
            </h1>
            <p style="color: #64748b; margin: 0.25rem 0 0 0; font-size: 0.9rem;">
                View official announcements from the municipality and various departments.
            </p>
        </div>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success" style="background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem;">
            ✓ <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem;">
            ⚠️ <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($canPostAnnouncements): ?>
        <!-- ANNOUNCEMENT CREATION FORM -->
        <div class="card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
            <h3 style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                <span>✍️</span> Post a New Announcement / Announcement Form
            </h3>
            <form method="POST" action="announcements.php">
                <input type="hidden" name="action" value="create_announcement">
                <div class="announcement-form-grid">
                    <div>
                        <label for="announcement-title" style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem;">Title</label>
                        <input id="announcement-title" type="text" name="title" required placeholder="Ex. Executive Order No. 5 - Work Suspension Notice" style="width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.92rem;">
                    </div>
                    <div>
                        <label for="announcement-category" style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem;">Category</label>
                        <div class="announcement-category-control">
                            <select id="announcement-category" name="category" required>
                                <?php foreach ($announcementCategories as $categoryOption): ?>
                                    <option value="<?= htmlspecialchars($categoryOption) ?>" <?= $selectedCategory === $categoryOption ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($canManageAnnouncements): ?>
                                <button type="button" class="announcement-add-category-button add-create-control" aria-label="Add announcement category" title="Add category" onclick="document.getElementById('add-announcement-category-modal').style.display='flex'; document.getElementById('announcement-category-name').focus();">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                    <span>Add Category</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem;">Audience</label>
                    <select name="target_role" style="width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.92rem; background: white;">
                        <option value="all">📣 All active users</option>
                        <option value="department_admin">🛡️ All Department Admins</option>
                    </select>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label for="announcement-content" style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem;">Announcement Details / Content</label>
                    <textarea id="announcement-content" name="content" rows="4" required placeholder="Write the full details of the announcement here..." style="width: 100%; padding: 0.65rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.92rem; line-height: 1.5;"></textarea>
                </div>

                <button type="submit" class="icon-circle-button primary announcement-post-button" aria-label="Post announcement">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                </button>
            </form>
        </div>
        <?php if ($canManageAnnouncements): ?>
            <div id="add-announcement-category-modal" class="announcement-category-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="add-announcement-category-title">
                <div class="announcement-category-modal-card">
                    <h3 id="add-announcement-category-title">Add Announcement Category</h3>
                    <form method="POST" action="announcements.php">
                        <input type="hidden" name="action" value="add_announcement_category">
                        <label for="announcement-category-name">Category name</label>
                        <input id="announcement-category-name" name="category_name" type="text" maxlength="50" required>
                        <div class="announcement-category-modal-actions">
                            <button type="button" class="admin-secondary-button" onclick="document.getElementById('add-announcement-category-modal').style.display='none';">Cancel</button>
                            <button type="submit" class="admin-primary-button add-create-control"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add category</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ANNOUNCEMENTS LIST FEED -->
    <h3 style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin-bottom: 1rem;">
        Posted Announcements (<?= count($announcements) ?>)
    </h3>

    <?php if (empty($announcements)): ?>
        <div class="card" style="text-align: center; padding: 3rem 1.5rem; color: #94a3b8; background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
            <span style="font-size: 2.5rem; display: block; margin-bottom: 0.5rem;">📭</span>
            <p style="margin: 0; font-size: 0.95rem;">There are no announcements posted at the moment.</p>
        </div>
    <?php else: ?>
        <div class="announcement-table-card">
            <table class="table announcement-data-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Title</th>
                        <th>Details</th>
                        <th>Posted By</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($announcements as $ann):
                        $isPosterOrAdmin = $canManageAnnouncements || (int)$ann['posted_by'] === (int)$user['id'];
                    ?>
                        <tr>
                            <td data-label="Category"><?= htmlspecialchars($ann['category']) ?></td>
                            <td data-label="Title"><strong><?= htmlspecialchars($ann['title']) ?></strong></td>
                            <td data-label="Details"><?= nl2br(htmlspecialchars($ann['content'])) ?></td>
                            <td data-label="Posted By"><?= htmlspecialchars($ann['poster_name'] ?? 'System Admin') ?></td>
                            <td data-label="Date"><?= date('m/d/Y h:i A', strtotime($ann['created_at'])) ?></td>
                            <td data-label="Status">
                                <span class="badge <?= $ann['is_active'] ? 'badge-approved' : 'badge-secondary' ?>">
                                    <?= $ann['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td data-label="Actions">
                                <?php if ($isPosterOrAdmin): ?>
                                    <div class="action-menu-shell announcement-action-menu">
                                        <button type="button" class="action-more-toggle" aria-label="More actions" title="More actions" aria-expanded="false">
                                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                        </button>
                                        <div class="action-menu-panel">
                                            <?php if ($canManageAnnouncements): ?>
                                                <form method="POST" action="announcements.php">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                                    <button type="submit" class="action-menu-item">
                                                        <?= $ann['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" action="announcements.php" class="delete-announcement-form">
                                                <input type="hidden" name="action" value="delete_announcement">
                                                <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                                <button type="button" class="action-menu-item" data-confirm-delete>Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
