-- Schema and non-account reference data. Import into the configured app database.
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `department_code` VARCHAR(50) NULL,
  `official_email` VARCHAR(150) NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'active',
  `description` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_department_name` (`name`),
  UNIQUE KEY `uniq_department_code` (`department_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `google_id` VARCHAR(255) NULL,
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `account_status` VARCHAR(50) NOT NULL DEFAULT 'active',
  `profile_picture` TEXT NULL,
  `last_login` DATETIME NULL,
  `role` VARCHAR(50) DEFAULT 'department_staff',
  `department_id` INT NULL,
  `temp_password` VARCHAR(255) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_email` (`email`),
  UNIQUE KEY `uniq_user_google_id` (`google_id`),
  KEY `idx_users_department` (`department_id`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `role_id` INT NOT NULL,
  `permission_name` VARCHAR(100) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_permission` (`role_id`, `permission_name`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `department_services` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `department_id` INT NOT NULL,
  `assigned_user_id` INT NULL,
  `service_name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Active',
  `priority` VARCHAR(50) NOT NULL DEFAULT 'Medium',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_services_department` (`department_id`),
  KEY `idx_services_assigned_user` (`assigned_user_id`),
  CONSTRAINT `fk_services_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_services_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `service_workflow_steps` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `service_id` INT NOT NULL,
  `step_number` INT NOT NULL,
  `department_id` INT NOT NULL,
  `required_action` VARCHAR(100) DEFAULT 'Process',
  `status` VARCHAR(50) DEFAULT 'Active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_service_step` (`service_id`, `step_number`),
  KEY `idx_workflow_service` (`service_id`),
  KEY `idx_workflow_department` (`department_id`),
  CONSTRAINT `fk_workflow_service` FOREIGN KEY (`service_id`) REFERENCES `department_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_workflow_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reports` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `service_id` INT NULL,
  `service_name` VARCHAR(150) NULL,
  `category` VARCHAR(100) NULL,
  `purpose` TEXT NULL,
  `additional_information` TEXT NULL,
  `status` VARCHAR(50) DEFAULT 'Pending',
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_at` DATETIME NULL,
  `deleted_by` INT NULL,
  `deleted_reason` TEXT NULL,
  `is_suspended` TINYINT(1) NOT NULL DEFAULT 0,
  `suspended_at` DATETIME NULL,
  `suspended_by` INT NULL,
  `suspended_reason` TEXT NULL,
  `priority` VARCHAR(20) DEFAULT 'Medium',
  `client_id` INT NOT NULL,
  `client_name` VARCHAR(150) NULL,
  `client_email` VARCHAR(150) NULL,
  `submission_mode` VARCHAR(20) NOT NULL DEFAULT 'online',
  `submission_source` VARCHAR(30) NOT NULL DEFAULT 'client',
  `department_id` INT NULL,
  `sender_department_id` INT NULL,
  `originating_department_id` INT NULL,
  `assigned_department_id` INT NULL,
  `current_workflow_step` INT DEFAULT 1,
  `total_workflow_steps` INT DEFAULT 1,
  `tracking_number` VARCHAR(50) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reports_client` (`client_id`),
  KEY `idx_reports_department` (`department_id`),
  KEY `idx_reports_assigned` (`assigned_department_id`),
  KEY `idx_reports_status` (`status`),
  UNIQUE KEY `uniq_reports_tracking_number` (`tracking_number`),
  CONSTRAINT `fk_reports_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `report_workflow_routes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `report_id` INT NOT NULL,
  `step_number` INT NOT NULL,
  `step_order` INT NOT NULL,
  `department_id` INT NULL,
  `destination_type` VARCHAR(50) DEFAULT 'Department',
  `destination_id` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `assigned_user_id` INT NULL,
  `status` VARCHAR(50) DEFAULT 'Pending',
  `approver_notes` TEXT,
  `received_date` DATETIME NULL,
  `assigned_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `completed_date` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_routes_report` (`report_id`),
  KEY `idx_routes_department` (`department_id`),
  CONSTRAINT `fk_routes_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_routes_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `report_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `report_id` INT NOT NULL,
  `old_department` INT NULL,
  `new_department` INT NULL,
  `action` VARCHAR(50) NOT NULL,
  `remarks` TEXT,
  `updated_by` INT NOT NULL,
  `date_updated` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_report` (`report_id`),
  KEY `idx_logs_updated_by` (`updated_by`),
  CONSTRAINT `fk_logs_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `archived_records` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `report_id` INT NOT NULL UNIQUE,
  `archived_by` INT NULL,
  `archived_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `reason` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_archived_report` (`report_id`),
  CONSTRAINT `fk_archived_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_archived_user` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attachments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `report_id` INT NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT NOT NULL,
  `uploaded_by` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attachment_stored_name` (`stored_name`),
  KEY `idx_attachments_report` (`report_id`),
  CONSTRAINT `fk_attachments_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attachments_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_outbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient` VARCHAR(254) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_outbox_ready` (`status`, `available_at`, `id`),
  KEY `idx_email_outbox_locked` (`status`, `locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `department_id` INT NULL,
  `user_id` INT NULL,
  `tracking_no` VARCHAR(50) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `client_is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `admin_is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_department` (`department_id`),
  CONSTRAINT `fk_notifications_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_label` VARCHAR(150) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `description` VARCHAR(255) NULL,
  `updated_by` INT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_system_settings_key` (`setting_key`),
  CONSTRAINT `fk_system_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sidebar_sections` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sidebar_sections_order` (`is_active`, `sort_order`),
  CONSTRAINT `fk_sidebar_sections_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sidebar_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `section_id` INT NULL,
  `label` VARCHAR(100) NOT NULL,
  `url` VARCHAR(255) NOT NULL,
  `icon` VARCHAR(20) NOT NULL DEFAULT '★',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sidebar_items_section` (`section_id`),
  KEY `idx_sidebar_items_order` (`is_active`, `sort_order`),
  CONSTRAINT `fk_sidebar_items_section` FOREIGN KEY (`section_id`) REFERENCES `sidebar_sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sidebar_items_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(100) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reset_token` (`token`),
  KEY `idx_reset_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `category` VARCHAR(50) DEFAULT 'Executive Memo',
  `posted_by` INT NULL,
  `department_id` INT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `announcement_categories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_announcement_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `verified_at` DATETIME NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email_verification_token` (`token`),
  KEY `idx_email_verifications_user` (`user_id`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `document_id` INT NULL,
  `department_id` INT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `departments` (`name`, `department_code`, `official_email`, `description`) VALUES
('Mayor\'s Office', 'MAYOR', 'mayor@carranglan.gov.ph', 'Municipal executive office'),
('General Service Office', 'GSO', 'gso@carranglan.gov.ph', 'General services office'),
('Human Resource Management', 'HRM', 'hrm@carranglan.gov.ph', 'Human resource management'),
('Department of the Interior and Local Government', 'DILG', 'dilg@carranglan.gov.ph', 'DILG liaison office'),
('Municipal Planning and Development Office', 'MPDO', 'mpdo@carranglan.gov.ph', 'Municipal planning and development office'),
('Municipal Environment and Natural Resources Office', 'MENRO', 'menro@carranglan.gov.ph', 'Environment and natural resources office'),
('Municipal Disaster Risk Reduction and Management Office', 'MDRRMO', 'mdrrmo@carranglan.gov.ph', 'Disaster risk reduction and management office'),
('Department of Social Welfare and Development', 'DSWD', 'dswd@carranglan.gov.ph', 'Social welfare and development office'),
('Municipal Civil Registrar\'s Office', 'MCR', 'mcr@carranglan.gov.ph', 'Civil registration office'),
('Municipal Engineering Office', 'ENGR', 'engr@carranglan.gov.ph', 'Engineering and public works'),
('Municipal Budget Office', 'BUDGET', 'budget@carranglan.gov.ph', 'Budget planning and monitoring'),
('Bureau of Fire Protection', 'BFP', 'bfp@carranglan.gov.ph', 'Fire protection office'),
('Municipal Assessor Office', 'ASSESSOR', 'assessor@carranglan.gov.ph', 'Municipal assessor office'),
('Municipal Accounting Office', 'ACCOUNTING', 'accounting@carranglan.gov.ph', 'Accounting and financial records'),
('Office of the Municipal Treasurer', 'TREASURER', 'treasurer@carranglan.gov.ph', 'Treasury and cash operations'),
('Philippine National Police', 'PNP', 'pnp@carranglan.gov.ph', 'Police liaison office'),
('Senior Office', 'SENIOR', 'senior@carranglan.gov.ph', 'Senior citizens affairs office'),
('Rural Health Unit', 'RHU', 'rhu@carranglan.gov.ph', 'Rural health unit'),
('Department of Agriculture', 'DA', 'da@carranglan.gov.ph', 'Agriculture support office')
ON DUPLICATE KEY UPDATE
  `department_code` = VALUES(`department_code`),
  `official_email` = VALUES(`official_email`),
  `description` = VALUES(`description`);

INSERT INTO `roles` (`name`, `slug`, `description`) VALUES
('Client', 'client', 'Citizen users who submit and track documents.'),
('Department Staff', 'department_staff', 'Staff members handling assigned documents.'),
('Department Admin', 'department_admin', 'Department administrator for routing and approvals.'),
('Super Admin', 'super_admin', 'System-level administrator with full oversight.')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `department_services` (`department_id`, `service_name`, `description`, `priority`, `status`)
SELECT d.id, 'flashing', 'Flashing service offered by the Bureau of Fire Protection.', 'Medium', 'Active'
FROM departments d
WHERE d.department_code = 'BFP'
  AND NOT EXISTS (
    SELECT 1 FROM department_services ds
    WHERE ds.department_id = d.id AND LOWER(ds.service_name) = 'flashing'
  );

INSERT INTO `service_workflow_steps` (`service_id`, `step_number`, `department_id`, `required_action`)
SELECT ds.id, 1, ds.department_id, 'Process'
FROM department_services ds
WHERE LOWER(ds.service_name) = 'flashing'
  AND NOT EXISTS (SELECT 1 FROM service_workflow_steps sws WHERE sws.service_id = ds.id);

INSERT INTO `service_workflow_steps` (`service_id`, `step_number`, `department_id`, `required_action`)
SELECT ds.id, 1, ds.department_id, 'Process'
FROM department_services ds
LEFT JOIN service_workflow_steps sws ON sws.service_id = ds.id
WHERE sws.id IS NULL AND ds.status = 'Active';
