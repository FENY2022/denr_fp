<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

try {
    // Canonicalize old index.php?page=... bookmarks without redirecting POST data.
    $requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
        && str_ends_with($requestPath, '/index.php')) {
        $legacyPage = (string)($_GET['page'] ?? 'landing');
        $legacyParams = $_GET;
        unset($legacyParams['page']);
        header('Location: ' . url(str_replace('-', '_', $legacyPage), $legacyParams), true, 302);
        exit;
    }
    $page = str_replace('-', '_', (string)($_GET['page'] ?? 'landing'));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $post = $method === 'POST';
    $loggedIn = current_user();

    if ($loggedIn && (int)$loggedIn['must_change_password'] === 1 &&
        !in_array($page, ['change_password','logout'], true)) {
        go('change_password');
    }

    switch ($page) {
        case 'landing':
            view_landing();
            break;
        case 'setup_admin':
            if ($post) {
                check_csrf();
                $errors = create_initial_super_admin($_POST);
                if ($errors) {
                    view_initial_admin_setup($errors);
                } else {
                    flash('success', 'RICTU Super Administrator created. Sign in with the new account; a password change is required on first login.');
                    go('login');
                }
            } else {
                view_initial_admin_setup();
            }
            break;
        case 'login':
            if ($post) {
                check_csrf();
                $hintEmail = reviewer_login_hint_email((string)($_POST['hint'] ?? ''));
                $email = $hintEmail ?? safe_post('email',190);
                if (authenticate($email,(string)($_POST['password'] ?? ''))) {
                    $loggedIn = current_user();
                    $nextPage = (string)($_POST['next'] ?? '');
                    if ($loggedIn && in_array($nextPage, ['review-queue','permit-initial-queue'],true)
                        && has_role($loggedIn,'lpdd_fus')) go(str_replace('-', '_', $nextPage));
                    if ($loggedIn && $nextPage === 'permit-ro-inbox'
                        && has_role($loggedIn,'ro_receiving')) go('permit_ro_inbox');
                    if ($loggedIn && $nextPage === 'permit-applications'
                        && $loggedIn['user_kind'] === 'applicant'
                        && $loggedIn['status'] === 'active') go('permit_applications');
                    go('dashboard');
                }
                if (registration_otp_pending_client()) go('verify_registration_otp');
                view_login(['Invalid credentials or too many recent failed attempts. Try again later.']);
            } else view_login();
            break;
        case 'forgot_password':
            if ($post) {
                check_csrf();
                request_password_reset(safe_post('email', 190));
                flash('info', 'Please check your email for a password reset link. If it is not in your inbox, check your Spam or Junk folder. For security, a link is sent only when the email address matches a registered account, and it expires in 30 minutes.');
                go('login');
            } else {
                view_forgot_password();
            }
            break;
        case 'reset_password':
            $token = strtolower(trim((string)($_GET['token'] ?? '')));
            if ($post) {
                check_csrf();
                $errors = complete_password_reset(
                    $token,
                    (string)($_POST['password'] ?? ''),
                    (string)($_POST['confirm_password'] ?? '')
                );
                if ($errors) {
                    view_reset_password($token, $errors);
                } else {
                    flash('success', 'Password reset successfully. You can now sign in with your new password.');
                    go('login');
                }
            } else {
                view_reset_password($token);
            }
            break;
        case 'register':
            if ($post) {
                check_csrf();
                $values = registration_input();
                $errors = register_applicant($values,safe_post('email',190),(string)($_POST['password'] ?? ''),(string)($_POST['password_confirm'] ?? ''),$_FILES,$_POST);
                if (!$errors) {
                    flash('info', 'Please open the verification email and click the confirmation link.');
                    go('verify_registration_otp');
                }
                view_register($errors);
            } else view_register();
            break;
        case 'confirm_registration_email':
            if ($post) show_error('Email confirmation requires a link.',405);
            $errors = registration_otp_verify(strtolower(trim((string)($_GET['token'] ?? ''))));
            if ($errors) {
                flash('warning',$errors[0]);
                go('login');
            }
            $_SESSION['registration_verified_modal'] = [
                'ack_delivery_failed'=>!empty($_SESSION['registration_ack_delivery_failed']),
                'applicant_name'=>(string)($_SESSION['registration_verified_applicant_name'] ?? '')
            ];
            unset($_SESSION['registration_ack_delivery_failed'],$_SESSION['registration_verified_applicant_name']);
            go('login');
        case 'verify_registration_otp':
            if ($loggedIn) go('dashboard');
            if ($post) {
                check_csrf();
                if (($_POST['otp_action'] ?? '') !== 'resend') show_error('Invalid verification action.',400);
                $errors = registration_otp_resend();
                if (!$errors) {
                    flash('info','A new verification link has been sent to your email.');
                    go('verify_registration_otp');
                }
                view_verify_registration_otp($errors);
            } else view_verify_registration_otp();
            break;
        case 'register_draft_upload':
            if (!$post) registration_draft_json(['error'=>'Upload requires POST.'],405);
            if ($loggedIn) registration_draft_json(['error'=>'Sign out before registering.'],403);
            check_csrf();
            $result = registration_draft_upload($_POST,$_FILES);
            registration_draft_json($result, isset($result['error']) ? 422 : 200);
        case 'register_draft_delete':
            if (!$post) registration_draft_json(['error'=>'Removal requires POST.'],405);
            if ($loggedIn) registration_draft_json(['error'=>'Sign out before registering.'],403);
            check_csrf();
            $draftId = filter_var($_POST['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
            $result = registration_draft_delete((int)$draftId);
            registration_draft_json($result, isset($result['error']) ? 422 : 200);
        case 'register_draft_rename':
            if (!$post) registration_draft_json(['error'=>'Rename requires POST.'],405);
            if ($loggedIn) registration_draft_json(['error'=>'Sign out before registering.'],403);
            check_csrf();
            $draftId = filter_var($_POST['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
            $result = registration_draft_rename((int)$draftId,(string)($_POST['name'] ?? ''));
            registration_draft_json($result, isset($result['error']) ? 422 : 200);
        case 'register_draft_clear':
            if (!$post) registration_draft_json(['error'=>'Clear requires POST.'],405);
            if ($loggedIn) registration_draft_json(['error'=>'Sign out before registering.'],403);
            check_csrf();
            $result = registration_draft_clear();
            registration_draft_json($result, isset($result['error']) ? 422 : 200);
        case 'dashboard':
            view_dashboard();
            break;
        case 'profile':
            $user = require_user();
            $tab = strtolower(trim((string)($_GET['tab'] ?? 'view')));
            if ($tab === 'edit') {
                if ($post) {
                    check_csrf();
                    $action = (string)($_POST['profile_action'] ?? 'update');
                    $errors = $action === 'remove_photo'
                        ? remove_profile_photo($user)
                        : process_profile_update($user, $_POST, $_FILES);
                    if ($errors) {
                        view_edit_profile($errors);
                    } else {
                        flash('success', $action === 'remove_photo' ? 'Profile photo removed successfully.' : 'Profile updated successfully.');
                        go('profile', ['tab'=>'edit']);
                    }
                } else {
                    view_edit_profile();
                }
            } else {
                if ($post) show_error('Profile updates must be submitted from the Edit Profile tab.', 405);
                view_profile();
            }
            break;
        case 'edit_profile':
            if ($post) show_error('Use /profile?tab=edit to update your profile.', 405);
            go('profile', ['tab'=>'edit']);
        case 'profile_photo':
            send_current_profile_photo(require_user());
            break;
        case 'notifications':
            view_notifications();
            break;
        case 'notification_open':
            $user = require_user();
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid notification ID.', 400);
            notification_open($user, (int)$id);
            break;
        case 'notifications_read_all':
            if (!$post) show_error('This action requires a submitted form.', 405);
            $user = require_user();
            check_csrf();
            mark_all_notifications_read($user);
            go('notifications');
            break;
        case 'my_registration':
            view_applicant_registration(require_user());
            break;
        case 'registration_update':
            $user = require_user();
            require_kind($user,'applicant');
            if (!$post) go('my_registration');
            check_csrf();
            $errors = revise_registration($user,registration_input(),$_FILES,$_POST);
            if ($errors) view_applicant_registration($user,$errors);
            else {
                flash('success','Corrections resubmitted for LPDD-FUS review.');
                go('my_registration');
            }
            break;
        // Annex A Step 2: activated applicant -> LPDD-FUS initial review.
        case 'permit_applications':
            if ($post) show_error('This page is read-only.',405);
            view_step2_applications();
            break;
        case 'permit_draft_upload':
        case 'permit_draft_delete':
        case 'permit_draft_save':
        case 'permit_draft_clear':
        case 'permit_draft_replace':
            $client = step2_require_applicant();
            if (!$post) step2_draft_json(['error'=>'POST required.'],405);
            check_csrf();
            $scope = filter_var($_POST['application_id'] ?? 0,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0]]);
            if ($scope === false || $scope === null) {
                step2_draft_json(['error'=>'Invalid FP application reference.'],400);
            }
            $draftId = filter_var($_POST['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]) ?: 0;
            if ($page === 'permit_draft_upload') {
                $result = step2_draft_upload($client,(int)$scope,$_FILES);
            } elseif ($page === 'permit_draft_delete') {
                $result = step2_draft_delete($client,(int)$scope,(int)$draftId);
            } elseif ($page === 'permit_draft_save') {
                $result = step2_persistent_save_fields($client,(int)$scope,$_POST);
            } elseif ($page === 'permit_draft_clear') {
                $result = step2_persistent_clear($client,(int)$scope);
            } else {
                $result = step2_persistent_replace_pdf($client,(int)$scope,(int)$draftId,$_FILES);
            }
            step2_draft_json($result,isset($result['error']) ? 422 : 200);
        case 'permit_draft_file':
            $client = step2_require_applicant();
            if ($post) show_error('Use GET to open a saved PDF.',405);
            $scope = filter_var($_GET['application_id'] ?? 0,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0]]);
            $draftId = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if ($scope === false || $scope === null || !$draftId) {
                show_error('Invalid PDF draft reference.',400);
            }
            step2_persistent_send_pdf($client,(int)$scope,(int)$draftId);
        case 'permit_apply':
            $client = step2_require_applicant();
            $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
            if (isset($_GET['id']) && !$id) show_error('Invalid FP application ID.',400);
            if ($post) {
                check_csrf();
                $fields = step2_input();
                $saved = step2_persistent_ready()
                    ? step2_persistent_save_fields($client,(int)$id,$fields) : [];
                $errors = isset($saved['error']) ? [$saved['error']]
                    : step2_submit($client,$fields,$_FILES,(int)$id);
                if ($errors) view_step2_form((int)$id,$errors);
                else {
                    flash('success','FP application submitted for LPDD-FUS Technical Staff initial review.');
                    go('permit_applications');
                }
            } else {
                view_step2_form((int)$id);
            }
            break;
        case 'permit_application':
            if ($post) show_error('Use the review form to submit a decision.',405);
            $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            view_step2_application((int)$id);
            break;
        case 'permit_initial_queue':
            if ($post) show_error('This page is read-only.',405);
            view_step2_review_queue();
            break;
        case 'permit_initial_decision':
            $reviewer = step2_require_reviewer();
            if (!$post) show_error('Initial-review decisions require a form submission.',405);
            check_csrf();
            $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            $decision = safe_post('decision',20);
            $remarks = safe_post('remarks',3000);
            $errors = step2_review($reviewer,(int)$id,$decision,$remarks);
            if ($errors) view_step2_application((int)$id,$errors);
            else {
                flash('success',$decision === 'forward'
                    ? 'Initial review passed; application forwarded to RO Receiving.'
                    : 'Incomplete application returned to the applicant with required corrections.');
                go('permit_initial_queue');
            }
            break;
        case 'permit_ro_inbox':
            if ($post) show_error('This page is read-only.',405);
            view_step2_ro_inbox();
            break;
        case 'permit_document':
            if ($post) show_error('Download requires a GET request.',405);
            $viewer = require_user();
            $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP document ID.',400);
            step2_send_document($viewer,(int)$id);
            break;

        case 'review_queue':
            view_review_queue();
            break;
        case 'review':
            $reviewer = require_role('lpdd_fus');
            $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Missing or invalid registration ID.', 400);
            if ($post) {
                check_csrf();
                $errors = decide_registration($reviewer,(int)$id,safe_post('decision',30),safe_post('remarks',5000));
                if ($errors) view_review_case((int)$id,$errors);
                else {
                    flash('success','LPDD-FUS decision saved and applicant account status updated.');
                    go('review_queue');
                }
            } else view_review_case((int)$id);
            break;
        case 'download_reg':
            $user = require_user();
            $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid document ID.',400);
            send_registration_document($user,(int)$id);
            break;
        case 'staff':
            require_role('super_admin');
            if ($post) show_error('The previous user-creation form is no longer used. Open User Management.',405);
            go('user_management');
            break;
        case 'user_management':
            require_role('super_admin');
            if ($post) show_error('Use Add User or Edit User to change user records.',405);
            view_user_management();
            break;
        case 'user_create':
            $admin = require_role('super_admin');
            if ($post) {
                check_csrf();
                $errors = user_management_create($admin,$_POST);
                if ($errors) view_user_create($errors);
                else {
                    flash('success','Internal user created. Share the temporary password securely. The new user must change it on first sign-in.');
                    go('user_management',['tab'=>'accounts']);
                }
            } else {
                view_user_create();
            }
            break;
        case 'user_edit':
            $admin = require_role('super_admin');
            $id = filter_var($_GET['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
            if (!$id) show_error('Invalid internal user ID.',400);
            if ($post) {
                check_csrf();
                $action = (string)($_POST['action'] ?? '');
                if ($action === 'update') {
                    $errors = user_management_update($admin,(int)$id,$_POST);
                    $success = 'User profile, role, and account status updated.';
                } elseif ($action === 'activate') {
                    $errors = user_management_activate($admin,(int)$id,$_POST);
                    $success = 'Existing Pending Setup user activated. Share their temporary password securely.';
                } elseif ($action === 'reset_password') {
                    $errors = user_management_reset_password($admin,(int)$id,$_POST);
                    $success = 'Temporary password reset. Share it securely; the user must change it at next login.';
                } else {
                    show_error('Invalid user edit operation.',400);
                }
                $fromSetupModal = $action === 'activate'
                    && (string)($_POST['setup_origin'] ?? '') === 'user_management_modal';
                $fromEditModal = in_array($action, ['update','reset_password'], true)
                    && (string)($_POST['edit_origin'] ?? '') === 'user_management_modal';
                if ($errors) {
                    if ($fromSetupModal) view_user_management($errors,(int)$id);
                    elseif ($fromEditModal) view_user_management($errors,0,(int)$id,$action);
                    else view_user_edit((int)$id,$errors);
                } else {
                    flash('success',$success);
                    if ($fromSetupModal || $fromEditModal) go('user_management');
                    else go('user_edit',['id'=>(int)$id]);
                }
            } else {
                view_user_edit((int)$id);
            }
            break;
        case 'change_password':
            $user = require_user();
            if ($post) {
                check_csrf();
                $new = (string)($_POST['new_password'] ?? '');
                $errors = [];
                if ($new !== (string)($_POST['confirm_password'] ?? '')) $errors[] = 'New password confirmation does not match.';
                if (!$errors) $errors = change_password($user,(string)($_POST['current_password'] ?? ''),$new);
                if ($errors) view_change_password($errors);
                else {
                    flash('success','Password changed successfully.');
                    go('dashboard');
                }
            } else view_change_password();
            break;
        case 'logout':
            if (!$post) show_error('Logout requires a submitted form.',405);
            check_csrf();
            logout_user();
            go('login');
        default:
            show_error('The requested page does not exist.',404);
    }
} catch (Throwable $exception) {
    error_log('FP-OMS request failure: ' . $exception->getMessage());
    if (!headers_sent()) http_response_code(503);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Service Setup Required | DENR FP-OMS</title><link rel="stylesheet" href="assets/style.css"></head>'
        . '<body style="padding:3rem;font-family:Arial,sans-serif"><h1>System temporarily unavailable</h1>'
        . '<p>Database connectivity or server configuration is incomplete. Contact your RICTU system administrator.</p>'
        . '<p><small>For local installation, check README.md, your .env values and PHP PDO MySQL support.</small></p></body></html>';
}
