<?php
declare(strict_types=1);

const PROFILE_PHOTO_MAX_BYTES = 2097152;
const PROFILE_PHOTO_MIN_DIMENSION = 100;

function profile_display_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '—';

    try {
        return (new DateTimeImmutable($value))->format('F j, Y · g:i A');
    } catch (Throwable) {
        return $value;
    }
}

function profile_status_badge(string $status): string
{
    $labels = [
        'active' => ['success', 'Active'],
        'pending_review' => ['warning', 'Pending Review'],
        'needs_correction' => ['danger', 'Corrections Required'],
        'suspended' => ['secondary', 'Suspended'],
        'pending_setup' => ['warning', 'Pending Setup'],
    ];
    [$color, $label] = $labels[$status] ?? ['secondary', ucwords(str_replace('_', ' ', $status))];
    return '<span class="badge text-bg-' . e($color) . '">' . e($label) . '</span>';
}

function profile_context(array $user): array
{
    $roles = user_roles((int)$user['id']);
    $definitions = role_definitions();
    $roleNames = array_map(static fn(string $role): string => $definitions[$role][0] ?? $role, $roles);

    return [
        'roles' => $roles,
        'role_text' => $roleNames ? implode(', ', $roleNames) : 'Unassigned',
        'account_type' => $user['user_kind'] === 'applicant' ? 'External Applicant' : 'DENR Personnel',
        'initial' => strtoupper(substr(trim((string)$user['name']), 0, 1)) ?: 'U',
        'username' => trim((string)($user['username'] ?? '')) ?: 'Not assigned',
        'office_level' => trim((string)($user['office_level'] ?? '')) ?: 'Not assigned',
    ];
}

function initialize_profile_account_fields(int $userId, string $userKind): void
{
    try {
        $username = 'fpuser' . str_pad((string)$userId, 6, '0', STR_PAD_LEFT);
        $office = $userKind === 'internal' ? 'Regional Office' : 'External Applicant';
        db()->prepare(
            "UPDATE users
             SET username=COALESCE(NULLIF(username,''),?),
                 office_level=COALESCE(NULLIF(office_level,''),?)
             WHERE id=?"
        )->execute([$username, $office, $userId]);
    } catch (PDOException $e) {
        // Profile migration may not yet be installed during staged deployment.
        error_log('FP-OMS profile field initialization skipped: ' . $e->getMessage());
    }
}

function profile_photo_url(array $user): ?string
{
    $path = trim((string)($user['profile_photo'] ?? ''));
    if ($path === '') return null;
    return url('profile_photo', ['v' => substr(hash('sha256', $path), 0, 12)]);
}

function profile_avatar_html(array $user, string $className, string $alt = ''): string
{
    $photo = profile_photo_url($user);
    if ($photo !== null) {
        return '<span class="' . e($className) . ' profile-avatar-has-photo"><img src="' . e($photo) . '" alt="' . e($alt) . '"></span>';
    }

    $initial = strtoupper(substr(trim((string)$user['name']), 0, 1)) ?: 'U';
    return '<span class="' . e($className) . '" aria-hidden="true">' . e($initial) . '</span>';
}

function profile_hero_html(array $user, array $context): string
{
    return '<section class="profile-hero">'
        . profile_avatar_html($user, 'profile-avatar-lg', 'Profile photo of ' . (string)$user['name'])
        . '<div class="profile-hero-copy"><h2>' . e((string)$user['name']) . '</h2>'
        . '<div class="profile-role">' . e((string)$context['role_text']) . '</div>'
        . '<div class="profile-meta"><span><i class="bi bi-building"></i>' . e((string)$context['office_level']) . '</span>'
        . '<span><i class="bi bi-envelope"></i>' . e((string)($user['email'] ?? '')) . '</span></div></div>'
        . '<div class="profile-hero-actions"><a class="btn btn-outline-primary btn-sm" href="' . e(url('change_password')) . '">'
        . '<i class="bi bi-shield-lock me-1"></i>Change Password</a></div></section>';
}

function profile_tabs_html(string $active): string
{
    $viewClass = $active === 'view' ? ' active' : '';
    $editClass = $active === 'edit' ? ' active' : '';

    return '<div class="profile-tabs">'
        . '<a class="profile-tab' . $viewClass . '" href="' . e(url('profile')) . '"><i class="bi bi-person-vcard me-1"></i>View Profile</a>'
        . '<a class="profile-tab' . $editClass . '" href="' . e(url('profile', ['tab'=>'edit'])) . '"><i class="bi bi-pencil-square me-1"></i>Edit Profile</a>'
        . '</div>';
}

function profile_name_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function profile_upload_details(?array $file): array
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['none' => true];
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        return ['error' => 'Profile photo upload did not complete. Please try again.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > PROFILE_PHOTO_MAX_BYTES) {
        return ['error' => 'Profile photo must be 2 MB or smaller.'];
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['error' => 'Only JPG, PNG, and WEBP profile photos are allowed.'];
    }

    $dimensions = @getimagesize((string)$file['tmp_name']);
    if (!is_array($dimensions) || (int)$dimensions[0] < PROFILE_PHOTO_MIN_DIMENSION || (int)$dimensions[1] < PROFILE_PHOTO_MIN_DIMENSION) {
        return ['error' => 'Profile photo must be a valid image at least 100×100 pixels.'];
    }

    return [
        'file' => $file,
        'mime' => $mime,
        'extension' => $allowed[$mime],
        'width' => (int)$dimensions[0],
        'height' => (int)$dimensions[1],
        'size' => $size,
    ];
}

function profile_storage_root(): string
{
    return PROJECT_ROOT . '/storage/profile_photos';
}

function store_profile_photo(int $userId, array $upload): array
{
    $relativeDir = 'profile_photos/' . $userId;
    $absoluteDir = PROJECT_ROOT . '/storage/' . $relativeDir;

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0700, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Profile photo storage directory could not be created.');
    }

    $filename = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
    $relative = $relativeDir . '/' . $filename;
    $absolute = PROJECT_ROOT . '/storage/' . $relative;

    if (!move_uploaded_file((string)$upload['file']['tmp_name'], $absolute)) {
        throw new RuntimeException('Profile photo could not be saved.');
    }

    @chmod($absolute, 0600);

    return ['relative' => $relative, 'absolute' => $absolute];
}

function delete_profile_photo_relative(?string $relative): void
{
    $relative = trim((string)$relative);
    if ($relative === '') return;

    $storage = realpath(PROJECT_ROOT . '/storage');
    $absolute = realpath(PROJECT_ROOT . '/storage/' . $relative);

    if (!$storage || !$absolute || !str_starts_with($absolute, $storage . DIRECTORY_SEPARATOR)) return;
    if (!str_contains(str_replace('\\', '/', $relative), 'profile_photos/')) return;
    if (is_file($absolute)) @unlink($absolute);
}

function process_profile_update(array $user, array $data, array $files): array
{
    $name = trim((string)($data['name'] ?? ''));
    $emailInput = strtolower(trim((string)($data['email'] ?? '')));
    $email = $emailInput === '' ? strtolower((string)($user['email'] ?? '')) : $emailInput;
    $errors = [];

    $nameLength = profile_name_length($name);
    if ($nameLength < 3 || $nameLength > 160) {
        $errors[] = 'Full name is required and must be between 3 and 160 characters.';
    }

    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
        $errors[] = 'Email address must be valid when provided.';
    }

    if ($email !== '') {
        $duplicate = query_one('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1', [$email, (int)$user['id']]);
        if ($duplicate) $errors[] = 'Another account already uses this email address.';
    }

    $upload = profile_upload_details($files['profile_photo'] ?? null);
    if (isset($upload['error'])) $errors[] = $upload['error'];

    if ($errors) return $errors;

    $newStored = null;
    $pdo = db();

    try {
        if (!isset($upload['none'])) {
            $newStored = store_profile_photo((int)$user['id'], $upload);
        }

        $pdo->beginTransaction();
        $locked = query_one('SELECT id,user_kind,name,email,profile_photo FROM users WHERE id=? FOR UPDATE', [(int)$user['id']]);
        if (!$locked) throw new RuntimeException('User account was not found.');

        $newPhoto = $newStored['relative'] ?? (string)($locked['profile_photo'] ?? '');

        $pdo->prepare('UPDATE users SET name=?,email=?,profile_photo=? WHERE id=?')
            ->execute([$name, $email, $newPhoto !== '' ? $newPhoto : null, (int)$user['id']]);

        if ((string)$locked['user_kind'] === 'applicant') {
            $pdo->prepare('UPDATE applicant_registrations SET representative_name=? WHERE user_id=?')
                ->execute([$name, (int)$user['id']]);
        }

        audit((int)$user['id'], 'profile.updated', 'user', (int)$user['id'], [
            'name_changed' => $name !== (string)$locked['name'],
            'email_changed' => $email !== strtolower((string)$locked['email']),
            'photo_replaced' => $newStored !== null,
        ]);

        $pdo->commit();

        if ($newStored !== null && !empty($locked['profile_photo']) && $locked['profile_photo'] !== $newStored['relative']) {
            delete_profile_photo_relative((string)$locked['profile_photo']);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return [];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($newStored !== null) @unlink($newStored['absolute']);

        if ($e->getCode() === '23000') return ['Another account already uses this email address.'];
        error_log('Profile update failed: ' . $e->getMessage());
        return ['Profile changes could not be saved. Please try again.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($newStored !== null) @unlink($newStored['absolute']);
        error_log('Profile update failed: ' . $e->getMessage());
        return ['Profile changes could not be saved. Please try again.'];
    }
}

function remove_profile_photo(array $user): array
{
    $pdo = db();

    try {
        $pdo->beginTransaction();
        $locked = query_one('SELECT id,profile_photo FROM users WHERE id=? FOR UPDATE', [(int)$user['id']]);
        if (!$locked) throw new RuntimeException('User account was not found.');

        $old = trim((string)($locked['profile_photo'] ?? ''));
        $pdo->prepare('UPDATE users SET profile_photo=NULL WHERE id=?')->execute([(int)$user['id']]);
        audit((int)$user['id'], 'profile.photo_removed', 'user', (int)$user['id']);
        $pdo->commit();

        if ($old !== '') delete_profile_photo_relative($old);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Profile photo removal failed: ' . $e->getMessage());
        return ['Profile photo could not be removed. Please try again.'];
    }
}

function send_current_profile_photo(array $user): never
{
    $relative = trim((string)($user['profile_photo'] ?? ''));
    if ($relative === '') show_error('No profile photo is available.', 404);

    $root = realpath(profile_storage_root());
    $path = realpath(PROJECT_ROOT . '/storage/' . $relative);

    if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        show_error('Profile photo is unavailable.', 404);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        show_error('Profile photo is invalid.', 404);
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function view_profile(): void
{
    $user = require_user();
    $context = profile_context($user);
    $registration = $user['user_kind'] === 'applicant' ? registration_for_user((int)$user['id']) : null;

    $html = profile_hero_html($user, $context)
        . profile_tabs_html('view')
        . '<section class="panel profile-information-card"><div class="profile-section-title"><i class="bi bi-info-circle"></i><h3>Account Information</h3></div>'
        . '<div class="profile-info-grid">'
        . '<div class="profile-info-item"><span class="profile-info-label">Full Name</span><div class="profile-info-value">' . e((string)$user['name']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Email Address</span><div class="profile-info-value">' . e((string)($user['email'] ?? '—')) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Username</span><div class="profile-info-value">' . e((string)$context['username']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Role</span><div class="profile-info-value">' . e((string)$context['role_text']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Office Level / Assignment</span><div class="profile-info-value">' . e((string)$context['office_level']) . '</div></div>'
        . ($user['user_kind'] === 'internal' ? '<div class="profile-info-item"><span class="profile-info-label">Office / Station</span><div class="profile-info-value">' . e((string)($user['office_station'] ?? 'Not assigned')) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Contact Number</span><div class="profile-info-value">' . e((string)($user['contact_number'] ?? 'Not provided')) . '</div></div>'
            : '')
        . '<div class="profile-info-item"><span class="profile-info-label">Account Status</span><div class="profile-info-value">' . profile_status_badge((string)$user['status']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Last Login</span><div class="profile-info-value">' . e(profile_display_date($user['last_login_at'] ?? null)) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Activated</span><div class="profile-info-value">' . e(profile_display_date($user['activated_at'] ?? null)) . '</div></div>'
        . '</div></section>';

    if ($registration) {
        $html .= '<section class="panel profile-information-card mt-4"><div class="profile-section-title"><i class="bi bi-building"></i><h3>Applicant Information</h3></div>'
            . '<div class="profile-info-grid">'
            . '<div class="profile-info-item"><span class="profile-info-label">Company / Proponent</span><div class="profile-info-value">' . e((string)$registration['company_name']) . '</div></div>'
            . '<div class="profile-info-item"><span class="profile-info-label">Contact Number</span><div class="profile-info-value">' . e((string)$registration['contact_number']) . '</div></div>'
            . '<div class="profile-info-item profile-info-wide"><span class="profile-info-label">Company Address</span><div class="profile-info-value">' . e((string)$registration['company_address']) . '</div></div>'
            . '</div></section>';
    }

    render_page('Profile', $html);
}

function profile_photo_editor_modals(): string
{
    return '<div class="modal fade" id="profileCameraModal" tabindex="-1" aria-labelledby="profileCameraModalLabel" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header">'
        . '<h2 class="modal-title fs-5" id="profileCameraModalLabel">Use Camera</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><div id="profileCameraAlert" class="alert alert-warning d-none" role="alert"></div>'
        . '<div class="profile-camera-frame"><video id="profileCameraVideo" autoplay playsinline muted></video></div>'
        . '<div class="row g-2 mt-2"><div class="col-md-8"><label class="form-label" for="profileCameraSelect">Camera</label>'
        . '<select class="form-select" id="profileCameraSelect"></select></div><div class="col-md-4 d-flex align-items-end">'
        . '<button class="btn btn-outline-primary w-100" type="button" id="profileCameraRestart"><i class="bi bi-arrow-clockwise me-1"></i>Restart Camera</button></div></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="button" class="btn btn-primary" id="profileCameraCapture"><i class="bi bi-camera me-1"></i>Capture Photo</button></div>'
        . '</div></div></div>'
        . '<div class="modal fade" id="profileAdjustModal" tabindex="-1" aria-labelledby="profileAdjustModalLabel" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header">'
        . '<h2 class="modal-title fs-5" id="profileAdjustModalLabel">Adjust Profile Photo</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><p class="small text-secondary">Drag to reposition. Use zoom and flip controls. The saved image is cropped to a square and exported as a 600×600 JPEG.</p>'
        . '<div class="profile-crop-stage"><canvas id="profileCropCanvas" width="600" height="600" aria-label="Profile photo crop preview"></canvas></div>'
        . '<div class="row g-3 align-items-end mt-1"><div class="col-md-7"><label class="form-label" for="profileCropZoom">Zoom</label>'
        . '<input class="form-range" type="range" id="profileCropZoom" min="1" max="3" step="0.01" value="1"></div>'
        . '<div class="col-md-5 d-flex gap-2"><button class="btn btn-outline-primary flex-fill" type="button" id="profileCropFlip"><i class="bi bi-symmetry-vertical me-1"></i>Flip</button>'
        . '<button class="btn btn-outline-secondary flex-fill" type="button" id="profileCropReset"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button></div></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="button" class="btn btn-primary" id="profileCropApply"><i class="bi bi-check2-circle me-1"></i>Use Photo</button></div>'
        . '</div></div></div>';
}

function view_edit_profile(array $errors = []): void
{
    $user = require_user();
    $context = profile_context($user);
    $name = form_value('name', ['name' => (string)$user['name']]);
    $email = form_value('email', ['email' => (string)($user['email'] ?? '')]);
    $hasPhoto = trim((string)($user['profile_photo'] ?? '')) !== '';

    $photoPreview = $hasPhoto
        ? '<img id="profilePhotoPreviewImage" src="' . e((string)profile_photo_url($user)) . '" alt="Current profile photo">'
        : '<span id="profilePhotoPreviewInitial">' . e((string)$context['initial']) . '</span>';

    $html = profile_hero_html($user, $context)
        . profile_tabs_html('edit')
        . '<form id="profileEditForm" method="post" action="' . e(url('profile', ['tab'=>'edit'])) . '" enctype="multipart/form-data">' . csrf_field()
        . '<input type="hidden" name="profile_action" value="update">'
        . '<input class="d-none" type="file" id="profilePhotoUpload" name="profile_photo" accept="image/jpeg,image/png,image/webp">'
        . '<input class="d-none" type="file" id="profilePhotoSource" accept="image/jpeg,image/png,image/webp">'
        . '<div class="row g-4"><div class="col-lg-4"><section class="panel profile-photo-card">'
        . '<div class="profile-section-title"><i class="bi bi-camera"></i><h3>Profile Photo</h3></div>'
        . '<div class="profile-photo-body"><div class="profile-photo-preview" id="profilePhotoPreview">' . $photoPreview . '</div>'
        . '<div><strong class="d-block">' . e((string)$user['name']) . '</strong><span class="small text-secondary">' . e((string)$context['role_text']) . '</span></div>'
        . '<div class="profile-photo-source"><span class="profile-info-label">Add / Change Profile Photo</span>'
        . '<div class="row g-2"><div class="col-sm-6 col-lg-12 col-xl-6"><button class="btn btn-outline-primary w-100" type="button" id="profileChoosePhoto"><i class="bi bi-image me-1"></i>Choose Photo</button></div>'
        . '<div class="col-sm-6 col-lg-12 col-xl-6"><button class="btn btn-outline-primary w-100" type="button" id="profileUseCamera" data-bs-toggle="modal" data-bs-target="#profileCameraModal"><i class="bi bi-camera me-1"></i>Use Camera</button></div></div>'
        . '<div class="form-text mt-2">JPG, PNG or WEBP · max 2 MB · minimum 100×100 pixels.</div>'
        . '<div id="profilePhotoPending" class="alert alert-success small mt-3 mb-0 d-none">Adjusted photo is ready. Save Changes to upload it.</div></div>'
        . ($hasPhoto ? '<button class="btn btn-outline-danger w-100 mt-3" type="button" data-bs-toggle="modal" data-bs-target="#profileRemovePhotoModal"><i class="bi bi-trash me-1"></i>Remove Current Photo</button>' : '')
        . '</div></section></div>'
        . '<div class="col-lg-8"><section class="panel profile-information-card"><div class="profile-section-title"><i class="bi bi-pencil-square"></i><h3>Edit Profile Information</h3></div>'
        . '<div class="profile-edit-body">' . errors_html($errors)
        . '<div class="row g-3">'
        . '<div class="col-12">' . field('name', 'Full Name', $name, 'text', true, 'minlength="3" maxlength="160" autocomplete="name"') . '</div>'
        . '<div class="col-12">' . field('email', 'Email Address', $email, 'email', false, 'maxlength="190" autocomplete="email" placeholder="Optional — leave blank to keep current email"') . '</div>'
        . '<div class="col-md-6"><label class="form-label">Username</label><div class="profile-readonly-field">' . e((string)$context['username']) . '</div></div>'
        . '<div class="col-md-6"><label class="form-label">Role</label><div class="profile-readonly-field">' . e((string)$context['role_text']) . '</div></div>'
        . '<div class="col-md-6"><label class="form-label">Office Level / Assignment</label><div class="profile-readonly-field">' . e((string)$context['office_level']) . '</div></div>'
        . ($user['user_kind'] === 'internal' ? '<div class="col-md-6"><label class="form-label">Office / Station</label><div class="profile-readonly-field">' . e((string)($user['office_station'] ?? 'Not assigned')) . '</div></div>'
        . '<div class="col-md-6"><label class="form-label">Contact Number</label><div class="profile-readonly-field">' . e((string)($user['contact_number'] ?? 'Not provided')) . '</div></div>'
            : '')
        . '<div class="col-md-6"><label class="form-label">Account Status</label><div class="profile-readonly-field">' . profile_status_badge((string)$user['status']) . '</div></div>'
        . '</div><div class="alert alert-light border small mt-3 mb-0"><i class="bi bi-shield-check me-1"></i>Username, role, office assignment and account status are controlled account fields and cannot be changed from this page.</div>'
        . ($user['user_kind'] === 'applicant' ? '<div class="alert alert-info small mt-3 mb-0">Changing your full name also updates the representative name in your applicant registration.</div>' : '')
        . '<div class="profile-edit-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save Changes</button>'
        . '<a class="btn btn-outline-secondary" href="' . e(url('profile')) . '">Cancel</a></div>'
        . '</div></section></div></div></form>'
        . profile_photo_editor_modals();

    if ($hasPhoto) {
        $html .= '<div class="modal fade" id="profileRemovePhotoModal" tabindex="-1" aria-labelledby="profileRemovePhotoModalLabel" aria-hidden="true">'
            . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header">'
            . '<h2 class="modal-title fs-5" id="profileRemovePhotoModalLabel">Remove Profile Photo?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            . '<div class="modal-body">Your current profile photo will be permanently removed. Your first-letter avatar will be shown instead.</div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
            . '<form method="post" action="' . e(url('profile', ['tab'=>'edit'])) . '">' . csrf_field()
            . '<input type="hidden" name="profile_action" value="remove_photo">'
            . '<button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Remove Photo</button></form>'
            . '</div></div></div></div>';
    }

    render_page('Edit Profile', $html);
}
