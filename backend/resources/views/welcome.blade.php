<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    {{-- 画面は Blade + Tailwind CDN で作る（要件定義書 第4.4章）。ビルド工程は持たない --}}
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.11"></script>
</head>
<body class="bg-gray-50 text-gray-900">
    <main class="mx-auto max-w-3xl px-4 py-16">
        <h1 class="text-2xl font-bold">{{ config('app.name') }}</h1>
        <p class="mt-2 text-gray-600">開発環境は起動しています。質問画面は今後のステップで作ります。</p>
    </main>
</body>
</html>
