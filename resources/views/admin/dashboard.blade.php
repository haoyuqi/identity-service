<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration</title>
</head>
<body>
    <main>
        <h1>Administration</h1>
        <p>Administrator session is active.</p>
        <p><a href="{{ route('admin.users.index') }}">Manage accounts</a></p>
        <form method="post" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>
    </main>
</body>
</html>
