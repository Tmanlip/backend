<!DOCTYPE html>
<html>
<head>
    <title>Password Reset</title>
</head>
<body>
    <h2>Password Reset Request</h2>
    <p>Click the button below to reset your password:</p>

    <a href="{{ $resetUrl }}"
       style="padding:10px 20px;background:#f39c12;color:#fff;text-decoration:none;">
        Reset Password
    </a>

    <p>If you did not request this, please ignore this email.</p>
</body>
</html>
