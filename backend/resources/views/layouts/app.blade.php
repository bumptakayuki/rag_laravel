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
    @php
        $nav = [
            ['route' => 'search', 'label' => '検索', 'active' => request()->routeIs('search', 'search.run', 'candidates.*')],
            ['route' => 'jobs.index', 'label' => '求人票', 'active' => request()->routeIs('jobs.*')],
            ['route' => 'history.index', 'label' => '履歴', 'active' => request()->routeIs('history.*')],
        ];
    @endphp
    <header class="border-b border-[#E3E6EA] bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="{{ route('search') }}" class="flex items-center gap-2 font-bold text-[#1B1F24] no-underline">
                <span class="flex h-7 w-7 items-center justify-center rounded-md bg-[#2457C5] text-sm text-white">R</span>
                簡易RAG Lab
            </a>
            <nav class="flex gap-1 text-sm" aria-label="メイン">
                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}"
                       @if ($item['active']) aria-current="page" @endif
                       class="rounded-md px-3 py-1.5 {{ $item['active'] ? 'bg-[#EAF0FC] font-semibold text-[#1A3F8F]' : 'text-[#5B6470] hover:text-[#1B1F24]' }}">{{ $item['label'] }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        {{-- 外部 API の失敗・実行できなかった理由（RunsRagPipeline が渡す） --}}
        @if (session('api_error'))
            <div role="alert" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800" data-testid="api-error">
                {{ session('api_error') }}
            </div>
        @endif

        @yield('content')
    </main>

    {{--
        実行中の表示（スピナー）。data-loading="表示する文" を付けたフォームを送信すると、
        送信ボタンを押せなくして（二重送信で API を2回呼ばないように）スピナーと文を出す。
        結果の画面へ移れば元に戻る。ブラウザの「戻る」でこの画面がそのまま表示されたときも戻す
    --}}
    <template id="loading-label">
        <span class="inline-flex items-center justify-center gap-2">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                <path d="M4 12a8 8 0 0 1 8-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
            </svg>
            <span data-loading-text></span>
        </span>
    </template>
    <script>
    (() => {
        const template = document.getElementById('loading-label');

        document.addEventListener('submit', (event) => {
            const form = event.target.closest('form[data-loading]');
            if (!form || event.defaultPrevented) return;

            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.dataset.originalLabel = button.innerHTML;
                const label = template.content.cloneNode(true);
                label.querySelector('[data-loading-text]').textContent = form.dataset.loading;
                button.replaceChildren(label);
                button.disabled = true;
            });
        });

        // 「戻る」で前の画面がキャッシュから表示されたとき、押せないボタンのまま残らないようにする
        window.addEventListener('pageshow', () => {
            document.querySelectorAll('form[aria-busy="true"]').forEach((form) => {
                form.removeAttribute('aria-busy');
                form.querySelectorAll('button[data-original-label]').forEach((button) => {
                    button.innerHTML = button.dataset.originalLabel;
                    button.disabled = false;
                });
            });
        });
    })();
    </script>
</body>
</html>
