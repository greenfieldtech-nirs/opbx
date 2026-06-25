<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You've been invited to join {{ $organizationName }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h1>You've been invited to join {{ $organizationName }}</h1>

    <p>Click the button below to accept your invitation and create your OPBX account.</p>

    <p>
        <a href="{{ $inviteLink }}" style="display: inline-block; padding: 12px 24px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 4px;">Accept Invitation</a>
    </p>

    <p>This link expires in {{ $ttlHours }} hours.</p>

    <p>If you did not expect this invitation, you can ignore this email.</p>
</body>
</html>
