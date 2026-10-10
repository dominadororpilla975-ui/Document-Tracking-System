<?php

function runRetentionCleanup(PDO $pdo): void
{
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'dts_retention_' . substr(hash('sha256', $databaseName), 0, 32);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
    $lockStmt->execute(['lock_name' => $lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        return;
    }

    try {
        if ($pdo->inTransaction()) {
            return;
        }

        $lastRunStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'retention_cleanup_last_run' LIMIT 1");
        $lastRunStmt->execute();
        $lastRun = $lastRunStmt->fetchColumn();
        if ($lastRun !== false && strtotime((string) $lastRun) > time() - 86400) {
            return;
        }

        $retentionStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'document_retention_months' LIMIT 1");
        $retentionStmt->execute();
        $retentionMonths = filter_var($retentionStmt->fetchColumn(), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 120],
        ]);
        $retentionMonths = $retentionMonths === false ? 3 : $retentionMonths;

        $reportStmt = $pdo->query(
            "SELECT id FROM reports
             WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionMonths} MONTH)
             ORDER BY created_at ASC, id ASC
             LIMIT 500"
        );
        $reportIds = array_map('intval', $reportStmt->fetchAll(PDO::FETCH_COLUMN));
        $storedNames = [];

        $pdo->beginTransaction();
        try {
            if ($reportIds !== []) {
                $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
                $attachmentStmt = $pdo->prepare(
                    'SELECT stored_name FROM attachments WHERE report_id IN (' . $placeholders . ')'
                );
                $attachmentStmt->execute($reportIds);
                $storedNames = $attachmentStmt->fetchAll(PDO::FETCH_COLUMN);

                $deleteDocumentAudits = $pdo->prepare(
                    'DELETE FROM audit_logs WHERE document_id IN (' . $placeholders . ')'
                );
                $deleteDocumentAudits->execute($reportIds);

                $deleteReports = $pdo->prepare(
                    'DELETE FROM reports WHERE id IN (' . $placeholders . ')'
                );
                $deleteReports->execute($reportIds);
            }

            $pdo->exec("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionMonths} MONTH)");
            $pdo->exec("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionMonths} MONTH)");
            $pdo->exec("DELETE FROM announcements WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionMonths} MONTH)");
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        foreach ($storedNames as $storedName) {
            $storedName = (string) $storedName;
            if ($storedName === '' || basename($storedName) !== $storedName) {
                error_log('Retention cleanup skipped an invalid document file name.');
                continue;
            }

            $filePath = APP_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($filePath) && !@unlink($filePath)) {
                error_log('Retention cleanup could not remove a document file.');
            }
        }

        $moreReportsStmt = $pdo->query(
            "SELECT 1 FROM reports
             WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionMonths} MONTH)
             LIMIT 1"
        );
        if (!$moreReportsStmt->fetchColumn()) {
            $saveRunStmt = $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_label, setting_value, setting_type, description, updated_by)
                 VALUES ('retention_cleanup_last_run', 'Retention Cleanup Last Run', NOW(), 'system', 'Internal automatic retention cleanup marker.', NULL)
                 ON DUPLICATE KEY UPDATE setting_value = NOW(), updated_by = NULL"
            );
            $saveRunStmt->execute();
        }
    } finally {
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseStmt->execute(['lock_name' => $lockName]);
    }
}
