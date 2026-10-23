<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accounts · Identity Service</title>
</head>
<body>
    <main>
        <h1>Accounts</h1>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        <p><a href="{{ route('admin.users.create') }}">Create account</a></p>

        <table>
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td><a href="{{ route('admin.users.show', $user) }}">{{ $user->name }}</a></td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->role->value }}</td>
                        <td>{{ $user->disabled_at === null ? 'Active' : 'Disabled' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No accounts found.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{ $users->links() }}
    </main>
</body>
</html>
