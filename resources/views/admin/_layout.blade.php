<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#00603a">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('storefront.admin.title'))</title>

    <link rel="icon" href="{{ asset('favicon.png') }}" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/tokens.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/base.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/components.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/admin.css') }}">
</head>
<body class="admin">
    @yield('body')
</body>
</html>
