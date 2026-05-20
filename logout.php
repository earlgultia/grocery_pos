<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

$logoutRequested = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout']);

if (!$logoutRequested && !isLoggedIn()) {
    header('Location: login.php');
    exit();
}

if ($logoutRequested) {
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', 'User logged out');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - Grocery POS</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(79, 70, 229, 0.16), transparent 28%),
                radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.12), transparent 24%),
                linear-gradient(180deg, #eef2ff 0%, #f8fafc 100%);
            color: #0f172a;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: linear-gradient(rgba(255,255,255,0.48) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.48) 1px, transparent 1px);
            background-size: 40px 40px;
            mask-image: linear-gradient(180deg, rgba(0,0,0,0.18), transparent 88%);
            pointer-events: none;
        }

        .logout-card {
            position: relative;
            width: min(92vw, 540px);
            padding: 2.2rem;
            border-radius: 1.6rem;
            background: rgba(255, 255, 255, 0.84);
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 28px 70px rgba(15, 23, 42, 0.12);
            backdrop-filter: blur(18px);
            z-index: 1;
        }

        .logout-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            background: rgba(79, 70, 229, 0.10);
            color: #4338ca;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            margin-bottom: 1rem;
        }

        .logout-card h1 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            letter-spacing: -0.04em;
        }

        .logout-card p {
            margin: 0.85rem 0 0;
            color: #475569;
            line-height: 1.65;
            font-size: 1rem;
        }

        .logout-meta {
            margin-top: 1.35rem;
            display: grid;
            gap: 0.75rem;
        }

        .logout-note {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1rem;
            border-radius: 1rem;
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(15, 23, 42, 0.08);
            color: #0f172a;
            font-weight: 600;
        }

        .logout-note span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 0.7rem;
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: #fff;
            font-size: 1rem;
        }

        .logout-progress {
            margin-top: 1rem;
            display: flex;
            gap: 0.7rem;
            align-items: center;
            color: #64748b;
            font-size: 0.92rem;
        }

        .logout-dot {
            width: 0.6rem;
            height: 0.6rem;
            border-radius: 999px;
            background: #22c55e;
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.3);
            animation: pulse 1.8s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.35); }
            70% { box-shadow: 0 0 0 12px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        .swal2-popup.logout-swal {
            border-radius: 1.4rem;
            padding: 1.45rem 1.25rem 1.3rem;
            box-shadow: 0 26px 60px rgba(15, 23, 42, 0.18);
        }

        .swal2-title {
            font-family: 'Space Grotesk', sans-serif;
            letter-spacing: -0.03em;
            color: #0f172a;
        }

        .swal2-html-container {
            color: #475569;
        }

        .swal-confirm,
        .swal-cancel {
            min-width: 140px;
            border: 0 !important;
            border-radius: 0.9rem !important;
            padding: 0.82rem 1rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.01em;
            margin: 0 0.35rem !important;
            box-shadow: none !important;
        }

        .swal-confirm {
            background: linear-gradient(135deg, #ef4444, #dc2626) !important;
        }

        .swal-cancel {
            background: rgba(15, 23, 42, 0.08) !important;
            color: #0f172a !important;
        }

        .swal-success-confirm {
            min-width: 160px;
            border: 0 !important;
            border-radius: 0.9rem !important;
            padding: 0.82rem 1rem !important;
            font-weight: 700 !important;
            background: linear-gradient(135deg, #4f46e5, #4338ca) !important;
            box-shadow: none !important;
        }
    </style>
</head>
<body>
    <form id="logoutForm" method="post" action="logout.php" style="display:none;">
        <input type="hidden" name="confirm_logout" value="1">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const logoutComplete = <?php echo $logoutRequested ? 'true' : 'false'; ?>;
        const returnUrl = document.referrer && document.referrer !== window.location.href ? document.referrer : 'store_dashboard.php';

        function showLogoutConfirm() {
            Swal.fire({
                title: 'Are you sure you want to logout?',
                text: 'You will need to sign in again to continue.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, log me out',
                cancelButtonText: 'Stay signed in',
                buttonsStyling: false,
                customClass: {
                    popup: 'logout-swal',
                    confirmButton: 'swal-confirm',
                    cancelButton: 'swal-cancel'
                },
                reverseButtons: true,
                focusCancel: true
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('logoutForm').submit();
                    return;
                }

                window.location.replace(returnUrl);
            });
        }

        function showLogoutSuccess() {
            window.location.replace('login.php');
        }

        if (logoutComplete) {
            showLogoutSuccess();
        } else {
            showLogoutConfirm();
        }
    </script>
</body>
</html>