<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something has gone wrong.</title>
    <style>
        html, body {
            margin: 0;
            min-height: 100%;
            background: #070708;
            color: #e7d7b8;
        }
        body {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        main {
            padding: 2rem 1.25rem;
            text-align: center;
        }
        img {
            width: min(720px, 92vw);
            height: auto;
        }
        p {
            margin: 1.75rem 0 0;
            font-family: Georgia, "Palatino Linotype", Palatino, serif;
            font-size: 1.75rem;
            letter-spacing: 0.02em;
        }
    </style>
</head>
<body>
    <main>
        <img src="{{ rtrim((string) config('filesystems.disks.s3.url'), '/') }}/branding/eldritch-runes-crest.jpg" alt="Eldritch Runes">
        <p>Something has gone wrong.</p>
    </main>
</body>
</html>
