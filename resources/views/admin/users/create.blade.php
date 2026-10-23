<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account · Identity Service</title>
</head>
<body>
    <main>
        <h1>Create account</h1>
        <p><a href="{{ route('admin.users.index') }}">Back to accounts</a></p>

        @if ($errors->any())
            <div role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('admin.users.store') }}">
            @csrf
            <label for="name">Name</label>
            <input id="name" name="name" value="{{ is_string(old('name')) ? old('name') : '' }}" required maxlength="255" autocomplete="name">

            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required maxlength="255" autocomplete="email">

            <label for="role">Role</label>
            <select id="role" name="role" required>
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(old('role', 'member') === $role->value)>{{ ucfirst($role->value) }}</option>
                @endforeach
            </select>

            <label for="password">Initial password</label>
            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
            <p>Use at least 12 characters and no more than 72 bytes.</p>

            <button type="submit">Create account</button>
        </form>
    </main>
</body>
</html>
