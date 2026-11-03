<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col">
        <main class="w-full max-w-2xl text-center">
            <h1 class="mb-2 text-2xl font-medium">{{ config('app.name', 'Laravel') }}</h1>
            <p class="mb-6 text-[#706f6c] dark:text-[#A1A09A]">AI 长篇网络小说自动生成系统</p>

            <div class="flex items-center justify-center gap-3 text-sm">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/dashboard') }}" class="inline-block px-5 py-1.5 border rounded-sm">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="inline-block px-5 py-1.5 border rounded-sm">Log in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="inline-block px-5 py-1.5 border rounded-sm">Register</a>
                        @endif
                    @endauth
                @endif
            </div>

            <p class="mt-8 text-xs text-[#706f6c] dark:text-[#A1A09A]">v{{ app()->version() }}</p>
        </main>
    </body>
</html>