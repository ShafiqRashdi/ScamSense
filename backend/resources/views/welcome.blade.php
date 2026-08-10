<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'ScamSense') }}</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="bg-body-secondary">
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <h1 class="h3 mb-3">ScamSense</h1>
                <p class="text-body-secondary">
                    AI scam verification for Malaysian SMS, WhatsApp messages and links.
                </p>

                <div class="card mt-4">
                    <div class="card-body">
                        <p class="mb-2 fw-semibold">Placeholder — not implemented.</p>
                        <p class="mb-0 text-body-secondary">
                            The public verification interface is Phase 6: a single page with a
                            textarea, a message/URL toggle, and a result panel showing the verdict,
                            confidence and indicators in Bahasa Malaysia and English. This page
                            exists only so that Phase 0 can prove the application boots.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
