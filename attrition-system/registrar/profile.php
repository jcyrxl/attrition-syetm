<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/validation.php';

$activePage = 'profile';
$flash = null;
$avatarDir = __DIR__ . '/../uploads/avatars';

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A picture bigger than the server's limit arrives as an empty request.
    if (empty($_POST) && empty($_FILES)) {
        $flash = ['type' => 'error', 'msg' => 'That picture is too big — please use one that is 2 MB or smaller.'];
    }

    // ---- Contact details (name / User ID / role are Admin-managed) ----
    if ($action === 'update_contact') {
        $email   = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        if (($err = validateEmail($email)) !== null) {
            $flash = ['type' => 'error', 'msg' => $err];
        } elseif (($err = validateContactNumber($contact)) !== null) {
            $flash = ['type' => 'error', 'msg' => $err];
        } else {
            $pdo->prepare("UPDATE users SET email = ?, contact_number = ? WHERE user_id = ?")
                ->execute([$email !== '' ? $email : null, $contact !== '' ? $contact : null, $_SESSION['user_id']]);
            $user['email'] = $email;
            $user['contact_number'] = $contact;
            $flash = ['type' => 'success', 'msg' => 'Contact information updated successfully.'];
        }
    }

    // ---- Profile photo ----
    if ($action === 'upload_avatar') {
        $file = $_FILES['avatar'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $flash = ['type' => 'error', 'msg' => 'Please choose a picture first.'];
        } elseif (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > 2 * 1024 * 1024) {
            $flash = ['type' => 'error', 'msg' => 'That picture is too big — please use one that is 2 MB or smaller.'];
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $flash = ['type' => 'error', 'msg' => 'The upload did not finish. Please try again.'];
        } else {
            $info  = @getimagesize($file['tmp_name']);      // checks the real file contents, not just the name
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
            if (defined('IMAGETYPE_WEBP')) { $types[IMAGETYPE_WEBP] = 'webp'; }
            if (!$info || !isset($types[$info[2]])) {
                $flash = ['type' => 'error', 'msg' => 'Please choose a JPG, PNG or WEBP picture.'];
            } else {
                if (!is_dir($avatarDir)) { @mkdir($avatarDir, 0755, true); }
                $newName = bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
                if (!move_uploaded_file($file['tmp_name'], "$avatarDir/$newName")) {
                    $flash = ['type' => 'error', 'msg' => 'Could not save the picture. Check that the uploads/avatars folder is writable.'];
                } else {
                    if (!empty($user['profile_picture'])) { @unlink("$avatarDir/" . basename($user['profile_picture'])); }
                    $pdo->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?")->execute([$newName, $_SESSION['user_id']]);
                    $user['profile_picture'] = $newName;
                    $mePicture = $newName;
                    $flash = ['type' => 'success', 'msg' => 'Profile photo updated.'];
                }
            }
        }
    }

    if ($action === 'remove_avatar') {
        if (!empty($user['profile_picture'])) { @unlink("$avatarDir/" . basename($user['profile_picture'])); }
        $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE user_id = ?")->execute([$_SESSION['user_id']]);
        $user['profile_picture'] = null;
        $flash = ['type' => 'success', 'msg' => 'Photo removed — your initial is shown again.'];
    }
}

$roleLabel = ucwords(str_replace('_', ' ', $user['role']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Attrition Risk Prediction</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=23">
    <style>
        .profile-wrap { max-width: 640px; margin: 0 30px 30px; }
        .profile-card {
            background: white; border: 1px solid #e8edf0; border-radius: 12px;
            padding: 28px; box-shadow: 0 3px 10px rgba(0,0,0,.03); margin-bottom: 20px;
        }
        .profile-card h2 { font-size: 17px; color: #173f5f; margin-bottom: 16px; }
        .photo-row { display: flex; gap: 22px; align-items: center; flex-wrap: wrap; }
        .profile-avatar {
            width: 104px; height: 104px; border-radius: 50%; background: #e8f1f7; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; border: 3px solid #e3f1f8;
        }
        .photo-actions { flex: 1; min-width: 220px; }
        .photo-actions input[type="file"] { font-size: 13.5px; margin-bottom: 10px; max-width: 100%; }
        .photo-actions .row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
        .photo-hint { font-size: 12.5px; color: #8a959b; line-height: 1.6; }
        .profile-card .form-group { margin-bottom: 16px; }
        .profile-card .form-group label { display: block; font-size: 14px; font-weight: 600; color: #33475a; margin-bottom: 6px; }
        .profile-card .form-group input {
            width: 100%; padding: 11px; border: 1px solid #d8d8d8; border-radius: 8px; font-size: 15px; box-sizing: border-box;
        }
        .profile-card .form-group input:focus { border-color: #1E88E5; outline: none; }
        .profile-field { margin-bottom: 16px; }
        .profile-field label { display: block; font-size: 13px; color: #7a858d; margin-bottom: 4px; }
        .profile-field .value { font-size: 16px; color: #23435a; font-weight: 600; }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/layout_top.php'; ?>

    <section class="page-header">
        <div>
            <a href="<?= $homeUrl ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to <?= htmlspecialchars($homeLabel) ?></a>
            <h1>My Profile</h1>
            <p>Your photo, account details and contact information.</p>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="alert-banner alert-<?= $flash['type'] ?>" style="margin-left:30px;margin-right:30px;">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="profile-wrap">

        <div class="profile-card">
            <h2>Profile Photo</h2>
            <div class="photo-row">
                <div class="profile-avatar" id="avatarPreview"><?= avatarInner($user['profile_picture'] ?? null, $user['full_name']) ?></div>
                <div class="photo-actions">
                    <form method="POST" enctype="multipart/form-data" id="avatarForm">
                        <input type="hidden" name="action" value="upload_avatar">
                        <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp" required>
                        <div class="row"><button type="submit" class="btn-primary"><i class="fa-solid fa-camera"></i> Save Photo</button></div>
                    </form>
                    <?php if (!empty($user['profile_picture'])): ?>
                    <form method="POST" onsubmit="return confirm('Remove your photo and show your initial instead?');">
                        <input type="hidden" name="action" value="remove_avatar">
                        <div class="row"><button type="submit" class="btn-secondary"><i class="fa-solid fa-trash"></i> Remove Photo</button></div>
                    </form>
                    <?php endif; ?>
                    <p class="photo-hint">JPG, PNG or WEBP, up to 2 MB. Until you add a photo, the first letter of your name is shown.</p>
                </div>
            </div>
        </div>

        <div class="profile-card">
            <h2>Account Information</h2>
            <div class="profile-field"><label>Full Name</label><div class="value"><?= htmlspecialchars($user['full_name']) ?></div></div>
            <div class="profile-field"><label>User ID</label><div class="value"><?= htmlspecialchars($user['user_id']) ?></div></div>
            <div class="profile-field"><label>Role</label><div class="value"><?= htmlspecialchars($roleLabel) ?></div></div>
            <p style="font-size:12px;color:#8a959b;">Name and role are managed by your Administrator. Passwords and PINs are under Privacy Settings.</p>

            <h2 style="margin-top:24px;">Contact Information</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_contact">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="e.g. juan.delacruz@udm.edu.ph">
                </div>
                <div class="form-group">
                    <label>Contact Number (11 digits)</label>
                    <input type="text" name="contact_number" id="contact_number" inputmode="numeric" maxlength="11" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" placeholder="e.g. 09171234567">
                </div>
                <button type="submit" class="btn-primary">Save Contact Info</button>
            </form>
        </div>
    </div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script>
    // Contact number: digits only, 11 max.
    document.getElementById('contact_number').addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 11);
    });

    // Preview the chosen photo right away, and catch a too-big file before uploading.
    document.getElementById('avatarInput').addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            alert('That picture is too big — please choose one that is 2 MB or smaller.');
            this.value = '';
            return;
        }
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        const box = document.getElementById('avatarPreview');
        box.innerHTML = '';
        box.appendChild(img);
    });
</script>
</body>
</html>
