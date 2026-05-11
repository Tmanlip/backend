<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Case Update</title>
</head>
<body>
    <p>Hello,</p>
    <p>The case <strong>{{ $caseTitle }}</strong> (Case ID: {{ $caseId }}) has a new update.</p>

    <p><strong>Update:</strong> {{ $actionLabel }}</p>
    @if(!empty($actorName))
        <p><strong>Updated by:</strong> {{ $actorName }}</p>
    @endif
    <p><strong>Details:</strong> {{ $summary }}</p>

    <p>Please log in to your ASLAW account to review the latest changes.</p>
</body>
</html>