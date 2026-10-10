<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';

requireLogin();
$user = currentUser();
requireAnyPermission(['manage_security_settings', 'manage_settings'], $user);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_timeout_settings') {
        $timeoutMinutes = max(1, (int) ($_POST['session_timeout_minutes'] ?? 60));
        $warningMinutes = max(1, (int) ($_POST['session_warning_minutes'] ?? 2));
        $uploadCooldownMinutes = (int) ($_POST['file_upload_cooldown_minutes'] ?? 1);

        if ($warningMinutes >= $timeoutMinutes) {
            $error = 'Countdown warning minutes must be less than the total timeout duration.';
        } elseif ($uploadCooldownMinutes < 1 || $uploadCooldownMinutes > 1440) {
            $error = 'File upload cooldown must be between 1 and 1440 minutes.';
        } else {
            try {
                // Ensure rows exist in system_settings using unique parameter names or VALUES()
                $upsert = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_label, setting_value, setting_type, description, updated_by)
                    VALUES (:key, :label, :val1, 'number', :desc, :uid1)
                    ON DUPLICATE KEY UPDATE setting_value = :val2, updated_by = :uid2");

                $pdo->beginTransaction();
                $upsert->execute([
                    'key' => 'session_timeout_minutes',
                    'label' => 'Inactivity Timeout (Minutes)',
                    'val1' => (string) $timeoutMinutes,
                    'desc' => 'Total minutes of inactivity before auto logout.',
                    'uid1' => (int) $user['id'],
                    'val2' => (string) $timeoutMinutes,
                    'uid2' => (int) $user['id']
                ]);

                // Also calculate and keep session_timeout_hours for compatibility
                $upsert->execute([
                    'key' => 'session_timeout_hours',
                    'label' => 'Inactivity Timeout (Hours)',
                    'val1' => (string) round($timeoutMinutes / 60, 4),
                    'desc' => 'Total hours of inactivity before auto logout.',
                    'uid1' => (int) $user['id'],
                    'val2' => (string) round($timeoutMinutes / 60, 4),
                    'uid2' => (int) $user['id']
                ]);

                $upsert->execute([
                    'key' => 'session_warning_minutes',
                    'label' => 'Countdown Warning Duration (Minutes)',
                    'val1' => (string) $warningMinutes,
                    'desc' => 'Minutes before timeout to show countdown modal.',
                    'uid1' => (int) $user['id'],
                    'val2' => (string) $warningMinutes,
                    'uid2' => (int) $user['id']
                ]);

                $upsert->execute([
                    'key' => 'file_upload_cooldown_minutes',
                    'label' => 'File Upload Cooldown (Minutes)',
                    'val1' => (string) $uploadCooldownMinutes,
                    'desc' => 'Minimum wait time between file uploads by the same user.',
                    'uid1' => (int) $user['id'],
                    'val2' => (string) $uploadCooldownMinutes,
                    'uid2' => (int) $user['id']
                ]);

                $pdo->commit();
                $message = 'Session timeout, countdown, and file upload settings updated successfully!';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error saving settings: ' . $e->getMessage();
            }
        }
    }
}

// Fetch current values
$currentTimeoutMin = (int) getSystemSetting('session_timeout_minutes', '0');
if ($currentTimeoutMin <= 0) {
    $hours = (float) getSystemSetting('session_timeout_hours', '2');
    $currentTimeoutMin = max(1, (int) ($hours * 60));
}
$currentWarningMin = max(1, (int) getSystemSetting('session_warning_minutes', '2'));
$currentUploadCooldownMin = max(1, min(1440, (int) getSystemSetting('file_upload_cooldown_minutes', '1')));

require_once APP_ROOT . '/includes/header.php';
?>

<div class="admin-module-page">
    <div class="admin-module-heading">
        <div>
            <span class="admin-eyebrow">SECURITY CONTROL</span>
            <h2>Session Timeout & Countdown Settings</h2>
            <p>Directly control the inactivity session timeout, countdown duration, and live monitor.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Configuration Form -->
    <div class="card" style="border: 1px solid #e2e8f0;">
        <div class="settings-panel-heading" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 1.75rem;">⏱️</span>
                <div>
                    <h3 style="margin: 0; font-size: 1.15rem; color: #0f172a;">Configure Timeout &amp; Countdown Timers</h3>
                    <p style="margin: 0.25rem 0 0; font-size: 0.85rem; color: #64748b;">Customize the exact minutes for timeout and countdown popup.</p>
                </div>
            </div>
            <span class="settings-section-badge" style="background: #dbeafe; color: #1e40af; padding: 0.35rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 800;">ADMIN CONTROL</span>
        </div>

        <form method="POST" action="admin_settings_security.php">
            <input type="hidden" name="action" value="save_timeout_settings">
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label for="session_timeout_minutes" style="display: block; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem;">
                        Total Inactivity Timeout (Minutes) *
                    </label>
                    <input type="number" id="session_timeout_minutes" name="session_timeout_minutes" class="form-control" min="1" max="1440" value="<?= $currentTimeoutMin ?>" required style="padding: 0.65rem 0.85rem; border-radius: 8px; border: 1px solid #cbd5e1; width: 100%;">
                    <small style="color: #64748b; display: block; margin-top: 0.35rem;">
                        Halimbawa: <strong>120</strong> = 2 oras, <strong>60</strong> = 1 oras, <strong>30</strong> = 30 minuto.
                    </small>
                </div>

                <div class="form-group">
                    <label for="session_warning_minutes" style="display: block; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem;">
                        Countdown Warning Popup (Minutes) *
                    </label>
                    <input type="number" id="session_warning_minutes" name="session_warning_minutes" class="form-control" min="1" max="60" value="<?= $currentWarningMin ?>" required style="padding: 0.65rem 0.85rem; border-radius: 8px; border: 1px solid #cbd5e1; width: 100%;">
                    <small style="color: #64748b; display: block; margin-top: 0.35rem;">
                        Ilang minuto bago ma-timeout lilitaw ang countdown modal sa screen (halimbawa: <strong>2</strong> o <strong>5</strong> minuto).
                    </small>
                </div>

                <div class="form-group">
                    <label for="file_upload_cooldown_minutes" style="display: block; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem;">
                        File / Image Upload Cooldown (Minutes) *
                    </label>
                    <input type="number" id="file_upload_cooldown_minutes" name="file_upload_cooldown_minutes" class="form-control" min="1" max="1440" value="<?= $currentUploadCooldownMin ?>" required style="padding: 0.65rem 0.85rem; border-radius: 8px; border: 1px solid #cbd5e1; width: 100%;">
                    <small style="color: #64748b; display: block; margin-top: 0.35rem;">
                        Itakda kung ilang minuto maghihintay bago makapag-upload ulit ang parehong account. Default: 1 minuto.
                    </small>
                </div>
            </div>

            <!-- Quick Preset Helper Buttons -->
            <div class="session-timeout-presets">
                <span class="session-timeout-presets-label">Quick Presets:</span>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="button" class="session-timeout-preset-button" onclick="setPreset(15, 2)">15 Mins (2 min warning)</button>
                    <button type="button" class="session-timeout-preset-button" onclick="setPreset(30, 3)">30 Mins (3 min warning)</button>
                    <button type="button" class="session-timeout-preset-button" onclick="setPreset(60, 5)">1 Hour (5 min warning)</button>
                    <button type="button" class="session-timeout-preset-button" onclick="setPreset(120, 5)">2 Hours (5 min warning)</button>
                    <button type="button" class="session-timeout-preset-button" onclick="setPreset(240, 10)">4 Hours (10 min warning)</button>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem;">
                    💾 Save Timeout Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function setPreset(totalMin, warningMin) {
    document.getElementById('session_timeout_minutes').value = totalMin;
    document.getElementById('session_warning_minutes').value = warningMin;
}

// Sync live timer to this page card
setInterval(() => {
    const headerText = document.getElementById('header-session-timer-text');
    const pageDisplay = document.getElementById('security-page-live-countdown');
    if (headerText && pageDisplay) {
        pageDisplay.textContent = headerText.textContent;
    }
}, 1000);
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
