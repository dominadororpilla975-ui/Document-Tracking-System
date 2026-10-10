<?php
require_once __DIR__ . '/../config/db.php';

// Auto-create announcements table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'Executive Memo',
    posted_by INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Check if any announcement exists, if not, seed default Executive Memo ("Walang Pasok")
$checkStmt = $pdo->query("SELECT COUNT(*) FROM announcements");
if ($checkStmt->fetchColumn() == 0) {
    $seedStmt = $pdo->prepare("INSERT INTO announcements (title, content, category, is_active) VALUES 
        (:title, :content, :category, 1)");
    $seedStmt->execute([
        'title' => 'EXECUTIVE MEMORANDUM NO. 2026-08: SUSPENSION OF WORK IN ALL MUNICIPAL OFFICES (WALANG PASOK)',
        'content' => 'Pursuant to Presidential Proclamation & Municipal Executive Advisory, work in all Municipal Government Offices and Departments in Carranglan, Nueva Ecija is hereby SUSPENDED on July 24, 2026 due to inclement weather and disaster risk reduction measures. All Department Heads are advised to ensure skeletal workforce for emergency response.',
        'category' => 'Work Suspension'
    ]);
}

function getActiveAnnouncements(?array $user = null): array {
    global $pdo;
    $where = ['a.is_active = 1', 'a.deleted_at IS NULL'];
    $params = [];
    if ($user !== null) {
        $role = strtolower((string) ($user['role'] ?? ''));
        $where[] = "(a.target_role IS NULL OR a.target_role = 'all' OR a.target_role = :target_role)";
        $params['target_role'] = $role;
    }
    $stmt = $pdo->prepare("SELECT a.*, u.name as poster_name 
                        FROM announcements a 
                        LEFT JOIN users u ON a.posted_by = u.id 
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY a.created_at DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}
