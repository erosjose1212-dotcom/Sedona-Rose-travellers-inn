<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add-ons — @yield('title')</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white">
    @if(session('success'))
        <p role="status" class="m-3 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p role="alert" class="m-3 rounded border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{{ session('error') }}</p>
    @endif
    <main>@yield('content')</main>
    @stack('scripts')
    <script>
        @if(session('success'))
            window.parent.postMessage({ type: 'sedona:guest-orders-updated' }, window.location.origin);
        @endif
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                window.parent.postMessage({ type: 'sedona:guest-orders-close' }, window.location.origin);
            }
        });
    </script>
</body>
</html>
