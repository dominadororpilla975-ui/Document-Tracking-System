<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/tracking.php';

try {
    // 1. Ensure departments table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Upgrade departments table: Add department_code, official_email, status
    $deptCols = $pdo->query("DESCRIBE departments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('department_code', $deptCols)) {
        $pdo->exec("ALTER TABLE departments ADD COLUMN department_code VARCHAR(50) UNIQUE NULL AFTER name");
    }
    if (!in_array('official_email', $deptCols)) {
        $pdo->exec("ALTER TABLE departments ADD COLUMN official_email VARCHAR(100) NULL AFTER department_code");
    }
    if (!in_array('status', $deptCols)) {
        $pdo->exec("ALTER TABLE departments ADD COLUMN status VARCHAR(50) DEFAULT 'active' AFTER official_email");
    }
    if (!in_array('deleted_at', $deptCols)) {
        $pdo->exec("ALTER TABLE departments ADD COLUMN deleted_at DATETIME NULL AFTER status");
    }
    if (!in_array('deleted_by', $deptCols)) {
        $pdo->exec("ALTER TABLE departments ADD COLUMN deleted_by INT NULL AFTER deleted_at");
    }
    if (!in_array('deleted_reason', $deptCols)) {
        $pdo->exec("ALTER TABLE departments ADD COLUMN deleted_reason VARCHAR(255) NULL AFTER deleted_by");
    }

    // 2. Ensure RBAC tables exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        permission_name VARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_role_permission (role_id, permission_name),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS department_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        assigned_user_id INT NULL,
        service_name VARCHAR(150) NOT NULL,
        description TEXT NULL,
        status VARCHAR(50) DEFAULT 'Active',
        priority VARCHAR(50) DEFAULT 'Medium',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
    )");
    $serviceColumns = $pdo->query("DESCRIBE department_services")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('detail_rows', $serviceColumns, true)) {
        $pdo->exec("ALTER TABLE department_services ADD COLUMN detail_rows TEXT NULL AFTER description");
    }
    if (!in_array('sla_days', $serviceColumns, true)) {
        $pdo->exec("ALTER TABLE department_services ADD COLUMN sla_days INT NULL AFTER detail_rows");
    }
    $reportColumns = $pdo->query("DESCRIBE reports")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tracking_number', $reportColumns, true)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN tracking_number VARCHAR(50) NULL");
    }
    if (!in_array('activity_details_answers', $reportColumns, true)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN activity_details_answers TEXT NULL AFTER additional_information");
    }
    if (!in_array('submission_mode', $reportColumns, true)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN submission_mode VARCHAR(20) NOT NULL DEFAULT 'online' AFTER client_email");
    }
    if (!in_array('submission_source', $reportColumns, true)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN submission_source VARCHAR(30) NOT NULL DEFAULT 'client' AFTER submission_mode");
    }
    $userColumns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('deleted_at', $userColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL");
    }
    if (!in_array('deleted_by', $userColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN deleted_by INT NULL");
    }
    if (!in_array('deleted_reason', $userColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN deleted_reason VARCHAR(255) NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_workflow_steps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_id INT NOT NULL,
        step_number INT NOT NULL,
        department_id INT NOT NULL,
        required_action VARCHAR(100) DEFAULT 'Process',
        status VARCHAR(50) DEFAULT 'Active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_service_step (service_id, step_number),
        FOREIGN KEY (service_id) REFERENCES department_services(id) ON DELETE CASCADE,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
        INDEX idx_workflow_service (service_id),
        INDEX idx_workflow_department (department_id)
    )");


    $defaultRoles = [
        ['name' => 'Client', 'slug' => 'client', 'description' => 'Citizen users who submit and track reports.'],
        ['name' => 'Department Staff', 'slug' => 'department_staff', 'description' => 'Staff members handling assigned documents.'],
        ['name' => 'Department Admin', 'slug' => 'department_admin', 'description' => 'Department head or delegated admin for routing and approvals.'],
        ['name' => 'Super Admin', 'slug' => 'super_admin', 'description' => 'System-level administrator with full oversight.'],
    ];

    foreach ($defaultRoles as $role) {
        $check = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
        $check->execute(['slug' => $role['slug']]);
        if (!$check->fetch()) {
            $ins = $pdo->prepare('INSERT INTO roles (name, slug, description) VALUES (:name, :slug, :description)');
            $ins->execute($role);
        }
    }

    $permissionDefaults = [
        'view_dashboard',
        'view_documents',
        'create_reports',
        'manage_reports',
        'view_workflow',
        'view_archive',
        'view_trash_bin',
        'view_reports',
        'route_reports',
        'approve_reports',
        'manage_users',
        'create_department_staff',
        'manage_departments',
        'manage_services',
        'manage_roles',
        'manage_settings',
        'manage_landing_settings',
        'manage_contact_settings',
        'manage_maintenance_settings',
        'manage_retention_settings',
        'manage_sidebar_settings',
        'view_audit_logs',
        'manage_notifications',
        'manage_announcements',
        'manage_security_settings',
        'export_reports',
        'permanent_delete',
        'manage_account_status',
        'override_workflow',
        'override_department_privacy',
        'manage_file_revisions',
        'complete_own_documents',
    ];
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $unusedBackupPermissionMigration = $pdo->prepare(
        "INSERT IGNORE INTO schema_migrations (migration_key) VALUES ('remove_unused_manage_backups_permission')"
    );
    $unusedBackupPermissionMigration->execute();
    if ($unusedBackupPermissionMigration->rowCount() === 1) {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_name = 'manage_backups'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        recipient VARCHAR(254) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at DATETIME NULL,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        INDEX idx_email_outbox_ready (status, available_at, id),
        INDEX idx_email_outbox_locked (status, locked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $permissionCount = (int) $pdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn();
    if ($permissionCount === 0) {
        $rolePermissionStmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_name)
             SELECT id, :permission_name FROM roles WHERE slug IN (:role_slug, :admin_slug)'
        );
        foreach ($permissionDefaults as $permission) {
            $rolePermissionStmt->execute([
                'permission_name' => $permission,
                'role_slug' => 'super_admin',
                'admin_slug' => 'admin',
            ]);
        }
        $departmentAdminPermissions = ['view_dashboard', 'view_documents', 'create_reports', 'manage_reports', 'route_reports', 'approve_reports', 'manage_notifications', 'manage_users', 'create_department_staff', 'manage_departments', 'manage_file_revisions'];
        $staffPermissions = ['view_dashboard', 'view_documents', 'manage_reports', 'manage_notifications'];
        $clientPermissions = ['view_dashboard', 'view_documents', 'create_reports', 'manage_notifications', 'manage_file_revisions', 'complete_own_documents'];
        $rolePermissionInsert = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_name)
             SELECT id, :permission_name FROM roles WHERE slug = :role_slug'
        );
        foreach ([
            'department_admin' => $departmentAdminPermissions,
            'department_staff' => $staffPermissions,
            'client' => $clientPermissions,
        ] as $roleSlug => $permissions) {
            foreach ($permissions as $permission) {
                $rolePermissionInsert->execute([
                    'role_slug' => $roleSlug,
                    'permission_name' => $permission,
                ]);
            }
        }
    }
    $granularRolePermissionsMigration = $pdo->prepare(
        "INSERT IGNORE INTO schema_migrations (migration_key) VALUES ('granular_role_menu_permissions')"
    );
    $granularRolePermissionsMigration->execute();
    if ($granularRolePermissionsMigration->rowCount() === 1) {
        $copyLegacyPermission = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_name)
             SELECT role_id, :new_permission FROM role_permissions WHERE permission_name = :old_permission'
        );
        $copyLegacyPermission->execute([
            'new_permission' => 'manage_services',
            'old_permission' => 'manage_departments',
        ]);
        foreach ([
            'manage_roles',
            'manage_landing_settings',
            'manage_contact_settings',
            'manage_maintenance_settings',
            'manage_retention_settings',
            'manage_sidebar_settings',
            'manage_security_settings',
            'manage_announcements',
        ] as $granularPermission) {
            $copyLegacyPermission->execute([
                'new_permission' => $granularPermission,
                'old_permission' => 'manage_settings',
            ]);
        }
        $pdo->exec("DELETE FROM role_permissions WHERE permission_name = 'manage_settings'");
    }
    $clientReceiptMigration = $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration_key) VALUES ('client_own_document_receipt_permission')");
    $clientReceiptMigration->execute();
    if ($clientReceiptMigration->rowCount() === 1) {
        $clientReceiptPermission = $pdo->prepare(
            "INSERT IGNORE INTO role_permissions (role_id, permission_name)
             SELECT id, 'complete_own_documents' FROM roles WHERE slug = 'client'"
        );
        $clientReceiptPermission->execute();
    }
    $fileRevisionMigration = $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration_key) VALUES ('default_file_revision_permissions')");
    $fileRevisionMigration->execute();
    if ($fileRevisionMigration->rowCount() === 1) {
        $fileRevisionPermission = $pdo->prepare(
            "INSERT IGNORE INTO role_permissions (role_id, permission_name)
             SELECT id, 'manage_file_revisions' FROM roles WHERE slug IN ('department_admin', 'client')"
        );
        $fileRevisionPermission->execute();
    }
    $archiveViewMigration = $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration_key) VALUES ('archive_page_view_permission')");
    $archiveViewMigration->execute();
    if ($archiveViewMigration->rowCount() === 1) {
        $archiveViewPermission = $pdo->prepare(
            "INSERT IGNORE INTO role_permissions (role_id, permission_name)
             SELECT role_id, 'view_archive' FROM role_permissions WHERE permission_name = 'permanent_delete'"
        );
        $archiveViewPermission->execute();
    }

    // 3. Ensure users table exists & support new RBAC roles
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(50) DEFAULT 'department_staff',
        custom_role_id INT NULL,
        department_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (custom_role_id) REFERENCES roles(id) ON DELETE RESTRICT,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS file_upload_rate_limits (
        user_id INT NOT NULL PRIMARY KEY,
        last_upload_at DATETIME NOT NULL,
        CONSTRAINT fk_file_upload_rate_limits_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $uCols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('created_at', $uCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
    if (!in_array('custom_role_id', $uCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN custom_role_id INT NULL");
    }
    $customRoleForeignKeyStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'custom_role_id'
           AND REFERENCED_TABLE_NAME = 'roles'
         LIMIT 1"
    );
    $customRoleForeignKeyStmt->execute();
    if (!$customRoleForeignKeyStmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_custom_role FOREIGN KEY (custom_role_id) REFERENCES roles(id) ON DELETE RESTRICT");
    }
    
    // Google OAuth upgrade: Add missing columns for Google account integration
    if (!in_array('google_id', $uCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) UNIQUE NULL AFTER password");
    }
    if (!in_array('email_verified', $uCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0 AFTER google_id");
    }
    if (!in_array('account_status', $uCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN account_status VARCHAR(50) DEFAULT 'active' AFTER email_verified");
    }
    if (!in_array('profile_picture', $uCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture TEXT NULL AFTER account_status");
    }
    if (!in_array('contact_number', $uCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(50) NULL AFTER profile_picture");
    }
    if (!in_array('last_login', $uCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER profile_picture");
    }

    $serviceCols = $pdo->query("DESCRIBE department_services")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('assigned_user_id', $serviceCols)) {
        $pdo->exec("ALTER TABLE department_services ADD COLUMN assigned_user_id INT NULL AFTER department_id");
    }
    if (!in_array('deleted_at', $serviceCols)) {
        $pdo->exec("ALTER TABLE department_services ADD COLUMN deleted_at DATETIME NULL AFTER priority");
    }
    if (!in_array('deleted_by', $serviceCols)) {
        $pdo->exec("ALTER TABLE department_services ADD COLUMN deleted_by INT NULL AFTER deleted_at");
    }
    if (!in_array('deleted_reason', $serviceCols)) {
        $pdo->exec("ALTER TABLE department_services ADD COLUMN deleted_reason VARCHAR(255) NULL AFTER deleted_by");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL UNIQUE,
        mime_type VARCHAR(150) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        uploaded_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (service_id) REFERENCES department_services(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_service_documents_service (service_id)
    )");

    // 3. Ensure reports table supports priority, sender_department_id, and assigned_department_id
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        service_id INT NULL,
        service_name VARCHAR(150) NULL,
        category VARCHAR(100) NULL,
        purpose TEXT NULL,
        additional_information TEXT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        priority VARCHAR(20) DEFAULT 'Medium',
        client_id INT NOT NULL,
        department_id INT NULL,
        sender_department_id INT NULL,
        originating_department_id INT NULL,
        assigned_department_id INT NULL,
        current_workflow_step INT DEFAULT 1,
        total_workflow_steps INT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES users(id),
        FOREIGN KEY (department_id) REFERENCES departments(id),
        INDEX idx_reports_created_at (created_at)
    )");

    // Add missing columns dynamically if reports table already existed
    $cols = $pdo->query("DESCRIBE reports")->fetchAll(PDO::FETCH_COLUMN);
    if ($pdo->query("SHOW INDEX FROM reports WHERE Key_name = 'idx_reports_created_at'")->fetch() === false) {
        $pdo->exec("ALTER TABLE reports ADD INDEX idx_reports_created_at (created_at)");
    }
    if (!in_array('priority', $cols)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN priority VARCHAR(20) DEFAULT 'Medium' AFTER status");
    }
    if (!in_array('category', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN category VARCHAR(100) NULL AFTER description");
    if (!in_array('service_id', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN service_id INT NULL AFTER description");
    if (!in_array('service_name', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN service_name VARCHAR(150) NULL AFTER service_id");
    if (!in_array('purpose', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN purpose TEXT NULL AFTER category");
    if (!in_array('additional_information', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN additional_information TEXT NULL AFTER purpose");
    if (!in_array('deadline_at', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN deadline_at DATETIME NULL AFTER additional_information");
    if (!in_array('overdue_notified_at', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN overdue_notified_at DATETIME NULL AFTER deadline_at");
    if (!in_array('is_deleted', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    if (!in_array('deleted_at', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN deleted_at DATETIME NULL AFTER is_deleted");
    if (!in_array('deleted_by', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN deleted_by INT NULL AFTER deleted_at");
    if (!in_array('deleted_reason', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN deleted_reason TEXT NULL AFTER deleted_by");
    if (!in_array('is_suspended', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0 AFTER deleted_reason");
    if (!in_array('suspended_at', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN suspended_at DATETIME NULL AFTER is_suspended");
    if (!in_array('suspended_by', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN suspended_by INT NULL AFTER suspended_at");
    if (!in_array('suspended_reason', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN suspended_reason TEXT NULL AFTER suspended_by");
    if (!in_array('department_private', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN department_private TINYINT(1) NOT NULL DEFAULT 0 AFTER suspended_reason");
    if (!in_array('submitted_by_user_id', $cols)) $pdo->exec("ALTER TABLE reports ADD COLUMN submitted_by_user_id INT NULL AFTER department_private");
    if (!in_array('sender_department_id', $cols)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN sender_department_id INT NULL AFTER department_id");
    }
    if (!in_array('originating_department_id', $cols)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN originating_department_id INT NULL AFTER sender_department_id");
        // Preserve existing workflows: their first recorded sender is the origin.
        $pdo->exec("UPDATE reports SET originating_department_id = sender_department_id WHERE originating_department_id IS NULL");
    }
    if (!in_array('assigned_department_id', $cols)) {
        $pdo->exec("ALTER TABLE reports ADD COLUMN assigned_department_id INT NULL AFTER sender_department_id");
    }

    $reportColDefs = $pdo->query("SHOW COLUMNS FROM reports")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reportColDefs as $col) {
        if ($col['Field'] === 'department_id' && $col['Null'] === 'NO') {
            $pdo->exec("ALTER TABLE reports MODIFY department_id INT NULL");
        }
        if ($col['Field'] === 'assigned_department_id' && $col['Null'] === 'NO') {
            $pdo->exec("ALTER TABLE reports MODIFY assigned_department_id INT NULL");
        }
    }

    // 4. Ensure report_workflow_routes table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS report_workflow_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        step_number INT NOT NULL,
        step_order INT NOT NULL,
        department_id INT NULL,
        destination_type VARCHAR(50) DEFAULT 'Department',
        destination_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        assigned_user_id INT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        approver_notes TEXT,
        received_date DATETIME NULL,
        assigned_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_date DATETIME NULL,
        FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
        FOREIGN KEY (department_id) REFERENCES departments(id)
    )");
    $routeCols = $pdo->query("DESCRIBE report_workflow_routes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('step_order', $routeCols)) $pdo->exec("ALTER TABLE report_workflow_routes ADD COLUMN step_order INT NULL AFTER step_number");
    if (!in_array('destination_type', $routeCols)) $pdo->exec("ALTER TABLE report_workflow_routes ADD COLUMN destination_type VARCHAR(50) DEFAULT 'Department' AFTER department_id");
    if (!in_array('destination_id', $routeCols)) $pdo->exec("ALTER TABLE report_workflow_routes ADD COLUMN destination_id INT NULL AFTER destination_type");
    if (!in_array('created_at', $routeCols)) $pdo->exec("ALTER TABLE report_workflow_routes ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER destination_id");
    if (!in_array('received_date', $routeCols)) $pdo->exec("ALTER TABLE report_workflow_routes ADD COLUMN received_date DATETIME NULL AFTER approver_notes");
    $pdo->exec("UPDATE report_workflow_routes SET step_order = step_number WHERE step_order IS NULL");
    $pdo->exec("ALTER TABLE report_workflow_routes MODIFY department_id INT NULL");

    // 5. Ensure report_logs table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS report_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        old_department INT NULL,
        new_department INT NULL,
        action VARCHAR(50) NOT NULL,
        remarks TEXT,
        updated_by INT NOT NULL,
        date_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
        FOREIGN KEY (updated_by) REFERENCES users(id)
    )");

    // Final-origin acceptance is recorded as an archive event without deleting the document history.
    $pdo->exec("CREATE TABLE IF NOT EXISTS archived_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL UNIQUE,
        archived_by INT NULL,
        archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reason TEXT NULL,
        FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL UNIQUE,
        mime_type VARCHAR(100) NOT NULL,
        file_size INT NOT NULL,
        uploaded_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_attachments_report (report_id)
    )");
    $attachmentCols = $pdo->query("SHOW COLUMNS FROM attachments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('version_number', $attachmentCols, true)) {
        $pdo->exec("ALTER TABLE attachments ADD COLUMN version_number INT NOT NULL DEFAULT 1 AFTER file_size");
    }
    if (!in_array('revision_note', $attachmentCols, true)) {
        $pdo->exec("ALTER TABLE attachments ADD COLUMN revision_note VARCHAR(500) NULL AFTER version_number");
    }
    if (!in_array('requirement_name', $attachmentCols, true)) {
        $pdo->exec("ALTER TABLE attachments ADD COLUMN requirement_name VARCHAR(255) NULL AFTER revision_note");
    }

    // 6. Ensure notifications table exists (Department or User notification)
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NULL,
        user_id INT NULL,
        tracking_no VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
        INDEX idx_notifications_created_at (created_at)
    )");

    if ($pdo->query("SHOW INDEX FROM notifications WHERE Key_name = 'idx_notifications_created_at'")->fetch() === false) {
        $pdo->exec("ALTER TABLE notifications ADD INDEX idx_notifications_created_at (created_at)");
    }
    $notifColDefs = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_ASSOC);
    $notifColumns = array_column($notifColDefs, 'Field');
    $newReadColumns = [];
    if (!in_array('client_is_read', $notifColumns, true)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN client_is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
        $newReadColumns[] = 'client_is_read';
    }
    if (!in_array('admin_is_read', $notifColumns, true)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN admin_is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER client_is_read");
        $newReadColumns[] = 'admin_is_read';
    }
    if (!empty($newReadColumns)) {
        $copyReadColumns = implode(', ', array_map(
            static fn($column) => $column . ' = is_read',
            $newReadColumns
        ));
        $pdo->exec("UPDATE notifications SET {$copyReadColumns}");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->beginTransaction();
    try {
        $trackingMigrationClaim = $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration_key) VALUES ('year_random_tracking_numbers')");
        $trackingMigrationClaim->execute();
        if ($trackingMigrationClaim->rowCount() === 1) {
            $legacyTrackingStmt = $pdo->query('SELECT id, tracking_number, created_at FROM reports ORDER BY id');
            $replaceTrackingStmt = $pdo->prepare(
                'UPDATE reports SET tracking_number = :new_tracking
                 WHERE id = :id AND tracking_number <=> :old_tracking'
            );
            $replaceNotificationTrackingStmt = $pdo->prepare(
                'UPDATE notifications SET tracking_no = :new_tracking WHERE tracking_no = :old_tracking'
            );
            foreach ($legacyTrackingStmt->fetchAll(PDO::FETCH_ASSOC) as $legacyReport) {
                $oldTrackingNumber = $legacyReport['tracking_number'];
                $createdAt = isset($legacyReport['created_at']) ? (string) $legacyReport['created_at'] : null;
                $newTrackingNumber = generateTrackingNumber($pdo, $createdAt);
                $replaceTrackingStmt->execute([
                    'new_tracking' => $newTrackingNumber,
                    'id' => (int) $legacyReport['id'],
                    'old_tracking' => $legacyReport['tracking_number'],
                ]);
                if ($replaceTrackingStmt->rowCount() === 1) {
                    if ($oldTrackingNumber !== null && trim((string) $oldTrackingNumber) !== '') {
                        $replaceNotificationTrackingStmt->execute([
                            'new_tracking' => $newTrackingNumber,
                            'old_tracking' => $oldTrackingNumber,
                        ]);
                    }
                }
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $reportIndexes = $pdo->query('SHOW INDEX FROM reports')->fetchAll(PDO::FETCH_ASSOC);
    $hasUniqueTrackingIndex = false;
    foreach ($reportIndexes as $reportIndex) {
        if ($reportIndex['Key_name'] === 'uniq_reports_tracking_number') {
            $hasUniqueTrackingIndex = true;
            break;
        }
    }
    if (!$hasUniqueTrackingIndex) {
        $pdo->exec('ALTER TABLE reports ADD UNIQUE KEY uniq_reports_tracking_number (tracking_number)');
    }

    foreach ($notifColDefs as $col) {
        if ($col['Field'] === 'department_id' && $col['Null'] === 'NO') {
            try {
                $pdo->exec("ALTER TABLE notifications MODIFY department_id INT NULL");
            } catch (Exception $ex) {
                error_log("Failed to modify notifications.department_id to NULLable: " . $ex->getMessage());
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_reads (
            notification_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (notification_id, user_id),
            FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS inbox_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            sender_id INT NOT NULL,
            recipient_user_id INT NULL,
            recipient_department_id INT NULL,
            subject VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (recipient_department_id) REFERENCES departments(id) ON DELETE SET NULL,
            INDEX idx_inbox_client (client_id),
            INDEX idx_inbox_department (recipient_department_id)
        )");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL UNIQUE,
        mime_type VARCHAR(100) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_message_attachment (message_id),
        FOREIGN KEY (message_id) REFERENCES inbox_messages(id) ON DELETE CASCADE
    )");

    // 7. Ensure editable system settings exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_label VARCHAR(150) NOT NULL,
        setting_value TEXT NULL,
        setting_type VARCHAR(20) NOT NULL DEFAULT 'text',
        description VARCHAR(255) NULL,
        updated_by INT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $defaultSettings = [
        ['municipality_name', 'Municipality Name', 'Municipality of Carranglan', 'text', 'Displayed municipality name.'],
        ['contact_email', 'Contact Email', '', 'email', 'Official contact email address.'],
        ['contact_number', 'Contact Number', '', 'text', 'Official contact number.'],
        ['contact_address', 'Contact Address', 'Municipal Hall, Poblacion, Carranglan, Nueva Ecija', 'text', 'Official office address shown on the landing page.'],
        ['contact_website', 'Contact Website', 'www.carranglan.gov.ph', 'text', 'Official website shown on the landing page.'],
        ['office_hours_days', 'Office Hours Days', 'Monday - Friday', 'text', 'Office days shown on the landing page.'],
        ['office_hours_time', 'Office Hours Time', '8:00 AM - 5:00 PM', 'text', 'Office hours shown on the landing page.'],
        ['office_hours_note', 'Office Hours Note', 'Except Holidays', 'text', 'Office hours note shown on the landing page.'],
        ['system_name', 'System Name', 'Municipal Document Tracking System', 'text', 'Name displayed in the system header.'],
        ['landing_title', 'Landing Page Title', 'Municipal Document Tracking System', 'text', 'Main title shown on the public landing page.'],
        ['landing_subtitle', 'Landing Page Subtitle', 'Secure, transparent, and trackable document workflow.', 'text', 'Subtitle shown on the public landing page.'],
        ['brand_logo_url', 'Logo URL', 'image/carranglan-seal.png', 'text', 'Optional public URL or relative path to the system logo.'],
        ['landing_background_url', 'Landing Background Image', '', 'text', 'Optional image used behind the public landing page hero section.'],
        ['dark_mode', 'Dark Mode', '0', 'boolean', 'Use the dark visual theme for authenticated pages.'],
        ['maintenance_mode', 'Maintenance Mode', '0', 'boolean', 'Temporarily restrict non-administrator access.'],
        ['sidebar_extra_label', 'Sidebar Extra Label', '', 'text', 'Optional label for a custom sidebar item.'],
        ['sidebar_extra_url', 'Sidebar Extra URL', '', 'text', 'Optional URL for a custom sidebar item.'],
        ['notification_enabled', 'Notifications Enabled', '1', 'boolean', 'Enable system notifications.'],
        ['document_retention_months', 'Document and Record Retention (Months)', '3', 'number', 'Age before documents, uploaded files, audit logs, system notifications, and announcements are automatically deleted.'],
    ];
    $settingStmt = $pdo->prepare(
        'INSERT IGNORE INTO system_settings
         (setting_key, setting_label, setting_value, setting_type, description)
         VALUES (:setting_key, :setting_label, :setting_value, :setting_type, :description)'
    );
    foreach ($defaultSettings as $setting) {
        $settingStmt->execute([
            'setting_key' => $setting[0],
            'setting_label' => $setting[1],
            'setting_value' => $setting[2],
            'setting_type' => $setting[3],
            'description' => $setting[4],
        ]);
    }
    $pdo->exec("UPDATE system_settings
        SET setting_value = 'image/carranglan-seal.png'
        WHERE setting_key = 'brand_logo_url'
          AND (setting_value IS NULL OR TRIM(setting_value) = '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sidebar_sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_sidebar_sections_order (is_active, sort_order)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sidebar_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_id INT NULL,
        label VARCHAR(100) NOT NULL,
        url VARCHAR(255) NOT NULL,
        icon VARCHAR(20) NOT NULL DEFAULT '★',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (section_id) REFERENCES sidebar_sections(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_sidebar_items_order (is_active, sort_order)
    )");
    $sidebarItemColumns = $pdo->query('DESCRIBE sidebar_items')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('section_id', $sidebarItemColumns, true)) {
        $pdo->exec('ALTER TABLE sidebar_items ADD COLUMN section_id INT NULL AFTER id');
    }
    if (!in_array('icon_path', $sidebarItemColumns, true)) {
        $pdo->exec('ALTER TABLE sidebar_items ADD COLUMN icon_path VARCHAR(255) NULL AFTER icon');
    }
    $sectionCount = (int) $pdo->query('SELECT COUNT(*) FROM sidebar_sections')->fetchColumn();
    if ($sectionCount === 0) {
        $pdo->exec("INSERT INTO sidebar_sections (name, sort_order) VALUES ('CUSTOM LINKS', 0)");
    }
    $defaultSidebarSectionId = (int) $pdo->query("SELECT id FROM sidebar_sections WHERE name = 'CUSTOM LINKS' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($defaultSidebarSectionId > 0) {
        $assignUngroupedSidebarItems = $pdo->prepare('UPDATE sidebar_items SET section_id = :section_id WHERE section_id IS NULL');
        $assignUngroupedSidebarItems->execute(['section_id' => $defaultSidebarSectionId]);
    }

    // 7. Seed the 19 official Municipal Departments with codes and emails
    $deptList = [
        ['code' => 'MAYOR', 'name' => "Mayor's Office", 'email' => 'mayor@carranglan.gov.ph'],
        ['code' => 'GSO', 'name' => 'General Service Office', 'email' => 'gso@carranglan.gov.ph'],
        ['code' => 'HRM', 'name' => 'Human Resource Management', 'email' => 'hrm@carranglan.gov.ph'],
        ['code' => 'DILG', 'name' => 'Department of the Interior and Local Government', 'email' => 'dilg@carranglan.gov.ph'],
        ['code' => 'MPDO', 'name' => 'Municipal Planning and Development Office', 'email' => 'mpdo@carranglan.gov.ph'],
        ['code' => 'MENRO', 'name' => 'Municipal Environment and Natural Resources Office', 'email' => 'menro@carranglan.gov.ph'],
        ['code' => 'MDRRMO', 'name' => 'Municipal Disaster Risk Reduction and Management Office', 'email' => 'mdrrmo@carranglan.gov.ph'],
        ['code' => 'DSWD', 'name' => 'Department of Social Welfare and Development', 'email' => 'dswd@carranglan.gov.ph'],
        ['code' => 'MCR', 'name' => "Municipal Civil Registrar's Office", 'email' => 'mcr@carranglan.gov.ph'],
        ['code' => 'ENGR', 'name' => 'Municipal Engineering Office', 'email' => 'engr@carranglan.gov.ph'],
        ['code' => 'BUDGET', 'name' => 'Municipal Budget Office', 'email' => 'budget@carranglan.gov.ph'],
        ['code' => 'BFP', 'name' => 'Bureau of Fire Protection', 'email' => 'bfp@carranglan.gov.ph'],
        ['code' => 'ASSESSOR', 'name' => 'Municipal Assessor Office', 'email' => 'assessor@carranglan.gov.ph'],
        ['code' => 'ACCOUNTING', 'name' => 'Municipal Accounting Office', 'email' => 'accounting@carranglan.gov.ph'],
        ['code' => 'TREASURER', 'name' => 'Office of the Municipal Treasurer', 'email' => 'treasurer@carranglan.gov.ph'],
        ['code' => 'PNP', 'name' => 'Philippine National Police', 'email' => 'pnp@carranglan.gov.ph'],
        ['code' => 'SENIOR', 'name' => 'Senior Office', 'email' => 'senior@carranglan.gov.ph'],
        ['code' => 'RHU', 'name' => 'Rural Health Unit', 'email' => 'rhu@carranglan.gov.ph'],
        ['code' => 'DA', 'name' => 'Department of Agriculture', 'email' => 'da@carranglan.gov.ph'],
    ];

    $deptMap = [];
    foreach ($deptList as $dept) {
        $check = $pdo->prepare("SELECT id FROM departments WHERE name = :name");
        $check->execute(['name' => $dept['name']]);
        $id = $check->fetchColumn();

        if (!$id) {
            $codeCheck = $pdo->prepare('SELECT id FROM departments WHERE department_code = :code LIMIT 1');
            $codeCheck->execute(['code' => $dept['code']]);
            $id = $codeCheck->fetchColumn();
        }
        
        if (!$id) {
            $ins = $pdo->prepare(
                "INSERT INTO departments (name, department_code, official_email, status) 
                 VALUES (:name, :code, :email, 'active')"
            );
            $ins->execute([
                'name' => $dept['name'],
                'code' => $dept['code'],
                'email' => $dept['email']
            ]);
            $id = $pdo->lastInsertId();
        } else {
            // Update existing department with code and email if missing
            $codeCheck = $pdo->prepare("SELECT id FROM departments WHERE department_code = :code AND id <> :id LIMIT 1");
            $codeCheck->execute(['code' => $dept['code'], 'id' => $id]);
            $codeAvailable = !$codeCheck->fetchColumn();
            $update = $pdo->prepare(
                "UPDATE departments
                 SET department_code = CASE WHEN department_code IS NULL AND :code_available = 1 THEN :code ELSE department_code END,
                     official_email = COALESCE(official_email, :email),
                     status = COALESCE(status, 'active')
                 WHERE id = :id"
            );
            $update->execute([
                'code' => $dept['code'],
                'code_available' => $codeAvailable ? 1 : 0,
                'email' => $dept['email'],
                'id' => $id
            ]);
        }
        $deptMap[$dept['name']] = $id;
    }

    $bfpServiceStmt = $pdo->prepare("SELECT id FROM departments WHERE department_code = 'BFP' LIMIT 1");
    $bfpServiceStmt->execute();
    $bfpDepartmentId = $bfpServiceStmt->fetchColumn();
    if ($bfpDepartmentId) {
        $serviceCheck = $pdo->prepare("SELECT id FROM department_services WHERE department_id = :department_id AND LOWER(service_name) = 'flashing' LIMIT 1");
        $serviceCheck->execute(['department_id' => $bfpDepartmentId]);
        if (!$serviceCheck->fetchColumn()) {
            $serviceInsert = $pdo->prepare("INSERT INTO department_services (department_id, service_name, description, priority, status) VALUES (:department_id, 'flashing', 'Flashing service offered by the Bureau of Fire Protection.', 'Medium', 'Active')");
            $serviceInsert->execute(['department_id' => $bfpDepartmentId]);
        }
        $bfpServiceIdStmt = $pdo->prepare("SELECT id FROM department_services WHERE department_id = :department_id AND LOWER(service_name) = 'flashing' LIMIT 1");
        $bfpServiceIdStmt->execute(['department_id' => $bfpDepartmentId]);
        $bfpServiceId = $bfpServiceIdStmt->fetchColumn();
        if ($bfpServiceId) {
            $workflowCheck = $pdo->prepare('SELECT id FROM service_workflow_steps WHERE service_id = :service_id LIMIT 1');
            $workflowCheck->execute(['service_id' => $bfpServiceId]);
            if (!$workflowCheck->fetchColumn()) {
                $workflowInsert = $pdo->prepare('INSERT INTO service_workflow_steps (service_id, step_number, department_id, required_action) VALUES (:service_id, 1, :department_id, :required_action)');
                $workflowInsert->execute(['service_id' => $bfpServiceId, 'department_id' => $bfpDepartmentId, 'required_action' => 'Process']);
            }
        }
    }

    $pdo->exec("INSERT INTO service_workflow_steps (service_id, step_number, department_id, required_action, status)
                SELECT ds.id, 1, ds.department_id, 'Process', 'Active'
                FROM department_services ds
                LEFT JOIN service_workflow_steps sws ON sws.service_id = ds.id
                WHERE sws.id IS NULL AND ds.status = 'Active'");

    // 8. Create the first administrator only with deployment-provided credentials.
    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
    if ($adminCount === 0) {
        $adminEmail = trim((string) (getenv('INITIAL_ADMIN_EMAIL') ?: ''));
        $adminPassword = (string) (getenv('INITIAL_ADMIN_PASSWORD') ?: '');

        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminPassword) < 16) {
            throw new RuntimeException(
                'Set INITIAL_ADMIN_EMAIL and INITIAL_ADMIN_PASSWORD (at least 16 characters) before initializing the first administrator.'
            );
        }

        $existingAdmin = $pdo->prepare('SELECT role FROM users WHERE email = :email LIMIT 1');
        $existingAdmin->execute(['email' => $adminEmail]);
        $existingRole = $existingAdmin->fetchColumn();
        if ($existingRole !== false && $existingRole !== 'super_admin') {
            throw new RuntimeException(
                'INITIAL_ADMIN_EMAIL is already assigned to a non-administrator account.'
            );
        }

        if ($existingRole === false) {
            $createAdmin = $pdo->prepare(
                "INSERT INTO users (name, email, password, role, department_id, email_verified, account_status)
                 VALUES (:name, :email, :password, 'super_admin', NULL, 1, 'active')"
            );
            $createAdmin->execute([
                'name' => 'System Administrator',
                'email' => $adminEmail,
                'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
            ]);
        }
    }

    // 9. Ensure password_resets table exists (for Forgot Password feature)
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_email (email)
    )");

    // 10. Ensure email_verifications table exists (for email verification feature)
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(100) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        verified_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_token (token),
        INDEX idx_email (email),
        INDEX idx_user (user_id)
    )");

    // 11. Ensure audit_logs table exists (for system audit trail)
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT NULL,
        document_id INT NULL,
        department_id INT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_action (action),
        INDEX idx_created (created_at),
        INDEX idx_document (document_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )");
    $auditLogCols = $pdo->query("DESCRIBE audit_logs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('old_values', $auditLogCols, true)) {
        $pdo->exec("ALTER TABLE audit_logs ADD COLUMN old_values TEXT NULL AFTER details");
    }
    if (!in_array('new_values', $auditLogCols, true)) {
        $pdo->exec("ALTER TABLE audit_logs ADD COLUMN new_values TEXT NULL AFTER old_values");
    }

    // 12. Ensure announcements table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        category VARCHAR(50) DEFAULT 'Executive Memo',
        posted_by INT NULL,
        department_id INT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_posted_by (posted_by),
        INDEX idx_is_active (is_active),
        INDEX idx_category (category),
        INDEX idx_announcements_created_at (created_at)
    )");
    if ($pdo->query("SHOW INDEX FROM announcements WHERE Key_name = 'idx_announcements_created_at'")->fetch() === false) {
        $pdo->exec("ALTER TABLE announcements ADD INDEX idx_announcements_created_at (created_at)");
    }

    $annCols = $pdo->query("DESCRIBE announcements")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('department_id', $annCols)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN department_id INT NULL AFTER posted_by");
    }
    if (!in_array('target_role', $annCols)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN target_role VARCHAR(50) NULL DEFAULT 'all' AFTER department_id");
    }
    if (!in_array('deleted_at', $annCols)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN deleted_at DATETIME NULL AFTER is_active");
    }
    if (!in_array('deleted_by', $annCols)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN deleted_by INT NULL AFTER deleted_at");
    }
    if (!in_array('deleted_reason', $annCols)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN deleted_reason VARCHAR(255) NULL AFTER deleted_by");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT IGNORE INTO announcement_categories (name)
        SELECT DISTINCT TRIM(category) FROM announcements
        WHERE category IS NOT NULL AND TRIM(category) <> ''");
    $pdo->exec("INSERT IGNORE INTO announcement_categories (name) VALUES
        ('Executive Memo'),
        ('Work Suspension'),
        ('Public Advisory'),
        ('Department Notice'),
        ('General Announcement')");

} catch (Exception $e) {
    error_log('Database Migration Failed: ' . $e->getMessage());
    fwrite(STDERR, 'Database migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
