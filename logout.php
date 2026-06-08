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
    
</head>
<body>
    <form id="logoutForm" method="post" action="logout.php" class="hidden">
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
