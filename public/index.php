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

    if ($loggedIn && (int)$loggedIn['must_change_password'] === 1
        && !initial_password_change_deferred($loggedIn)
        && !in_array($page, ['change_password','logout'], true)) {
        go('change_password');
    }

    switch ($page) {
        case 'pnpki_callback':
            pnpki_bridge_callback();
            break;
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
                $identifier = safe_post('identifier',190);
                if (authenticate($identifier,(string)($_POST['password'] ?? ''))) {
                    $loggedIn = current_user();
                    $nextPage = (string)($_POST['next'] ?? '');
                    if ($loggedIn && in_array($nextPage, ['review-queue','permit-initial-queue','permit-step4-queue'],true)
                        && has_role($loggedIn,'lpdd_fus')) go(str_replace('-', '_', $nextPage));
                    if ($loggedIn && $nextPage === 'permit-ro-inbox'
                        && has_role($loggedIn,'ro_receiving')) go('permit_ro_inbox');
                    if ($loggedIn && $nextPage === 'permit-applications'
                        && $loggedIn['user_kind'] === 'applicant'
                        && $loggedIn['status'] === 'active') go('permit_applications');
                    go('dashboard');
                }
                if (registration_otp_pending_client()) go('verify_registration_otp');
                view_login(['Invalid username/email or password. Please try again.']);
            } else view_login();
            break;
        case 'otp_delivery_diagnostics':
            $admin=require_role('super_admin');
            if ($post) {
                check_csrf();
                $action=safe_post('operation',20);
                if ($action==='analyze') {
                    $email=shared_office_normalize_email(safe_post('office_email',190));
                    if (!shared_office_email_valid($email)) {
                        view_otp_delivery_diagnostics(['Enter a valid office email to analyze.']);
                    } else {
                        $_SESSION['fpoms_otp_diag_email']=$email;
                        view_otp_delivery_diagnostics();
                    }
                } elseif ($action==='test_smtp') {
                    view_otp_delivery_diagnostics([],fpoms_otp_admin_test_mail($admin));
                } elseif ($action==='install_025') {
                    view_otp_delivery_diagnostics([],fpoms_otp_initialize_table($admin));
                } else {
                    show_error('Invalid diagnostics action.',400);
                }
            } else {
                view_otp_delivery_diagnostics();
            }
            break;
        case 'shared_office_recovery':
            if ($post) {
                check_csrf();
                $email=shared_office_normalize_email(safe_post('office_email',190));
                $purpose=(string)($_POST['purpose']??'password');
                if (!in_array($purpose,['password','username'],true)) {
                    show_error('Invalid recovery purpose.',400);
                }
                unset($_SESSION['shared_office_verified_email'],
                    $_SESSION['shared_office_verified_expires_at']);
                $_SESSION['shared_office_pending_email']=$email;
                $_SESSION['shared_office_purpose']=$purpose;
                shared_office_request($email);
                flash('info','If this office mailbox is associated with multiple active DENR accounts, a verification code has been sent. Enter it to continue.');
                go('shared_office_verify');
            }
            view_shared_office_recovery();
            break;
        case 'shared_office_verify':
            if ($post) {
                check_csrf();
                $email=(string)($_SESSION['shared_office_pending_email']??'');
                if (shared_office_verify($email,safe_post('otp',6))) {
                    go('shared_office_accounts');
                }
                view_shared_office_verify([
                    'Invalid or expired office verification code. Request a new code if needed.'
                ]);
            } else view_shared_office_verify();
            break;
        case 'shared_office_accounts':
            if ($post) {
                check_csrf();
                $id=filter_var($_POST['staff_id']??null,FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1]]) ?: 0;
                $purpose=(string)($_SESSION['shared_office_purpose']??'password');
                $errors=shared_office_choose_account((int)$id,$purpose);
                if ($errors) {
                    view_shared_office_accounts($errors);
                } elseif ($purpose==='username') {
                    go('verify_username');
                } else {
                    go('staff_reset_password');
                }
            } else view_shared_office_accounts();
            break;
        case 'forgot_password':
            if ($post) {
                check_csrf();
                $email = strtolower(trim(safe_post('email',190)));
                // Starting a new recovery attempt revokes any old office choice.
                unset($_SESSION['shared_office_verified_email'],
                    $_SESSION['shared_office_verified_expires_at'],
                    $_SESSION['shared_office_pending_email'],
                    $_SESSION['shared_office_purpose']);
                $officeFlow = shared_office_auto_start($email,'password');
                if (!$officeFlow) {
                    request_password_reset($email); // Applicant receives existing reset link.
                    staff_otp_request($email); // Eligible individual DENR staff OTP.
                    $_SESSION['staff_reset_pending_email']=$email;
                }
                unset($_SESSION['staff_password_reset_user_id'],
                    $_SESSION['staff_password_reset_expires_at']);
                $_SESSION['auto_recovery_email']=$email;
                $_SESSION['auto_recovery_purpose']='password';
                $_SESSION['auto_recovery_mode']=$officeFlow?'office':'individual';
                $_SESSION['auto_recovery_last_requested_at']=time();
                flash('info','If the email is registered, a recovery message has been requested. Enter a verification code if received, or use the applicant password-reset link in your email.');
                go('auto_recovery_verify');
            } else {
                view_forgot_password();
            }
            break;
        case 'forgot_username':
            if ($post) {
                check_csrf();
                $email = strtolower(trim(safe_post('email',190)));
                // Starting a new recovery attempt revokes any old office choice.
                unset($_SESSION['shared_office_verified_email'],
                    $_SESSION['shared_office_verified_expires_at'],
                    $_SESSION['shared_office_pending_email'],
                    $_SESSION['shared_office_purpose']);
                $officeFlow = shared_office_auto_start($email,'username');
                if (!$officeFlow) {
                    staff_otp_request($email,'username');
                    $_SESSION['staff_username_pending_email']=$email;
                }
                unset($_SESSION['staff_recovered_username']);
                $_SESSION['auto_recovery_email']=$email;
                $_SESSION['auto_recovery_purpose']='username';
                $_SESSION['auto_recovery_mode']=$officeFlow?'office':'individual';
                $_SESSION['auto_recovery_last_requested_at']=time();
                flash('info','If this email belongs to an active DENR account, a recovery code has been requested. Enter the code if received.');
                go('auto_recovery_verify');
            } else {
                view_forgot_username();
            }
            break;
        case 'auto_recovery_verify':
            if ($post) {
                check_csrf();
                $email=(string)($_SESSION['auto_recovery_email']??'');
                $purpose=(string)($_SESSION['auto_recovery_purpose']??'');
                $mode=(string)($_SESSION['auto_recovery_mode']??'');
                if (safe_post('action',20)==='resend') {
                    $lastRequested=(int)($_SESSION['auto_recovery_last_requested_at']??0);
                    $wait=max(0,60-(time()-$lastRequested));
                    if ($wait>0) {
                        flash('info','Please wait '.$wait.' seconds before requesting a new OTP.');
                    } else {
                        if ($mode==='office') {
                            shared_office_request($email);
                        } elseif ($mode==='individual' && $purpose==='password') {
                            request_password_reset($email);
                            staff_otp_request($email);
                        } elseif ($mode==='individual' && $purpose==='username') {
                            staff_otp_request($email,'username');
                        }
                        $_SESSION['auto_recovery_last_requested_at']=time();
                        flash('info','If the email is eligible and not rate-limited, a new recovery message has been requested.');
                    }
                    go('auto_recovery_verify');
                }
                $code=safe_post('otp',6);
                $verified=false;
                if ($mode==='office') {
                    $verified=shared_office_verify($email,$code);
                    if ($verified) {
                        unset($_SESSION['auto_recovery_email'],
                            $_SESSION['auto_recovery_purpose'],
                            $_SESSION['auto_recovery_mode'],
                            $_SESSION['auto_recovery_last_requested_at']);
                        go('shared_office_accounts');
                    }
                } elseif ($mode==='individual' && $purpose==='password') {
                    $id=staff_otp_verify_password($email,$code);
                    if ($id!==null) {
                        unset($_SESSION['staff_reset_pending_email'],
                            $_SESSION['auto_recovery_email'],
                            $_SESSION['auto_recovery_purpose'],
                            $_SESSION['auto_recovery_mode'],
                            $_SESSION['auto_recovery_last_requested_at']);
                        $_SESSION['staff_password_reset_user_id']=$id;
                        $_SESSION['staff_password_reset_expires_at']=time()+600;
                        session_regenerate_id(true);
                        $_SESSION['csrf_token']=bin2hex(random_bytes(32));
                        go('staff_reset_password');
                    }
                } elseif ($mode==='individual' && $purpose==='username') {
                    $account=staff_otp_verify_username($email,$code);
                    if ($account!==null) {
                        unset($_SESSION['staff_username_pending_email'],
                            $_SESSION['auto_recovery_email'],
                            $_SESSION['auto_recovery_purpose'],
                            $_SESSION['auto_recovery_mode'],
                            $_SESSION['auto_recovery_last_requested_at']);
                        $_SESSION['staff_recovered_username']=$account['username'];
                        session_regenerate_id(true);
                        $_SESSION['csrf_token']=bin2hex(random_bytes(32));
                        go('username_recovered');
                    }
                }
                view_auto_recovery_verify([
                    'Invalid or expired verification code. Check your email or request a new code.'
                ]);
            } else view_auto_recovery_verify();
            break;
        case 'verify_username':
            if ($post) {
                check_csrf();
                $email = (string)($_SESSION['staff_username_pending_email'] ?? '');
                $verified = staff_otp_verify_username($email,safe_post('otp',6));
                if ($verified === null) {
                    view_staff_username_verification([
                        'Invalid or expired verification code. Check the email or request a new code.'
                    ]);
                } else {
                    unset($_SESSION['staff_username_pending_email']);
                    $_SESSION['staff_recovered_username'] = $verified['username'];
                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    go('username_recovered');
                }
            } else {
                view_staff_username_verification();
            }
            break;
        case 'username_recovered':
            if ($post) show_error('This page is read-only.',405);
            view_staff_username_recovered();
            break;
        case 'staff_reset_password':
            if ($post) {
                check_csrf();
                $action=(string)($_POST['action'] ?? '');
                if ($action==='verify_otp') {
                    $email=(string)($_SESSION['staff_reset_pending_email'] ?? '');
                    $id=staff_otp_verify_password($email,safe_post('otp',6));
                    if ($id===null) {
                        view_staff_otp_reset([
                            'Invalid or expired verification code. Request a new OTP if needed.'
                        ]);
                    } else {
                        unset($_SESSION['staff_reset_pending_email']);
                        $_SESSION['staff_password_reset_user_id']=$id;
                        $_SESSION['staff_password_reset_expires_at']=time()+600;
                        session_regenerate_id(true);
                        $_SESSION['csrf_token']=bin2hex(random_bytes(32));
                        go('staff_reset_password');
                    }
                } elseif ($action==='reset_password') {
                    $id=staff_password_reset_verified_user_id();
                    $errors=staff_password_reset($id,(string)($_POST['password'] ?? ''),
                        (string)($_POST['confirm_password'] ?? ''));
                    if ($errors) {
                        view_staff_otp_reset($errors);
                        break;
                    }
                    unset($_SESSION['staff_password_reset_user_id'],
                        $_SESSION['staff_password_reset_expires_at']);
                    session_regenerate_id(true);
                    $_SESSION['csrf_token']=bin2hex(random_bytes(32));
                    flash('success','Password updated. Sign in with your DENR username.');
                    go('login');
                } else {
                    show_error('Invalid password recovery action.',400);
                }
            } else view_staff_otp_reset();
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
                if (($_POST['registration_review_confirmed'] ?? null) !== 'confirmed'
                    || ($_POST['registration_email_acknowledged'] ?? null) !== '1') {
                    view_register(['Review your information and saved documents, '
                        . 'then confirm that you can access your email before submitting.']);
                    break;
                }
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
        case 'register_draft_preview':
            if ($post) show_error('Preview requires GET.',405);
            if ($loggedIn) show_error('This preview is only for an unsigned registration.',403);
            $draftId = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]) ?: 0;
            registration_draft_send_preview((int)$draftId);
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
        case 'pnpki_settings':
            $admin=require_role('super_admin');
            if ($post) {
                check_csrf();
                $operation=safe_post('operation',20);
                if ($operation==='install') {
                    $errors=fpoms_pnpki_install_tables($admin);
                    if ($errors) fpoms_pnpki_setup_view($errors);
                    else {
                        flash('success','FP-OMS PNPKI integration database tables installed/verified.');
                        go('pnpki_settings');
                    }
                } elseif ($operation==='save') {
                    $errors=fpoms_pnpki_save_settings($admin,$_POST);
                    if ($errors) fpoms_pnpki_setup_view($errors);
                    else {
                        flash('success','PNPKI_2 connection saved encrypted. Use Test Connection to verify the remote server.');
                        go('pnpki_settings');
                    }
                } elseif ($operation==='test') {
                    $errors=fpoms_pnpki_test_connection($admin);
                    if ($errors) fpoms_pnpki_setup_view($errors);
                    else {
                        flash('success','PNPKI_2 authenticated connection and basic setup checks passed. '
                            .'Test a real RED PNPKI-signed PDF and Records release before production.');
                        go('pnpki_settings');
                    }
                } else show_error('Invalid PNPKI configuration operation.',400);
            } else fpoms_pnpki_setup_view();
            break;
        case 'realtime_settings':
            $admin = require_role('super_admin');
            if ($post) {
                check_csrf();
                $operation = safe_post('operation',20);
                if ($operation === 'save') {
                    $errors = fpoms_realtime_save($admin,$_POST);
                    if ($errors) view_realtime_settings($errors);
                    else {
                        flash('success','Realtime notification settings saved securely.');
                        go('realtime_settings');
                    }
                } elseif ($operation === 'test') {
                    $result = fpoms_realtime_test_connection($admin);
                    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
                    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
                    if (str_contains($accept,'application/json') || $requestedWith === 'xmlhttprequest') {
                        header('Content-Type: application/json; charset=utf-8');
                        header('Cache-Control: private, no-store');
                        echo json_encode([
                            'success'=>(bool)$result['success'],
                            'message'=>(string)$result['message']
                        ], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
                        exit;
                    }
                    view_realtime_settings([], (string)$result['message']);
                } else show_error('Invalid realtime settings action.',400);
            } else view_realtime_settings();
            break;
        case 'notifications_fragment':
            if ($post) show_error('GET required.',405);
            $user = require_user();
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo json_encode(['html'=>notification_dropdown_html($user)],
                JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
            exit;
        case 'pusher_auth':
            if (!$post) show_error('POST required.',405);
            $user = require_user();
            check_csrf();
            header('Content-Type: application/json; charset=utf-8');
            $auth = fpoms_pusher_auth($user,safe_post('channel_name',160),
                safe_post('socket_id',80));
            if ($auth===null) {
                http_response_code(403);
                echo json_encode(['error'=>'Forbidden']);
            } else echo json_encode($auth);
            exit;
        case 'messenger_conversations':
            if ($post) show_error('GET required.',405);
            messenger_api_conversations(require_user());
            break;
        case 'messenger_users':
            if ($post) show_error('GET required.',405);
            messenger_api_users(require_user());
            break;
        case 'messenger_direct':
            if (!$post) show_error('POST required.',405);
            messenger_api_direct(require_user());
            break;
        case 'messenger_messages':
            if ($post) show_error('GET required.',405);
            messenger_api_messages(require_user());
            break;
        case 'messenger_send':
            if (!$post) show_error('POST required.',405);
            messenger_api_send(require_user());
            break;
        case 'messenger_group_create':
            if (!$post) show_error('POST required.',405);
            messenger_api_group_create(require_user());
            break;
        case 'messenger_thread_action':
            if (!$post) show_error('POST required.',405);
            messenger_api_thread_action(require_user());
            break;
        case 'messenger_typing':
            if (!$post) show_error('POST required.',405);
            messenger_api_typing(require_user());
            break;
        case 'messenger_file':
            if ($post) show_error('GET required.',405);
            messenger_send_file(require_user());
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
        case 'records_archive':
            if ($post) show_error('Use the archive confirmation action.',405);
            view_records_archive();
            break;
        case 'records_archive_action':
            $officer=records_archive_require_officer();
            if (!$post) show_error('Archiving requires confirmed POST.',405);
            check_csrf();
            $id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            if (($_POST['archive_confirm'] ?? '')!=='1') {
                show_error('Confirm archiving in the dialog first.',400);
            }
            $errors=records_archive_action($officer,(int)$id,safe_post('remarks',2000));
            if ($errors) view_step2_application((int)$id,$errors);
            else {
                flash('success','Records action archived. Removed from active and recent Records action lists.');
                go('records_archive');
            }
            break;
        case 'my_actions':
            if ($post) show_error('Action history is read-only.',405);
            view_staff_action_history();
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
                $result = step2_draft_upload($client,(int)$scope,$_FILES,
                    (string)($_POST['document_category'] ?? ''));
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
        case 'permit_area_photo_group_save':
        case 'permit_area_photo_group_delete':
        case 'permit_area_photo_upload':
        case 'permit_area_photo_update':
        case 'permit_area_photo_delete':
            $client=step2_require_applicant();
            if (!$post) step2_draft_json(['error'=>'POST required.'],405);
            check_csrf();
            $scope=filter_var($_POST['application_id']??0,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0]]);
            if ($scope===false || $scope===null) {
                step2_draft_json(['error'=>'Invalid FP application reference.'],400);
            }
            $photoId=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]) ?: 0;
            if ($page==='permit_area_photo_group_save') {
                $result=step2_area_photo_group_save(
                    $client,(int)$scope,$_POST['documentation_group']??1,
                    $_POST['latitude']??'',$_POST['longitude']??''
                );
            } elseif ($page==='permit_area_photo_group_delete') {
                $result=step2_area_photo_group_delete(
                    $client,(int)$scope,$_POST['documentation_group']??1
                );
            } elseif ($page==='permit_area_photo_upload') {
                $result=step2_area_photo_upload(
                    $client,(int)$scope,$_FILES,
                    $_POST['documentation_group']??1
                );
            } elseif ($page==='permit_area_photo_update') {
                $result=step2_area_photo_update(
                    $client,(int)$scope,(int)$photoId,
                    $_POST['latitude']??'',$_POST['longitude']??''
                );
            } else {
                $result=step2_area_photo_delete($client,(int)$scope,(int)$photoId);
            }
            step2_draft_json($result,isset($result['error'])?422:200);
        case 'permit_area_photo':
            if ($post) show_error('Area photo preview requires GET.',405);
            $viewer=require_user();
            $scope=filter_var($_GET['application_id']??0,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0]]);
            $photoId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if ($scope===false || $scope===null || !$photoId) {
                show_error('Invalid area photo reference.',400);
            }
            if ((string)($_GET['draft']??'')==='1') {
                require_kind($viewer,'applicant');
                step2_area_photo_send_draft($viewer,(int)$scope,(int)$photoId);
            }
            if ((int)$scope<1) show_error('Invalid submitted area photo reference.',400);
            step2_area_photo_send_submitted($viewer,(int)$scope,(int)$photoId);
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
        case 'permit_revert':
            $client = step2_require_applicant();
            if (!$post) show_error('Revert requires a confirmed POST request.',405);
            check_csrf();
            $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            if (($_POST['revert_confirm'] ?? null) !== '1') {
                show_error('Confirm the reversion in the dialog first.',400);
            }
            $errors = step2_revert_application(
                $client,(int)$id,(string)($_POST['remarks'] ?? '')
            );
            if ($errors) view_step2_application((int)$id,$errors);
            else {
                flash('success','FP application reverted successfully. You may now edit and resubmit it.');
                go('permit_application',['id'=>(int)$id]);
            }
            break;
        case 'permit_receive':
            $reviewer = step2_require_reviewer();
            if (!$post) show_error('Document receipt requires a confirmed POST request.',405);
            check_csrf();
            $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            if (($_POST['receive_confirm'] ?? null) !== '1') {
                show_error('Confirm receipt of the submitted documents first.',400);
            }
            $errors = step2_receive_documents($reviewer,(int)$id);
            if ($errors) view_step2_application((int)$id,$errors);
            else {
                flash('success','Receipt acknowledged. This application can no longer be reverted.');
                go('permit_application',['id'=>(int)$id]);
            }
            break;
        case 'permit_application':
            if ($post) show_error('Use the action forms for posting.',405);
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
        case 'permit_ro_receive':
        case 'permit_ro_forward':
            $staff=step3_require_officer();
            if (!$post) show_error('This RO action requires a confirmed POST.',405);
            check_csrf();
            $id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            $action=$page==='permit_ro_receive' ? 'receive' : 'forward';
            if (($_POST['confirmation'] ?? '')!==$action) {
                show_error('Confirm this action in the dialog.',400);
            }
            $errors=step3_record_action($staff,(int)$id,$action,safe_post('remarks',2000));
            if ($errors) view_step2_application((int)$id,$errors);
            else {
                flash('success',$action==='receive'
                    ? 'RO document receipt recorded successfully.'
                    : 'Application forwarded to LPDD-FUS Step 4 action queue.');
                go('permit_ro_inbox');
            }
            break;
        case 'permit_step4_queue':
            if ($post) show_error('Step 4 queue is read-only.',405);
            view_step4_queue();
            break;
        case 'permit_step4_start':
            $reviewer=step4_require_reviewer();
            if (!$post) show_error('Starting Step 4 requires a confirmed POST.',405);
            check_csrf();
            $id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            $errors=step4_start($reviewer,(int)$id);
            if ($errors) view_step2_application((int)$id,$errors);
            else {
                $current=step2_application((int)$id);
                flash('success',$current && permit_flow_is_new($current)
                    ? 'Step 2 documentary completeness/sufficiency evaluation started.'
                    : 'Legacy Step 4 documentary evaluation started.');
                go('permit_application',['id'=>(int)$id]);
            }
            break;
        case 'payment_notice_generate':
            if (!$post) show_error('Template No. 2 generation requires POST.',405);
            $staff=require_role('ro_receiving');
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application reference.',400);
            $errors=fpoms_payment_notice_prepare_existing($staff,(int)$id);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else flash('success','Template No. 2 generated and sent to the RED approval queue.');
            go('permit_application',['id'=>(int)$id]);
            break;
        case 'payment_notice_pnpki_authorize':
            if (!$post) show_error('PNPKI account authorization requires POST.',405);
            $red=require_role('red');
            check_csrf();
            try {
                $target=pnpki_authorize_url($red);
                header('Referrer-Policy: no-referrer');
                header('Location: '.$target,true,303);
                exit;
            } catch(Throwable $e) {
                flash('error','Unable to start PNPKI_2 authorization. Apply migrations and configure the connection.');
                go('payment_notice_queue');
            }
            break;
        case 'payment_notice_pnpki_revoke':
            if (!$post) show_error('PNPKI authorization revocation requires POST.',405);
            $red=require_role('red');
            check_csrf();
            if (($_POST['confirmation']??'')!=='REVOKE') {
                show_error('Confirm RED PNPKI_2 account disconnection.',400);
            }
            $errors=pnpki_revoke_authorization($red);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else flash('success','PNPKI_2 access revoked. Existing documents and Records release history remain intact. You may authorize again when needed.');
            go('payment_notice_queue');
            break;
        case 'pnpki_authorized':
            if ($post) show_error('PNPKI account callback requires GET.',405);
            $red=require_role('red');
            $errors=pnpki_finish_authorization($red,
                (string)($_GET['code']??''),(string)($_GET['state']??''));
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else flash('success','Your PNPKI_2 RED account is authorized and connected to FP-OMS. '
                .'You can upload a digitally signed Template No. 2 in FP-OMS.');
            go('payment_notice_queue');
            break;
        case 'payment_notice_pnpki_upload':
            if (!$post) show_error('Signed PDF upload requires POST.',405);
            $red=require_role('red');
            check_csrf();
            if (($_POST['confirmation']??'')!=='1') show_error('Confirm RED-signed PDF submission.',400);
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid payment notice reference.',400);
            $errors=pnpki_upload_signed_pdf($red,(int)$id,$_FILES['signed_pdf']??[]);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else flash('success','Template No. 2 received by PNPKI_2 Records for review. '
                .'If signature verification is OFF, it remains UNVERIFIED / PENDING VERIFICATION. '
                .'No official release or applicant payment notice until verified Records release.');
            go('payment_notice_queue');
            break;
        case 'payment_notice_pnpki_retry':
            if (!$post) show_error('PNPKI_2 resubmission requires POST.',405);
            $red=require_role('red');
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid payment notice reference.',400);
            $errors=pnpki_submit_saved_signed_pdf($red,(int)$id);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else flash('success','PDF received by PNPKI_2 Records. If automated verification is OFF, '
                .'status remains UNVERIFIED / PENDING VERIFICATION and release is blocked.');
            go('payment_notice_queue');
            break;
        case 'payment_notice_pnpki_open':
            if (!$post) show_error('PNPKI_2 one-time login requires POST.',405);
            $red=require_role('red');
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid payment notice reference.',400);
            try {
                $target=pnpki_authorized_sso_url($red,(int)$id);
                header('Referrer-Policy: no-referrer');
                header('Location: '.$target,true,303);
                exit;
            } catch(Throwable $e) {
                flash('error','PNPKI_2 auto-login unavailable. Reauthorize your RED account and try again.');
                go('payment_notice_queue');
            }
            break;
        case 'payment_notice_pnpki_send':
            // Retired initial-unsigned handoff. This endpoint must not bypass
            // RED consent and signed PDF validation in the new workflow.
            show_error('Use Authorize PNPKI_2 and Upload PNPKI-Signed PDF in FP-OMS.',410);
            break;
        case 'payment_notice_pnpki_check':
            if (!$post) show_error('PNPKI status synchronization requires POST.',405);
            $red=require_role('red');
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application reference.',400);
            $errors=pnpki_bridge_reconcile((int)$id);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else {
                $notice=fpoms_payment_notice_record_for_app((int)$id);
                $link=pnpki_bridge_link((int)$id,(int)($notice['revision']??0));
                if ($notice && (string)$notice['status']==='released') {
                    flash('success','PNPKI_2 verified RED signature and Records release. '
                        .'The applicant notice to pay was released in FP-OMS.');
                } else {
                    $stage=(string)($link['stage']??'awaiting_red');
                    $label=match ($stage) {
                        'awaiting_records'=>'RED forwarded the document. Awaiting Records release.',
                        'verification_required'=>'Records action detected, but RED digital-signature or certificate validation is still required. No client notification was sent.',
                        default=>'Awaiting RED signing and forwarding in PNPKI_2.'
                    };
                    flash('info',$label);
                }
            }
            go('payment_notice_queue');
            break;
        case 'payment_notice_queue':
            if ($post) show_error('RED payment notice queue is read-only.',405);
            fpoms_payment_notice_queue_view(require_role('red'));
            break;
        case 'payment_notice_pdf':
            if ($post) show_error('Payment notice preview requires GET.',405);
            $viewer=require_user();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid payment notice reference.',400);
            fpoms_payment_notice_send_pdf($viewer,(int)$id);
            break;
        case 'payment_notice_approve':
            if (!$post) show_error('RED payment notice approval requires POST.',405);
            $red=require_role('red');
            check_csrf();
            if (($_POST['confirmation']??'')!=='1') show_error('RED approval confirmation required.',400);
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid payment notice reference.',400);
            $action=(string)($_POST['action']??'');
            if (!in_array($action,['sign','finalize'],true)) show_error('Invalid payment notice action.',400);
            $errors=fpoms_payment_notice_red_approve($red,(int)$id,$action==='sign');
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            elseif ($action==='sign') {
                flash('success','RED e-signature saved. Review the signed Template No. 2, then Finalize & Send.');
                go('payment_notice_queue',['preview_signed'=>(int)$id]);
            } else {
                $notice=fpoms_payment_notice_record_for_app((int)$id);
                $delivery=(string)($notice['email_status']??'');
                flash($delivery==='sent'?'success':'info',$delivery==='sent'
                    ? 'Template No. 2 finalized and emailed to the applicant with the RED-signed PDF attached.'
                    : 'Template No. 2 finalized and released in FP-OMS. Email delivery was not confirmed; retry after checking SMTP.');
            }
            go('payment_notice_queue');
            break;
        case 'payment_notice_upload_signed':
            if (!$post) show_error('Signed payment notice upload requires POST.',405);
            $red=require_role('red');
            check_csrf();
            if (($_POST['confirmation']??'')!=='1') show_error('Signed PDF upload confirmation required.',400);
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid payment notice reference.',400);
            $errors=fpoms_payment_notice_red_upload_signed($red,(int)$id,$_FILES['signed_pdf']??[]);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else {
                flash('success','Physically signed Template No. 2 uploaded. Review it, then Finalize & Send.');
                go('payment_notice_queue',['preview_signed'=>(int)$id]);
            }
            go('payment_notice_queue');
            break;
        case 'payment_notice_retry_email':
            if (!$post) show_error('Retry requires POST.',405);
            $staff=require_user();
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application reference.',400);
            $errors=fpoms_payment_notice_retry_email($staff,(int)$id);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else flash('success','Official RED-signed Template No. 2 email was accepted by SMTP.');
            go('permit_application',['id'=>(int)$id]);
            break;
        case 'deficiency_notice_settings':
            $admin=require_role('super_admin');
            if ($post) {
                check_csrf();
                $errors=fpoms_notice_save_config($admin,$_POST,$_FILES);
                if ($errors) fpoms_notice_config_view($admin,$errors);
                else {
                    flash('success','Deficiency notice settings saved. RED approval is still required per notice.');
                    go('deficiency_notice_settings');
                }
            } else fpoms_notice_config_view($admin);
            break;
        case 'deficiency_notice_queue':
            if ($post) show_error('RED approval queue is read-only.',405);
            fpoms_notice_queue_view(require_role('red'));
            break;
        case 'deficiency_notice_print':
            if ($post) show_error('Print preview requires GET.',405);
            $viewer=require_user();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid notice reference.',400);
            fpoms_notice_print_preview($viewer,(int)$id);
            break;
        case 'deficiency_notice_pdf':
            if ($post) show_error('PDF preview requires GET.',405);
            $viewer=require_user();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid notice reference.',400);
            fpoms_notice_send_pdf($viewer,(int)$id);
            break;
        case 'deficiency_notice_regenerate':
            if (!$post) show_error('Deficiency notice regeneration requires POST.',405);
            $reviewer=require_role('lpdd_fus');
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid notice reference.',400);
            $errors=fpoms_notice_regenerate_draft($reviewer,(int)$id);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else flash('success','Deficiency notice PDF regenerated successfully. Findings were unchanged.');
            go('permit_application',['id'=>(int)$id]);
            break;
        case 'deficiency_notice_approve':
            if (!$post) show_error('RED notice approval requires POST.',405);
            $red=require_role('red');
            check_csrf();
            if (($_POST['confirmation']??'')!=='1') show_error('RED approval confirmation required.',400);
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid notice reference.',400);
            $approvalAction=(string)($_POST['action']??'');
            if (!in_array($approvalAction,['sign','finalize'],true)) {
                show_error('Select a valid RED notice action.',400);
            }
            $errors=fpoms_notice_red_approve($red,(int)$id,$approvalAction==='sign');
            if ($errors) {
                flash('error',implode(' ',array_map('strval',$errors)));
            } elseif ($approvalAction==='sign') {
                flash('success','RED e-signature saved. Review the signed PDF, then Finalize and Return.');
                go('deficiency_notice_queue',['preview_signed'=>(int)$id]);
            } else {
                $notice=fpoms_notice_record((int)$id,
                    (int)(step2_application((int)$id)['revision']??0));
                $delivery=(string)($notice['email_status']??'');
                if ($delivery==='sent') {
                    flash('success','Finalize and Return completed. The official RED-approved PDF was released and SMTP accepted the applicant email.');
                } elseif ($delivery==='failed') {
                    flash('info','Finalize and Return completed and the official PDF is available to the applicant, but email delivery failed. Use Retry Applicant Email after correcting SMTP settings.');
                } else {
                    flash('info','Finalize and Return completed and the official PDF is available to the applicant. Email delivery could not be verified; review notice delivery status before claiming the email was sent.');
                }
            }
            go('deficiency_notice_queue');
            break;
        case 'deficiency_notice_upload_signed':
            if (!$post) show_error('Signed notice upload requires POST.',405);
            $red=require_role('red');
            check_csrf();
            if (($_POST['confirmation']??'')!=='1') {
                show_error('Signed PDF upload confirmation required.',400);
            }
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid notice reference.',400);
            $errors=fpoms_notice_red_upload_signed($red,(int)$id,$_FILES['signed_pdf']??[]);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else {
                flash('success','Signed PDF uploaded. Review the signed file, then Finalize and Return.');
                go('deficiency_notice_queue',['preview_signed'=>(int)$id]);
            }
            go('deficiency_notice_queue');
            break;
        case 'deficiency_notice_retry_email':
            if (!$post) show_error('Retrying notice email requires POST.',405);
            $staff=require_user();
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application reference.',400);
            $errors=fpoms_notice_retry_official_email($staff,(int)$id);
            if ($errors) flash('error',implode(' ',array_map('strval',$errors)));
            else flash('success','Official deficiency notice was sent to the applicant email.');
            go('permit_application',['id'=>(int)$id]);
            break;
        case 'permit_step4_decision':
            $reviewer=step4_require_reviewer();
            if (!$post) show_error('Step 4 decisions require a confirmed POST.',405);
            check_csrf();
            $id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            $errors=step4_decide($reviewer,(int)$id,$_POST);
            if ($errors) view_step2_application((int)$id,$errors);
            else {
                $decision=(string)($_POST['decision'] ?? '');
                $current=step2_application((int)$id);
                $modern=$current && permit_flow_is_new($current);
                if ($modern && $decision==='return') {
                    $notice=fpoms_notice_record((int)$id,(int)($current['revision']??0));
                    if ($notice && (string)$notice['status']==='pending_red') {
                        flash('success','Draft deficiency notice PDF generated for RED approval. No applicant notification was sent.');
                        go('permit_application',['id'=>(int)$id]);
                    }
                }
                flash('success',$decision==='complete'
                    ? ($modern
                        ? 'Step 2 Complete & Sufficient – application forwarded to RO Step 3.'
                        : 'Legacy Step 4 completed: documentary requirements are complete and sufficient.')
                    : 'Application returned to the applicant for documentary compliance.');
                if ($modern) go('permit_initial_queue');
                go('permit_step4_queue',['tab'=>$decision==='complete'?'complete':'returned']);
            }
            break;
        case 'permit_flow':
            if ($post) show_error('Workflow view requires GET.',405);
            $id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            view_permit_flow((int)$id);
            break;
        case 'permit_flow_queue':
            if ($post) show_error('Workflow queue requires GET.',405);
            view_permit_flow_queue();
            break;
        case 'permit_flow_upload':
            if (!$post) show_error('Document uploads require POST.',405);
            $staff=require_user();
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            $step=filter_var($_GET['step']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>2,'max_range'=>11]]);
            if (!$id || !$step) show_error('Invalid workflow upload reference.',400);
            $category=safe_post('category',60);
            $errors=permit_flow_upload($staff,(int)$id,(int)$step,$category,
                $_FILES['document']??null);
            if ($errors) {
                flash('error',implode(' ',array_map('strval',$errors)));
            } else {
                flash('success','Workflow document securely uploaded and recorded in the audit trail.');
            }
            go((int)$step===2?'permit_application':'permit_flow',['id'=>(int)$id]);
            break;
        case 'permit_flow_advance':
            if (!$post) show_error('Workflow transitions require confirmed POST.',405);
            $staff=require_user();
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            $step=filter_var($_GET['step']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>4,'max_range'=>11]]);
            if (!$id || !$step) show_error('Invalid workflow stage.',400);
            $errors=permit_flow_advance($staff,(int)$id,(int)$step,$_POST);
            if ($errors) view_permit_flow((int)$id,$errors);
            else {
                flash('success',$step===11?'Feasibility Permit release completed.'
                    :'Step '.$step.' recorded and forwarded to the next office.');
                go('permit_flow',['id'=>(int)$id]);
            }
            break;
        case 'permit_flow_schedule':
            if (!$post) show_error('Field schedule communication requires confirmed POST.',405);
            $staff=require_user();
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            $errors=permit_flow_communicate_schedule($staff,(int)$id,$_POST);
            if ($errors) view_permit_flow((int)$id,$errors);
            else {
                flash('success','Field validation/assessment schedule saved and communicated.');
                go('permit_flow',['id'=>(int)$id]);
            }
            break;
        case 'permit_flow_milestone':
            if (!$post) show_error('DENR milestone actions require confirmed POST.',405);
            $staff=require_user();
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            $errors=permit_flow_milestone($staff,(int)$id,safe_post('action',70),$_POST);
            if ($errors) view_permit_flow((int)$id,$errors);
            else {
                flash('success','CENRO/RRDC milestone recorded in the audit trail.');
                go('permit_flow',['id'=>(int)$id]);
            }
            break;
        case 'permit_flow_notice':
            if (!$post) show_error('CENRO notice requires POST.',405);
            $staff=require_user();
            check_csrf();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id || ($_POST['confirmation']??'')!=='1') show_error('Invalid notice confirmation.',400);
            $errors=permit_flow_notify_hardcopies($staff,(int)$id);
            if ($errors) view_permit_flow((int)$id,$errors);
            else {
                flash('success','CENRO hard-copy notice recorded and applicant notified.');
                go('permit_flow',['id'=>(int)$id]);
            }
            break;
        case 'permit_flow_file':
            if ($post) show_error('Workflow document download requires GET.',405);
            $user=require_user();
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            $file=filter_var($_GET['file']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id || !$file) show_error('Invalid document reference.',400);
            permit_flow_send_artifact($user,(int)$id,(int)$file);
            break;
        case 'lpdd_fus_payment_queue':
            if ($post) show_error('Payment queue is read-only.',405);
            view_lpdd_fus_payment_queue();
            break;
        case 'permit_payment_verify':
            $lpddFus=permit_payment_require_lpdd_fus();
            if (!$post) show_error('Payment verification requires a confirmed POST.',405);
            check_csrf();
            $id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            $errors=permit_payment_verify($lpddFus,(int)$id,$_POST,$_FILES);
            if ($errors) view_step2_application((int)$id,$errors);
            else {
                flash('success','Cashier Official Receipt uploaded and payment verified by LPDD-FUS.');
                go('permit_application',['id'=>(int)$id]);
            }
            break;
        case 'permit_payment_receipt':
            if ($post) show_error('Official Receipt download requires GET.',405);
            $viewer=require_user();
            $id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$id) show_error('Invalid FP application ID.',400);
            permit_payment_send_receipt($viewer,(int)$id);
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
        case 'user_management_export':
            if ($post) show_error('Excel download requires a GET request.',405);
            user_management_export_download();
            break;
        case 'user_login_logs':
            if ($post) show_error('Login Logs is read-only.',405);
            view_user_login_logs();
            break;
        case 'user_force_logout':
            $admin=require_role('super_admin');
            if (!$post) show_error('Force Logout requires a confirmed POST request.',405);
            check_csrf();
            $id=filter_var($_POST['user_id'] ?? 0,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]) ?: 0;
            $errors=user_management_force_logout($admin,(int)$id,safe_post('reason',500));
            if ($errors) {
                flash('danger',(string)$errors[0]);
            } else {
                flash('success','All active sessions for the selected account were revoked.');
            }
            go('user_management');
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
                if (($_POST['action'] ?? '') === 'defer_initial_password_change') {
                    if ((int)($user['must_change_password'] ?? 0) !== 1) {
                        show_error('There is no initial password change to postpone.',400);
                    }
                    defer_initial_password_change($user);
                    flash('warning', 'Initial password change postponed for this login session. '
                        . 'Please change it soon using the Password menu.');
                    go('dashboard');
                }
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
