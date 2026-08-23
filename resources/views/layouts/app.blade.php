<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'LLM Archive') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen overflow-x-hidden bg-zinc-50 text-zinc-900 antialiased">
    <div class="flex min-h-screen w-full">
        <aside class="sticky top-0 hidden h-screen w-56 shrink-0 border-r border-zinc-200 bg-white p-6 md:block">
            <div class="mb-8 text-lg font-semibold">{{ config('app.name', 'LLM Archive') }}</div>
            <nav class="space-y-1 text-sm">
                <a href="{{ route('conversations.index') }}" @class(['block rounded-md px-3 py-2', 'bg-zinc-100 font-medium' => request()->routeIs('conversations.*') && ! request()->routeIs('conversations.search')])>Conversations</a>
                <a href="{{ route('conversations.search') }}" @class(['block rounded-md px-3 py-2', 'bg-zinc-100 font-medium' => request()->routeIs('conversations.search')])>Search</a>
                <a href="{{ route('projects.index') }}" @class(['block rounded-md px-3 py-2', 'bg-zinc-100 font-medium' => request()->routeIs('projects.*')])>Projects</a>
                <a href="{{ route('library.index') }}" @class(['block rounded-md px-3 py-2', 'bg-zinc-100 font-medium' => request()->routeIs('library.*')])>Library</a>
                <a href="{{ route('imports.upload') }}" @class(['block rounded-md px-3 py-2', 'bg-zinc-100 font-medium' => request()->routeIs('imports.*')])>Import</a>
                <a href="{{ route('exports.index') }}" @class(['block rounded-md px-3 py-2', 'bg-zinc-100 font-medium' => request()->routeIs('exports.*')])>Export</a>
                <a href="{{ route('account.settings') }}" @class(['block rounded-md px-3 py-2', 'bg-zinc-100 font-medium' => request()->routeIs('account.*')])>Account</a>
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="mt-8">
                @csrf
                <button type="submit" class="text-sm text-zinc-500 hover:text-zinc-800">Sign out</button>
            </form>
        </aside>
        <main class="min-w-0 flex-1 p-6 md:p-10">
            <div class="mx-auto w-full max-w-7xl">
                @if (session('status'))
                    <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif
                {{ $slot }}
            </div>
        </main>
    </div>
    @livewireScripts
</body>
</html>
