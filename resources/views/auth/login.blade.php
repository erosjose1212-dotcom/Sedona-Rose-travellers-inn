<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#572039">
    <title>Staff Terminal Sign-In | {{ config('sedona.hotel_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <aside class="login-brand-panel">
        <div class="app-brand">
            <img class="login-brand-logo" src="{{ asset('billing-sedona-logo.png') }}" alt="Sedona Court logo" width="52" height="68">
            <span><span class="brand-name">SEDONA COURT</span><span class="brand-caption">TRAVELLERS INN</span></span>
        </div>
        <div class="login-intro">
            <span class="login-eyebrow">Your daily operations, simplified</span>
            <h1>A clear view.<br>A smoother stay.</h1>
            <p>From the first check-in to the final receipt, keep every part of your property working together.</p>
        </div>
        <div class="login-brand-footer">{{ config('sedona.hotel_name') }} · Property management</div>
    </aside>
    <main class="login-form-panel">
        <div class="login-form-card">
            <h2>Welcome back</h2>
            <p class="login-subtitle">Sign in to your staff workspace.</p>
            @if(session('error'))
                <div role="alert" class="mb-5 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">{{ session('error') }}</div>
            @endif
            @if(session('info'))
                <div role="status" class="mb-5 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800">{{ session('info') }}</div>
            @endif
            <form method="POST" action="{{ route('login.submit') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email">Email / Operator ID</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="operator@sedonapms.com" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')
                        <p id="email-error" role="alert" class="mt-2 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password" @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password')
                        <p id="password-error" role="alert" class="mt-2 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>
                <label class="remember-label">
                    <input type="checkbox" name="remember" class="accent-brand" @checked(old('remember'))>
                    <span>Keep terminal signed in</span>
                </label>
                <button type="submit" class="login-submit">Sign In to Terminal <span class="ml-2" aria-hidden="true">→</span></button>
            </form>
            <p class="login-form-footer">{{ config('sedona.hotel_name') }} · Staff access</p>
        </div>
    </main>
</body>
</html>
