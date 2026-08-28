<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name', 'FaPost') }}</title>
    <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700&family=Victor+Mono:wght@400;500&display=swap">
    <style>
        /* Matches the admin panels: same sage primary, same warm neutral ground,
           so arriving here and then signing in does not feel like two products. */
        :root {
            --ground:   #f5f4f1;
            --surface:  #ffffff;
            --line:     #e8e5e0;
            --line-2:   #d8d4cd;
            --ink:      #1c1917;
            --ink-2:    #6b6560;
            --ink-3:    #a09b94;
            --sage:     #5a6e58;
            --sage-2:   #47593f;
            --sage-bg:  #eaf0e8;
            --font-sans: "DM Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            --font-mono: "Victor Mono", ui-monospace, Menlo, Consolas, monospace;
            color-scheme: light;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --ground:  #131211;
                --surface: #1b1917;
                --line:    #2c2825;
                --line-2:  #3b3631;
                --ink:     #f2efea;
                --ink-2:   #a8a29a;
                --ink-3:   #756f68;
                --sage:    #94a187;
                --sage-2:  #b3bfa6;
                --sage-bg: #232821;
                color-scheme: dark;
            }
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 2rem 1.25rem;
            background: var(--ground);
            color: var(--ink);
            font-family: var(--font-sans);
            font-size: 16px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        main { width: 100%; max-width: 46rem; }

        .mark { border-radius: 50%; display: block; }

        .brand {
            display: flex; align-items: center; gap: .6rem;
            font-weight: 700; font-size: 1.05rem; letter-spacing: -.02em;
            margin-bottom: 2.2rem;
        }

        h1 {
            margin: 0 0 .9rem;
            font-size: clamp(1.9rem, 5vw, 2.6rem);
            font-weight: 700;
            letter-spacing: -.03em;
            line-height: 1.1;
            text-wrap: balance;
        }

        .lede { margin: 0 0 2rem; color: var(--ink-2); max-width: 46ch; }

        .cta {
            display: inline-flex; align-items: center; gap: .55rem;
            padding: .8rem 1.4rem; border-radius: 9px;
            background: var(--sage); color: var(--surface);
            font-size: .98rem; font-weight: 500; text-decoration: none;
            transition: background-color .15s ease;
        }
        .cta:hover { background: var(--sage-2); }

        .after { margin: 1.1rem 0 0; color: var(--ink-3); font-size: .9rem; max-width: 46ch; }

        .links {
            display: flex; flex-wrap: wrap; gap: 1.4rem;
            margin-top: 2rem; padding-top: 1.4rem;
            border-top: 1px solid var(--line);
            font-size: .93rem;
        }
        .links a { color: var(--ink-2); text-decoration: none; }
        .links a:hover { color: var(--sage); }

        .meta { margin-top: 1.6rem; color: var(--ink-3); font-size: .84rem; font-family: var(--font-mono); }

        a:focus-visible { outline: 2px solid var(--sage); outline-offset: 3px; border-radius: 6px; }
    </style>
</head>
<body>
<main>
    <div class="brand">
        <img class="mark" src="{{ asset('logo.png') }}" width="28" height="28" alt="">
        {{ config('app.name', 'FaPost') }}
    </div>

    <h1>Conversational assistants, running on your own infrastructure.</h1>

    <p class="lede">
        This installation is ready. Assistants connect to messaging channels, hold conversations
        with contacts, and run them through flows you design on a visual canvas.
    </p>

    <a class="cta" href="{{ $adminUrl }}">
        Open the admin panel
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M3 8h9M9 4l4 4-4 4"/>
        </svg>
    </a>

    <p class="after">
        Assistants, flows, contacts and conversations each have their own console,
        reached from the assistant you open in the panel.
    </p>

    <div class="links">
        <a href="https://docs.fapost.in">Documentation</a>
        <a href="https://docs.fapost.in/self-hosting/overview">Self-hosting</a>
        <a href="https://docs.fapost.in/extending/extension-model">Extending</a>
        <a href="https://fapost.in">fapost.in</a>
    </div>

    <p class="meta">
        {{ config('app.name', 'FaPost') }} · Apache-2.0
        @if (app()->environment('local', 'development'))
            · {{ app()->environment() }}
        @endif
    </p>
</main>
</body>
</html>
