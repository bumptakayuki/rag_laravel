<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '簡易RAG')</title>
    {{-- 画面は Blade + Tailwind CDN（Vite / npm は使わない。docs/architecture.md） --}}
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
</head>
<body class="min-h-screen bg-[#F6F7F9] text-[#1B1F24] antialiased">
    <header class="border-b border-[#E3E6EA] bg-white">
        <div class="mx-auto flex max-w-6xl items-center gap-6 px-4 py-3">
            <a href="{{ route('search') }}" class="flex items-center gap-2 font-bold text-[#1B1F24] no-underline">
                <span class="flex h-7 w-7 items-center justify-center rounded-md bg-[#2457C5] text-sm text-white">R</span>
                簡易RAG Lab
            </a>
            <nav class="flex gap-4 text-sm">
                <a href="{{ route('search') }}" class="font-semibold text-[#2457C5]">検索</a>
                {{-- 求人票一覧・履歴は RS-006 で追加する --}}
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        @yield('content')
    </main>
</body>
</html>
