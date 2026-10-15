<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>Eldritch Runes</title>
    <link rel="icon" type="image/png" href="{{ rtrim((string) config('filesystems.disks.s3.url'), '/') }}/branding/favicon.png">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="min-h-screen bg-ink text-parchment antialiased">
    @inertia
</body>
</html>
