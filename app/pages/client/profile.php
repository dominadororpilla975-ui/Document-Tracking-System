<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/upload_security.php';
require_once APP_ROOT . '/config/audit_log.php';
requireLogin();
$user = currentUser();
$canChangePassword = !isClientUser($user);
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $currentPassword = $canChangePassword ? ($_POST['current_password'] ?? '') : '';
    $newPassword = $canChangePassword ? ($_POST['new_password'] ?? '') : '';
    $uploadedPicturePath = null;
    if ($name === '') {
        $error = 'Name is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($newPassword !== '' && (!password_verify($currentPassword, $user['password'] ?? '') || strlen($newPassword) < 8)) {
        $error = 'Enter the correct current password and a new password with at least 8 characters.';
    } else {
        $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $emailStmt->execute(['email' => $email, 'id' => (int) $user['id']]);
        if ($emailStmt->fetchColumn()) {
            $error = 'That email address is already in use.';
        }

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $picture = $_FILES['profile_picture'];
            $allowedMimeTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            $imageInfo = $picture['error'] === UPLOAD_ERR_OK ? getimagesize($picture['tmp_name']) : false;
            $mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
            if ($picture['error'] !== UPLOAD_ERR_OK || $picture['size'] > 2 * 1024 * 1024) {
                $error = 'Profile image upload failed or is larger than 2 MB.';
            } elseif (!isset($allowedMimeTypes[$mimeType])) {
                $error = 'Profile image must be a JPG, PNG, GIF, or WEBP image.';
            } else {
                $retryAfter = enforceUploadCooldown($pdo, (int) $user['id']);
                if ($retryAfter > 0) {
                    $error = uploadCooldownMessage($retryAfter);
                } else {
                $uploadDirectory = APP_ROOT . '/image/uploads/profiles';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    $error = 'Unable to create the profile image upload directory.';
                } else {
                    $fileName = 'profile_' . bin2hex(random_bytes(12)) . '.' . $allowedMimeTypes[$mimeType];
                    $targetPath = $uploadDirectory . '/' . $fileName;
                    if (!move_uploaded_file($picture['tmp_name'], $targetPath)) {
                        $error = 'Unable to save the uploaded profile image.';
                    } else {
                        $uploadedPicturePath = 'image/uploads/profiles/' . $fileName;
                    }
                }
                }
            }
        }

        if ($error === '') {
            $oldProfileStmt = $pdo->prepare('SELECT name, email, contact_number, profile_picture FROM users WHERE id = :id LIMIT 1');
            $oldProfileStmt->execute(['id' => (int) $user['id']]);
            $oldProfile = $oldProfileStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldProfile) {
                $error = 'Unable to find your account to update.';
            } else {
                $newProfile = [
                    'name' => $name,
                    'email' => $email,
                    'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                ];
                $oldValues = [];
                $newValues = [];
                foreach ($newProfile as $field => $value) {
                    $oldValue = $oldProfile[$field] !== '' ? $oldProfile[$field] : null;
                    if ($oldValue !== $value) {
                        $oldValues[$field] = $oldValue;
                        $newValues[$field] = $value;
                    }
                }
                if ($uploadedPicturePath !== null) {
                    $oldValues['profile_picture'] = !empty($oldProfile['profile_picture']) ? 'set' : null;
                    $newValues['profile_picture'] = 'updated';
                }
                if ($newPassword !== '') {
                    $oldValues['password'] = 'unchanged';
                    $newValues['password'] = 'changed';
                }

                try {
                    $pdo->beginTransaction();
                    $params = [
                        'name' => $name,
                        'email' => $email,
                        'contact_number' => $newProfile['contact_number'],
                        'id' => (int) $user['id'],
                    ];
                    $sql = 'UPDATE users SET name = :name, email = :email, contact_number = :contact_number';
                    if ($uploadedPicturePath !== null) {
                        $sql .= ', profile_picture = :profile_picture';
                        $params['profile_picture'] = $uploadedPicturePath;
                    }
                    if ($newPassword !== '') {
                        $sql .= ', password = :password';
                        $params['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                    }
                    $sql .= ' WHERE id = :id';
                    $pdo->prepare($sql)->execute($params);

                    if ($oldValues !== [] && !auditLog(
                        $pdo,
                        (int) $user['id'],
                        'PROFILE_UPDATED',
                        'User updated profile fields: ' . implode(', ', array_keys($newValues)),
                        null,
                        null,
                        $oldValues,
                        $newValues
                    )) {
                        throw new RuntimeException('Profile audit event could not be recorded.');
                    }
                    $pdo->commit();
                    $success = 'Profile and contact information updated successfully.';
                    $user = currentUser();
                } catch (PDOException | RuntimeException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Profile update failed for user_id=' . (int) $user['id'] . ': ' . $exception->getMessage());
                    $error = 'Unable to update your profile. Please try again.';
                }
            }
        }
    }
}
require_once APP_ROOT . '/includes/header.php';
?>
<div class="admin-module-page profile-page">
    <div class="admin-module-heading"><div><span class="admin-eyebrow">ACCOUNT SETTINGS</span><h2>Manage Account</h2><p>Keep your personal and contact information up to date.</p></div></div>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <section class="admin-panel profile-panel">
        <form method="POST" enctype="multipart/form-data" class="admin-profile-form">
            <div class="profile-account-hero">
                <div class="profile-account-avatar">
                    <?php if (!empty($user['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?>
                    <?php endif; ?>
                </div>
                <div class="profile-account-identity">
                    <h3><?= htmlspecialchars($user['name'] ?? 'User') ?></h3>
                    <p><?= htmlspecialchars($user['email'] ?? '') ?></p>
                    <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $user['role'] ?? 'client'))) ?></span>
                </div>
            </div>
            <div class="profile-form-section">
                <div class="profile-form-heading"><span class="profile-form-icon"><i class="bi bi-person" aria-hidden="true"></i></span><div><h3>Personal information</h3><p>Update the details associated with your account.</p></div></div>
                <div class="profile-form-layout">
                    <label class="profile-picture-field">
                        <span>Profile photo</span>
                        <?php if (!empty($user['profile_picture'])): ?>
                            <img class="profile-picture-preview" src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="Current profile image">
                        <?php else: ?>
                            <span class="profile-picture-placeholder"><i class="bi bi-camera" aria-hidden="true"></i></span>
                        <?php endif; ?>
                        <input type="file" name="profile_picture" accept="image/jpeg,image/png,image/gif,image/webp" aria-label="Choose profile photo">
                        <small>JPG, PNG, GIF, or WEBP. Maximum 2 MB.</small>
                    </label>
                    <div class="profile-form-grid">
                        <label>Full name<input name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required autocomplete="name"></label>
                        <label>Email address<input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required autocomplete="email"></label>
                        <label>Phone number<input type="tel" name="contact_number" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" autocomplete="tel" placeholder="e.g. 09123456789"></label>
                        <label>Role / Office<input value="<?= htmlspecialchars(ucwords(str_replace('_', ' ', $user['role'] ?? 'client')) . ' · ' . ($user['department_name'] ?? 'System')) ?>" readonly></label>
                    </div>
                </div>
            </div>
            <?php if ($canChangePassword): ?>
                <div class="profile-form-section profile-password-section">
                    <div class="profile-form-heading"><span class="profile-form-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><div><h3>Change password</h3><p>Leave these fields blank to keep your current password.</p></div></div>
                    <div class="profile-form-grid">
                        <label>Current password<input type="password" name="current_password" autocomplete="current-password"></label>
                        <label>New password<input type="password" name="new_password" minlength="8" autocomplete="new-password"><small>Use at least 8 characters.</small></label>
                    </div>
                </div>
            <?php endif; ?>
            <div class="profile-form-actions"><button class="admin-primary-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save changes</button></div>
        </form>
    </section>
</div>
<script>
    (() => {
        const profilePictureInput = document.querySelector('input[name="profile_picture"]');
        const profilePicturePreview = document.querySelector('.profile-picture-preview, .profile-picture-placeholder');
        if (profilePictureInput && profilePicturePreview) {
            profilePictureInput.addEventListener('change', () => {
                const [file] = profilePictureInput.files || [];
                if (!file || !file.type.startsWith('image/')) return;
                const previewUrl = URL.createObjectURL(file);
                if (profilePicturePreview.tagName === 'IMG') {
                    profilePicturePreview.src = previewUrl;
                } else {
                    const preview = document.createElement('img');
                    preview.className = 'profile-picture-preview';
                    preview.alt = 'Selected profile image preview';
                    preview.src = previewUrl;
                    profilePicturePreview.replaceWith(preview);
                }
            });
        }

        const currentPassword = document.querySelector('input[name="current_password"]');
        const newPassword = document.querySelector('input[name="new_password"]');
        if (!currentPassword || !newPassword) {
            return;
        }

        const synchronizePasswordRequirement = () => {
            currentPassword.required = newPassword.value !== '';
        };
        newPassword.addEventListener('input', synchronizePasswordRequirement);
        synchronizePasswordRequirement();
    })();
</script>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>