<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $user->name }} · Identity Service</title>
</head>
<body>
    <main>
        <p><a href="{{ route('admin.users.index') }}">Back to accounts</a></p>
        <h1>{{ $user->name }}</h1>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <dl>
            <dt>Email</dt><dd>{{ $user->email }}</dd>
            <dt>Role</dt><dd>{{ $user->role->value }}</dd>
            <dt>Status</dt><dd>{{ $user->disabled_at === null ? 'Active' : 'Disabled' }}</dd>
            <dt>Created</dt><dd>{{ $user->created_at->toIso8601String() }}</dd>
        </dl>

        @can('disable', $user)
            <form method="post" action="{{ route('admin.users.disable', $user) }}">
                @csrf
                @method('patch')
                <button type="submit">Disable account</button>
            </form>
        @endcan

        <h2>Account history</h2>
        <ul>
            @forelse ($auditLogs as $auditLog)
                <li>{{ $auditLog->created_at->toIso8601String() }} — {{ $auditLog->action }}</li>
            @empty
                <li>No account history recorded.</li>
            @endforelse
        </ul>
        {{ $auditLogs->links() }}
    </main>
</body>
</html>
