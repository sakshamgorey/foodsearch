<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Follow the OS theme before first paint to avoid a light flash in dark mode. --}}
    <script>
        (function () {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            var apply = function () { document.documentElement.classList.toggle('dark', mq.matches); };
            apply();
            mq.addEventListener('change', apply);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
</head>
<body class="font-sans">
    @inertia
</body>
</html>
