<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exportar PDF — {{ $title }}</title>
    @vite(['resources/js/pdf/export.js'])
</head>
<body>
    <main>
        <h1>Exportar PDF</h1>
        <p>{{ $title }}</p>
        <p id="pdf-status">Preparando el PDF…</p>
        @if (! empty($warnings))
            <ul id="pdf-warnings">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
        <a id="pdf-download" href="#" hidden>Descargar PDF</a>
    </main>
    <script type="application/json" id="pdf-input">@json($input)</script>
    <script type="application/json" id="pdf-resources">@json($resources)</script>
</body>
</html>
