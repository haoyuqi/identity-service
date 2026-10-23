<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrator sign in</title>
</head>
<body>
    <main>
        <h1>Administrator sign in</h1>

        @if ($errors->any())
            <p role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="{{ route('login.store') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required autocomplete="username">

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">

            <button type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
