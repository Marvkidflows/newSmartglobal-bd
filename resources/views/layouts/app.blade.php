<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart System Investment')</title>
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@300;400;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    @include('layouts.styles')
    @stack('styles')  <!-- THIS IS IMPORTANT! -->
</head>
<body>
    <div class="app">
        @include('layouts.navbar')
        @yield('content')
        @if(!Auth::check())
            @include('layouts.footer')
        @endif
    </div>
    @stack('scripts')