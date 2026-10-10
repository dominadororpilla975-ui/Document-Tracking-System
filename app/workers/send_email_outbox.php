<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../bootstrap.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/mailer.php';

$batchLimit = 25;
$processed = 0;
$sent = 0;
$failed = 0;

while ($processed < $batchLimit) {
    $pdo->beginTransaction();
    try {
        $jobStmt = $pdo->query(
            "SELECT id, recipient, subject, body, attempts
             FROM email_outbox
             WHERE (status = 'pending' AND available_at <= NOW())
                OR (status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE"
        );
        $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            $pdo->commit();
            break;
        }

        $attempts = (int) $job['attempts'] + 1;
        $claimStmt = $pdo->prepare("UPDATE email_outbox SET status = 'processing', attempts = :attempts, locked_at = NOW() WHERE id = :id");
        $claimStmt->execute(['attempts' => $attempts, 'id' => (int) $job['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'Unable to claim email job: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $processed++;
    $delivered = sendEmailNotification(
        (string) $job['recipient'],
        (string) $job['subject'],
        (string) $job['body']
    );

    if ($delivered) {
        $sentStmt = $pdo->prepare("UPDATE email_outbox SET status = 'sent', sent_at = NOW(), locked_at = NULL, last_error = NULL WHERE id = :id");
        $sentStmt->execute(['id' => (int) $job['id']]);
        $sent++;
        continue;
    }

    if ($attempts >= 5) {
        $failedStmt = $pdo->prepare("UPDATE email_outbox SET status = 'failed', locked_at = NULL, last_error = 'SMTP delivery failed after 5 attempts' WHERE id = :id");
        $failedStmt->execute(['id' => (int) $job['id']]);
        $failed++;
        continue;
    }

    $retryDelay = min(1800, 30 * (2 ** ($attempts - 1)));
    $retryStmt = $pdo->prepare(
        "UPDATE email_outbox
         SET status = 'pending', locked_at = NULL, available_at = DATE_ADD(NOW(), INTERVAL {$retryDelay} SECOND),
             last_error = 'SMTP delivery failed; retry scheduled'
         WHERE id = :id"
    );
    $retryStmt->execute(['id' => (int) $job['id']]);
}

printf("Email queue worker finished: processed=%d, sent=%d, failed=%d\n", $processed, $sent, $failed);