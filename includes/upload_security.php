<?php

function hasUploadAttempt(?array $file): bool
{
    if ($file === null || !array_key_exists('error', $file)) {
        return false;
    }

    if (is_array($file['error'])) {
        foreach ($file['error'] as $errorCode) {
            if ((int) $errorCode !== UPLOAD_ERR_NO_FILE) {
                return true;
            }
        }
        return false;
    }

    return (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
}

function enforceUploadCooldown(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('A valid user account is required to upload files.');
    }

    $setting = $pdo->prepare(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'file_upload_cooldown_minutes' LIMIT 1"
    );
    $setting->execute();
    $cooldownMinutes = max(1, min(1440, (int) ($setting->fetchColumn() ?: 1)));
    $cooldownSeconds = $cooldownMinutes * 60;

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            "INSERT IGNORE INTO file_upload_rate_limits (user_id, last_upload_at)
             VALUES (:user_id, '2000-01-01 00:00:00')"
        );
        $insert->execute(['user_id' => $userId]);

        $select = $pdo->prepare(
            'SELECT GREATEST(0, :cooldown_seconds - TIMESTAMPDIFF(SECOND, last_upload_at, NOW()))
             FROM file_upload_rate_limits
             WHERE user_id = :user_id
             FOR UPDATE'
        );
        $select->execute(['cooldown_seconds' => $cooldownSeconds, 'user_id' => $userId]);
        $retryAfter = (int) $select->fetchColumn();

        if ($retryAfter > 0) {
            $pdo->commit();
            return $retryAfter;
        }

        $update = $pdo->prepare(
            'UPDATE file_upload_rate_limits SET last_upload_at = NOW() WHERE user_id = :user_id'
        );
        $update->execute(['user_id' => $userId]);
        $pdo->commit();

        return 0;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function uploadCooldownMessage(int $retryAfter): string
{
    $retryAfter = max(1, $retryAfter);
    $minutes = intdiv($retryAfter, 60);
    $seconds = $retryAfter % 60;
    $duration = [];

    if ($minutes > 0) {
        $duration[] = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    if ($seconds > 0) {
        $duration[] = $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }

    return 'For security, please wait ' . implode(' and ', $duration) . ' before uploading another file.';
}
