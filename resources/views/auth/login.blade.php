<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Administrator sign in · Identity Service</title>
    <link rel="stylesheet" href="/css/auth.css">
</head>
<body @if ($errors->any()) class="has-error" @endif>
    <header class="top-bar">
        <div class="top-bar-inner">
            <span class="brand-lockup">Identity Service</span>
            <span class="top-bar-meta">Sign in</span>
        </div>
    </header>

    <main class="stage">
        <section class="auth-shell" aria-labelledby="login-heading">
            <h1 class="auth-title" id="login-heading">Administrator sign in</h1>
            <p class="auth-subtitle">Sign in to manage accounts and access</p>

            @if ($errors->any())
                <div class="alert" role="alert">
                    <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.25a.75.75 0 0 1 1.5 0v4a.75.75 0 0 1-1.5 0v-4Zm.75 7.25a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"></path>
                    </svg>
                    <p class="alert-text">{{ $errors->first() }}</p>
                </div>
            @endif

            <form class="auth-form" method="post" action="{{ route('login.store') }}">
                @csrf
                <div class="field-group">
                    <div class="field">
                        <input
                            class="field-input"
                            id="email"
                            name="email"
                            type="email"
                            value="{{ is_string(old('email')) ? old('email') : '' }}"
                            placeholder=" "
                            required
                            autocomplete="username"
                            @if ($errors->any()) aria-invalid="true" @endif
                        >
                        <label class="field-label" for="email">Email</label>
                    </div>

                    <div class="field">
                        <input
                            class="field-input"
                            id="password"
                            name="password"
                            type="password"
                            placeholder=" "
                            required
                            autocomplete="current-password"
                            @if ($errors->any()) aria-invalid="true" @endif
                        >
                        <label class="field-label" for="password">Password</label>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn-primary" type="submit">Sign in</button>
                </div>
            </form>
        </section>
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <span>Identity Service</span>
            <span class="footer-meta">Administrator Console</span>
        </div>
    </footer>
</body>
</html>
