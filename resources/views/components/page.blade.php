@props([
    'title' => '',
    // What the page calls itself in its own header, when that differs from the
    // browser-tab title (e.g. tab "Student | Awa Jaw", header "Student record").
    'heading' => null,
    'bgColor' => 'bg-canvas',
    // Opt-in standard centered content wrapper. Most pages render their own
    // (Livewire components, custom layouts), so default off; pages with a
    // simple "centered card of content" pattern pass wrap=true.
    'wrap' => false,
    'width' => 'max-w-4xl',
])

<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <title>{{ 'KUBO'.(!empty($title) ? ' | '.$title : '') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="/favicon-192.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @livewireStyles()
    {{-- Paint the sidebar in its remembered width before Alpine boots, so a
         collapsed menu doesn't flash open on every page load. The kubo-preinit
         scope (and the transition freeze) is dropped as soon as Alpine has
         applied its own bindings. --}}
    <script>
        document.documentElement.classList.add('kubo-preinit');
        try { if (localStorage.getItem('kubo-sidebar') === 'false') document.documentElement.classList.add('kubo-sb-closed'); } catch (e) {}
    </script>
    <style>
        html.kubo-preinit [data-sidebar] { width: 14rem; }
        html.kubo-preinit.kubo-sb-closed [data-sidebar] { width: 3rem; }
        html.kubo-preinit [data-sidebar], html.kubo-preinit [data-sidebar] * { transition: none !important; }
    </style>
</head>

<body>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:p-4 focus:bg-white focus:text-gray-900">Skip to content</a>
    {{-- min-h-screen on phones: without it the shell is only as tall as its content,
         so a page with little on it (an empty lesson-plan list) left the canvas
         colour stopping halfway down and white underneath. --}}
    <div class="flex flex-col bg-white min-h-screen sm:h-screen" x-data="{ sidebarOpen: $persist(true).as('kubo-sidebar') }" x-init="$nextTick(() => document.documentElement.classList.remove('kubo-preinit', 'kubo-sb-closed'))">
        {{-- Not on the picker: that page is the demo's own front door. --}}
        @if (config('app.demo') && ! request()->routeIs('demo.picker'))
        @php
            $demoRoles = \App\Http\Controllers\NewInterfaceControllers\DemoController::availableRoles();
            $demoRoleMeta = \App\Http\Controllers\NewInterfaceControllers\DemoController::ROLES;
            $currentRole = auth()->check() ? auth()->user()->getRoleNames()->first() : null;
        @endphp

        {{-- The bar is scaffolding around the product, not part of it: it folds away to
             a small tab, so the school itself is what fills the screen. Open, it puts
             every role one click away, because that is what a visitor came to try. --}}
        <div x-data="{ open: $persist(true).as('kubo-demo-bar') }" class="relative z-40 shrink-0">
            <div x-show="open" x-collapse
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-2 text-sm text-white bg-[#4a76b9]">
                <span>
                    <strong class="font-bold tracking-wide uppercase">Demo</strong>
                    <span class="ml-1 text-white/85">&mdash; sample data, resets nightly.</span>
                </span>

                {{-- Not on the picker: that page is the role switch. --}}
                @unless (request()->routeIs('demo.picker'))
                {{-- On a phone the six roles wrapped to three lines and ate the screen;
                     there, one link to the picker does the same job. --}}
                <a href="{{ route('demo.picker') }}"
                    class="px-3 py-1 text-xs font-bold rounded-full sm:hidden text-[#0c0822] bg-[#ffcd31]">Switch role</a>
                <span class="items-center hidden gap-1 sm:flex sm:flex-wrap">
                    <span class="mr-1 text-white/70">{{ auth()->check() ? 'You are:' : 'Sign in as:' }}</span>
                    @foreach ($demoRoles as $role => $person)
                        @php $label = $demoRoleMeta[$role]['label']; @endphp
                        @if ($role === $currentRole)
                            <span class="px-3 py-1 font-bold rounded-full bg-[#ffcd31] text-[#0c0822]"
                                title="{{ $person->getFullNameAttribute() }}">{{ $label }}</span>
                        @elseif ($role === 'student')
                            {{-- A child signs in by picking their class and tapping their name;
                                 don't hand a visitor that session, walk them into it. --}}
                            <a href="{{ route('student-login.select-grade') }}"
                                class="px-3 py-1 rounded-full text-white/90 hover:bg-white/15">{{ $label }}</a>
                        @else
                            <form method="POST" action="{{ route('demo.login', $role) }}" class="inline">
                                @csrf
                                <button type="submit" title="{{ $person->getFullNameAttribute() }}"
                                    class="px-3 py-1 rounded-full text-white/90 hover:bg-white/15">{{ $label }}</button>
                            </form>
                        @endif
                    @endforeach
                </span>
                @endunless

                {{-- Quiet: a visitor rarely wants this, and it wipes what they were
                     looking at. It should be findable, not inviting. --}}
                <form method="POST" action="{{ route('demo.reset') }}"
                    onsubmit="return confirm('Reset the demo to fresh sample data? This wipes anything changed in the demo and takes about half a minute.')">
                    @csrf
                    <button type="submit" class="text-xs text-white/60 hover:text-white/90 hover:underline">Reset data</button>
                </form>
            </div>

            {{-- The tab: sticks out of the bar, folds it away and brings it back. --}}
            <button type="button" @click="open = !open"
                :aria-expanded="open ? 'true' : 'false'"
                class="absolute left-1/2 -translate-x-1/2 flex items-center gap-1 px-3 py-0.5 text-xs font-bold text-white bg-[#4a76b9] rounded-b-lg shadow-sm hover:brightness-110">
                <span x-show="!open" class="tracking-wide uppercase">Demo</span>
                <svg class="w-3.5 h-3.5 transition-transform" :class="open ? '' : 'rotate-180'"
                    fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 15l-7-7-7 7" />
                </svg>
                <span class="sr-only" x-text="open ? 'Hide the demo bar' : 'Show the demo bar'"></span>
            </button>
        </div>
        @endif
        <div class="flex flex-1 overflow-hidden">
            @auth<x-navigation.menu />@endauth
            <main id="main-content" class="relative z-0 flex-1 overflow-y-auto focus:outline-none {{ $bgColor }}" tabindex="0">
                @auth
                <x-header :title='$title' :heading='$heading' />
                @endauth

                {{-- Flash region: aligned with the page content's horizontal padding
                     (not edge-to-edge) and only rendered when there is something to
                     show, so it doesn't sit as a detached full-width band. --}}
                @if ($errors->any() || session('success') || session('error') || session('warning'))
                <div class="px-4 pt-6 sm:px-6 lg:px-8">
                    @if ($errors->any())
                    <x-error class='mb-4'>
                        <ul>
                            @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-error>
                    @endif

                    @if ($message = Session::get('success'))
                    <x-success class='mb-4'>{{ $message }}</x-success>
                    @endif

                    @if ($message = Session::get('error'))
                    <x-error class='mb-4'>{{ $message }}</x-error>
                    @endif

                    @if ($message = Session::get('warning'))
                    <div class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert">{{ $message }}</div>
                    @endif
                </div>
                @endif

                @if ($wrap)
                    <div class="{{ $width }} mx-auto px-4 py-8 sm:px-6 lg:px-8">
                        {{ $slot }}
                    </div>
                @else
                    {{ $slot }}
                @endif
            </main>
        </div>
    </div>
    @livewireScripts()
</body>