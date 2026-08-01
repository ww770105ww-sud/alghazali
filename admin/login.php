<?php
require_once __DIR__ . '/../includes/session_config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit();
}

$settings = getSettings($pdo);

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في التحقق من الطلب (CSRF). يرجى المحاولة مرة أخرى.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if ($user['status'] == 'inactive') {
            $error = 'حسابك غير نشط. يرجى التواصل مع المسؤول.';
        } else {
            // Check if blocked_devices table exists first
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE 'blocked_devices'");
                $hasBlockedTable = $checkTable->fetch() !== false;
                
                $deviceBlocked = false;
                if ($hasBlockedTable) {
                    $device_fingerprint = generateDeviceFingerprint();
                    $deviceBlocked = isDeviceBlocked($user['id'], $device_fingerprint);
                }
                
                if ($deviceBlocked) {
                    $error = 'هذا الجهاز محظور من الدخول. يرجى التواصل مع المسؤول.';
                    logUserActivity(
                        $user['id'],
                        $user['username'],
                        $user['full_name'] ?? $user['username'],
                        'login_blocked',
                        'محاولة تسجيل دخول من جهاز محظور'
                    );
                } else {
                    // Create user session (handles multiple sessions setting)
                    $session_result = createUserSession($user['id']);

                    if (isset($session_result['success']) && $session_result['success'] === false) {
                        $error = $session_result['message'] ?? 'تعذر تسجيل الدخول.';
                        logUserActivity(
                            $user['id'],
                            $user['username'],
                            $user['full_name'] ?? $user['username'],
                            'login_failed',
                            $error
                        );
                    } else {
                        // Security: regenerate session ID to prevent session fixation attacks
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            session_regenerate_id(true);
                        }

                        $ua_parsed = function_exists('parseUserAgent') ? parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '') : null;
                        logUserActivity(
                            $user['id'],
                            $user['username'],
                            $user['full_name'] ?? $user['username'],
                            'login',
                            'تسجيل دخول ناجح | نوع الجهاز: ' . ($ua_parsed['device'] ?? 'desktop')
                        );

                        $_SESSION['admin_id'] = $user['id'];
                        $_SESSION['user_id']  = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                        $_SESSION['role']    = $user['role_name'] ?? $user['role'] ?? 'employee';
                        $_SESSION['role_id'] = $user['role_id'];
                        $_SESSION['branch_id'] = $user['branch_id'] ?? null;
                        $_SESSION['agent_id']  = $user['agent_id'] ?? null;
                        $_SESSION['login_ip']  = $_SERVER['REMOTE_ADDR'] ?? null;
                        $_SESSION['login_at']  = time();

                        // Update DB row with the newly regenerated session_id
                        try {
                            $update_sess = $pdo->prepare("
                                UPDATE user_sessions
                                   SET session_id = ?
                                 WHERE user_id = ? AND status = 'active'
                                 ORDER BY started_at DESC LIMIT 1
                            ");
                            $update_sess->execute([session_id(), $user['id']]);
                        } catch (Throwable $t) {
                            error_log("Login session_id sync warning: " . $t->getMessage());
                        }

                        header('Location: index.php');
                        exit();
                    }
                }
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
                $error = 'حدث خطأ داخلي أثناء تسجيل الدخول. يرجى المحاولة مرة أخرى.';
            }
        }
    } else {
        // Log failed login attempt
        if (!empty($username)) {
            logUserActivity(
                null,
                $username,
                null,
                'login_failed',
                'محاولة تسجيل دخول فاشلة'
            );
        }
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
    }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول | <?php echo $settings['site_name'] ?? 'نظام الغزالي'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/icon-fallback.css?v=20260617">
    <link rel="stylesheet" href="../assets/css/unified-design.css?v=20260430">
    <script>
        (function() {
            function enableIconFallback() {
                var cssReady = false;
                var fontReady = false;
                try {
                    var probe = document.createElement('i');
                    probe.className = 'fas fa-check';
                    probe.style.position = 'absolute';
                    probe.style.opacity = '0';
                    probe.style.pointerEvents = 'none';
                    document.body.appendChild(probe);
                    var content = window.getComputedStyle(probe, '::before').getPropertyValue('content');
                    cssReady = !!content && content !== 'none' && content !== 'normal' && content !== '""';
                    probe.remove();
                } catch (e) {}
                try {
                    fontReady = document.fonts && (
                        document.fonts.check('900 1em "Font Awesome 6 Free"') ||
                        document.fonts.check('900 1em "Font Awesome 5 Free"') ||
                        document.fonts.check('400 1em "Font Awesome 6 Brands"') ||
                        document.fonts.check('400 1em "Font Awesome 5 Brands"')
                    );
                } catch (e) {}
                document.documentElement.classList.toggle('fa-fallback', !cssReady || !fontReady);
            }
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(enableIconFallback);
            }
            document.addEventListener('DOMContentLoaded', enableIconFallback);
            window.addEventListener('load', enableIconFallback);
            setTimeout(enableIconFallback, 350);
            setTimeout(enableIconFallback, 1200);
        })();
    </script>
    <style>
        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            background: #e8ebee;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #212529;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 16px;
        }

        .login-card {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid #d8dde2;
        }

        .card-header-custom {
            padding: 28px 24px 18px;
            text-align: center;
            background: #f5f7f9;
            border-bottom: 1px solid #dee2e6;
        }

        .logo-wrapper {
            width: 80px;
            height: 80px;
            margin: 0 auto 16px;
            background: #ffffff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dde2e7;
        }

        .logo-wrapper img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .login-title {
            color: #222d32;
            font-weight: 700;
            font-size: 1.4rem;
            margin-bottom: 8px;
        }

        .login-subtitle {
            color: #6c757d;
            font-size: 0.95rem;
            margin-bottom: 24px;
        }

        .form-control {
            border-radius: 8px;
            padding: 11px 14px;
            background: #ffffff;
            border: 1px solid #ced4da;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus {
            background: #ffffff;
            border-color: #5c7c98;
            box-shadow: 0 0 0 0.15rem rgba(92, 124, 152, 0.18);
        }

        .btn-login {
            border-radius: 8px;
            padding: 12px 0;
            font-weight: 600;
            font-size: 1rem;
            background: #2c4a66;
            color: #ffffff;
            border: none;
            transition: background 0.2s ease, transform 0.2s ease;
            margin-top: 8px;
        }

        .btn-login:hover {
            background: #20354a;
            transform: translateY(-1px);
        }

        .input-group-text {
            background: #f8f9fb;
            border: 1px solid #ced4da;
            border-radius: 8px 0 0 8px;
            color: #6c757d;
        }

        .input-group .form-control {
            border-left: 0;
        }

        .card-body {
            padding: 24px;
        }

        .card-footer {
            background: #ffffff;
            border-top: 1px solid #dee2e6;
        }

        .card-footer a {
            color: #5c7c98;
        }

        .card-footer a:hover {
            color: #31495e;
            text-decoration: none;
        }

        .alert {
            border-radius: 8px;
            font-size: 0.95rem;
            border: 1px solid #f5c6cb;
            background: #f8d7da;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card animate__animated animate__fadeInUp">
            <div class="card-header-custom">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <div class="logo-wrapper">
                    <?php if (!empty($settings['site_logo'])): ?>
                        <img src="../assets/uploads/<?php echo $settings['site_logo']; ?>" alt="Logo">
                    <?php else: ?>
                        <i class="fas fa-plane-departure fa-3x text-primary"></i>
                    <?php endif; ?>
                </div>
                <h1 class="login-title"><?php echo $settings['site_name'] ?? 'نظام الغزالي'; ?></h1>
                <p class="login-subtitle">مرحباً بك، يرجى تسجيل الدخول للمتابعة</p>
            </div>
            <div class="card-body p-4 pt-0">
                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <div><?php echo $error; ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted">اسم المستخدم</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0 bg-light"><i class="fas fa-user"></i></span>
                            <input type="text" name="username" class="form-control border-start-0 bg-light" placeholder="أدخل اسم المستخدم" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted">كلمة المرور</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0 bg-light"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" class="form-control border-start-0 bg-light" placeholder="أدخل كلمة المرور" required>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-login">
                            <i class="fas fa-sign-in-alt me-2"></i> دخول لوحة التحكم
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-footer bg-light border-0 text-center py-3">
                <a href="../index.php" class="text-decoration-none text-muted small">
                    <i class="fas fa-arrow-right me-1"></i> العودة للموقع الرئيسي
                </a>
            </div>
        </div>
    </div>
</body>

</html>
