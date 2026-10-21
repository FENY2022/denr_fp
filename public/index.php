<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

try {
    $page = (string)($_GET['page'] ?? 'dashboard');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $post = $method === 'POST';
    $loggedIn = current_user();

    if ($loggedIn && (int)$loggedIn['must_change_password'] === 1 &&
        !in_array($page, ['change_password','logout'], true)) {
        go('change_password');
    }

    switch ($page) {
        case 'login':
            if ($post) {
                check_csrf();
                $email = safe_post('email',190);
                if (authenticate($email,(string)($_POST['password'] ?? ''))) {
                    go('dashboard');
                }
                view_login(['Invalid credentials or too many recent failed attempts. Try again later.']);
            } else view_login();
            break;
        case 'register':
            if ($post) {
                check_csrf();
                $values = registration_input();
                $errors = register_applicant($values,safe_post('email',190),(string)($_POST['password'] ?? ''),(string)($_POST['password_confirm'] ?? ''),$_FILES);
                if (!$errors) {
                    flash('success','Registration submitted. Sign in to check LPDD-FUS review status.');
                    go('login');
                }
                view_register($errors);
            } else view_register();
            break;
        case 'dashboard':
            view_dashboard();
            break;
        case 'my_registration':
            view_applicant_registration(require_user());
            break;
        case 'registration_update':
            $user = require_user();
            require_kind($user,'applicant');
            if (!$post) go('my_registration');
            check_csrf();
            $errors = revise_registration($user,registration_input(),$_FILES);
            if ($errors) view_applicant_registration($user,$errors);
            else {
                flash('success','Corrections resubmitted for LPDD-FUS review.');
                go('my_registration');
            }
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
            $admin = require_role('super_admin');
            if ($post) {
                check_csrf();
                $errors = create_internal_user($admin,$_POST);
                if ($errors) view_staff($errors);
                else {
                    flash('success','Internal account created. Share the initial password securely and require its change on first login.');
                    go('staff');
                }
            } else view_staff();
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
