<!-- resources/views/emails/otp.blade.php -->

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>OTP Code</title>
</head>
<body>
    <p>Hello,</p>
    <p>Your verification code is: <strong>{{ $otp }}</strong></p>
    <p>This code will expire in 10 minutes.</p>
    <p>If you did not request this, please ignore this email.</p>
</body>
</html>