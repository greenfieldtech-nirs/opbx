<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duplicate invitation attempt</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h1>Duplicate invitation attempt</h1>

    <p>
        An invitation was attempted for <strong>{{ $email }}</strong> in organization <strong>{{ $organizationName }}</strong>
        by {{ $inviterName }} ({{ $inviterEmail }}), but a user with that email already exists.
    </p>

    <p>
        <a href="{{ $usersUrl }}" style="display: inline-block; padding: 12px 24px; background-color: #dc3545; color: #ffffff; text-decoration: none; border-radius: 4px;">Review users</a>
    </p>
</body>
</html>
