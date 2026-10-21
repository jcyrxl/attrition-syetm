<?php
$resetDone = isset($_GET['reset']);
session_start();
// If already logged in, skip straight to the right home page for their role
if (!empty($_SESSION['user_id'])) {
    header('Location: registrar/' . ($_SESSION['role'] === 'admin' ? 'users.php' : 'dashboard.php'));
    exit;
}

$error = $_GET['error'] ?? '';
// After a failed log-in the User ID that was just typed is shown again (not an older one, not an empty box).
$typedUserId = (string) ($_SESSION['login_userid'] ?? '');
unset($_SESSION['login_userid']);
$errorMessage = '';
if ($error === 'invalid') {
    $errorMessage = 'Invalid User ID or Password. Please try again.';
} elseif ($error === 'empty') {
    $errorMessage = 'Please fill in both User ID and Password.';
} elseif ($error === 'locked') {
    $errorMessage = 'Too many incorrect attempts. For your security you were logged out, and confirmations stay locked for 24 hours.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attrition Risk Prediction | CCS UDM</title>
    <link rel="stylesheet" href="style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

</head>

<body>

<div class="container">

<div class="left-panel">

    <div class="overlay"></div>


    <div class="branding">

        <img src="images/ccs-logo.png" class="logo" alt="UDM College of Computing Studies logo">

        <h1>
            Attrition Risk <span>Prediction</span>
        </h1>

        <h3>College of Computing Studies</h3>

        <h4>Universidad de Manila</h4>

        <p>
            Predict early. Intervene sooner. Keep every student on track.
        </p>

    </div>

</div>

    <div class="right-panel">

        <div class="login-card">

            <img src="images/ccs-logo.png" class="card-logo" alt="CCS logo">

            <h1>Welcome!</h1>

            <p>
                Log in to continue to the Student Attrition Risk Prediction System
            </p>

            <?php if ($errorMessage): ?>
                <p style="background:#ffe4e4;color:#c62828;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:18px;">
                    <?= htmlspecialchars($errorMessage) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($resetDone)): ?><div style="background:#e8f6ec;color:#1b6b3a;border-radius:9px;padding:10px 14px;font-size:13px;margin-bottom:10px;">Your password was changed. You can log in now.</div><?php endif; ?>
<form action="login.php" method="POST">

                <label>User ID</label>

                <div class="input-box">

    <i class="fa-regular fa-user input-icon"></i>

    <input
        type="text"
        name="userid"
        id="userid"
        placeholder="Enter your User ID"
        value="<?= htmlspecialchars($typedUserId) ?>"
        autocomplete="off"
        required>

</div>
<div id="useridHint" style="display:none;color:#c62828;font-size:12px;margin:-14px 0 14px;"></div>


                <label>Password</label>

                <div class="input-box">

    <i class="fa-solid fa-lock input-icon"></i>

    <input
        type="password"
        id="password"
        name="password"
        placeholder="Enter your password"
        required>

    <span id="togglePassword">

        <i class="fa-regular fa-eye"></i>

    </span>

</div>


                <button type="submit">

                    LOG IN

                </button>
                <div style="text-align:center;margin-top:12px;"><a href="forgot_password.php" style="font-size:13.5px;color:#087fa7;font-weight:600;">Forgot password?</a></div>

            </form>


            <div class="footer">

                © 2026 College of Computing Studies
                <br>
                Universidad de Manila

            </div>

        </div>

    </div>

</div>

<script>

const togglePassword =
document.querySelector("#togglePassword");

const password =
document.querySelector("#password");

togglePassword.addEventListener("click",function(){

const type =
password.getAttribute("type")==="password"
?
"text"
:
"password";

password.setAttribute("type",type);

this.innerHTML=

type==="password"

?

'<i class="fa-regular fa-eye"></i>'

:

'<i class="fa-regular fa-eye-slash"></i>';

});

</script>

<script src="assets/validation.js?v=3"></script>
<script>
    lockUserIdField(document.getElementById('userid'), document.getElementById('useridHint'));
    // a failed log-in puts the cursor in the Password box when the User ID is already there
    (function () { var u = document.getElementById('userid'); if (u.value) { document.getElementById('password').focus(); } })();
</script>

</body>

</html>
