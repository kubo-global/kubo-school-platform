{{-- The demo's front door. It is the first thing a visitor sees after clicking
     "Try the demo" on kubo.global, so it wears that site's clothes: dark indigo,
     heavy Work Sans, the yellow call to action.

     A first-time visitor has never seen the product and should not have to read
     to find out what it is. So the two ways in are shown as the screens behind
     them: staff get a class's results sheet, pupils get the name list they sign
     in with. Both are drawn from the demo's own data, not from a picture, so
     they stay sharp and cannot go stale. Only ever rendered in DEMO_MODE, so
     the school app itself carries none of this. --}}
<x-page title="Try the KUBO School Platform" bg-color="bg-[#14102b]">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;500;600;700;800;900&display=swap">

  <style>
    /* kubo.global's tokens, so the demo doesn't feel like a different product. */
    .door-page {
      --night: #14102b;
      --soft: #d8d4e6;
      --muted: #b9b4cc;
      --yellow: #ffcd31;
      min-height: 100%;
      font-family: "Work Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      color: #fff;
      background: radial-gradient(1200px 700px at 15% 0%, #2a2a5c 0%, var(--night) 60%);
    }
    .door-page .inner { max-width: 80rem; margin: 0 auto; padding: 32px clamp(16px, 4.4vw, 56px) 48px; }
    .door-page .logo { display: flex; align-items: center; gap: 10px; font-weight: 800; letter-spacing: .02em; }
    .door-page .logo svg { height: 28px; fill: #fff; }
    .door-page h1 {
      margin-top: 44px; font-weight: 900; line-height: 1.04; letter-spacing: -.03em;
      font-size: clamp(2rem, 4.1vw, 3.05rem); text-wrap: balance;
    }
    .door-page .lead { margin-top: 14px; font-size: 1.15rem; line-height: 1.45; color: var(--soft); text-wrap: balance; }

    .door-page .doors { display: grid; gap: 48px 56px; margin-top: 56px; align-items: start; }
    @media (min-width: 900px) { .door-page .doors { grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); } }
    .door-page .doors > section { min-width: 0; }
    .door-page h2 { font-weight: 700; font-size: 1.1rem; }

    /* A door shows the screen behind it. The screen is a picture of the page, so
       it is cut off by the frame and fades out instead of ending on a hard edge. */
    .door-page .shot {
      display: block; width: 100%; height: 264px; margin-top: 14px; position: relative; overflow: hidden;
      border-radius: 12px; text-align: left; cursor: pointer;
      box-shadow: 0 0 0 1px rgba(255, 255, 255, .12), 0 20px 40px rgba(0, 0, 0, .35);
      transition: transform .15s ease;
    }
    .door-page .shot:hover { transform: translateY(-2px); }
    .door-page .shot::after { content: ""; position: absolute; inset: 0; pointer-events: none; }
    .door-page .shot > .zoom { display: block; zoom: .66; }
    .door-page .names > .zoom { zoom: .8; }

    .door-page .sheet { background: #e9ebf3; color: #1f2937; padding: 22px 26px 0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    .door-page .sheet::after { background: linear-gradient(90deg, transparent 90%, #e9ebf3 99%), linear-gradient(180deg, transparent 72%, #e9ebf3 98%); }
    .door-page .sheet .title { font-size: 1.05rem; font-weight: 600; }
    .door-page .sheet .period { font-size: .8rem; color: #6b7280; }
    .door-page .sheet table { width: 100%; margin-top: 16px; font-size: .9rem; border-collapse: collapse; }
    .door-page .sheet th { padding: 0 10px 10px; font-size: .8rem; font-weight: 500; white-space: nowrap; color: #6b7280; border-bottom: 1px solid #cfd3df; }
    .door-page .sheet td { padding: 12px 10px; border-bottom: 1px solid #dcdfe8; text-align: center; white-space: nowrap; color: #4b5563; }
    .door-page .sheet .name { width: 40%; text-align: left; color: #1f2937; }
    .door-page .sheet .no { color: #6b7280; }
    .door-page .sheet .strong { font-weight: 600; color: #1f2937; }

    .door-page .names { background: radial-gradient(500px 300px at 10% 0%, #2b3270 0%, #0f1030 70%); padding: 22px 26px 0; text-align: center; }
    .door-page .names::after { background: linear-gradient(180deg, transparent 72%, #0f1030 98%); }
    .door-page .names .title { font-size: 1.05rem; font-weight: 600; }
    .door-page .names .hint { margin-top: 2px; font-size: .8rem; color: #b9b4cc; }
    .door-page .names ul { margin: 16px auto 0; max-width: 20rem; display: grid; gap: 10px; }
    .door-page .names li { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 10px; background: #e9ebf3; color: #312e81; font-size: .9rem; font-weight: 500; text-align: left; }

    .door-page .row { display: flex; flex-wrap: wrap; align-items: center; gap: 14px 22px; margin-top: 18px; }
    .door-page .cta {
      display: inline-block; padding: 16px 26px; border-radius: 10px; cursor: pointer; white-space: nowrap;
      font-weight: 700; font-size: 1.05rem; color: var(--night); background: var(--yellow);
      transition: filter .15s ease, transform .15s ease;
    }
    .door-page .cta.ghost { color: #fff; background: transparent; box-shadow: inset 0 0 0 1.5px rgba(255, 255, 255, .55); }
    .door-page .cta:hover { filter: brightness(.95); transform: translateY(-1px); }
    .door-page .cta.ghost:hover { background: rgba(255, 255, 255, .1); filter: none; }
    .door-page .others { margin-top: 18px; display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 16px; font-size: .95rem; color: var(--muted); }
    .door-page .others button, .door-page .foot a {
      color: var(--soft); font-weight: 500; cursor: pointer;
      text-decoration: underline; text-decoration-color: rgba(255, 255, 255, .3); text-underline-offset: 5px;
    }
    .door-page .others button:hover, .door-page .foot a:hover { color: #fff; text-decoration-color: #fff; }
    .door-page :is(.cta, .shot, .others button, .foot a):focus-visible { outline: 2px solid var(--yellow); outline-offset: 3px; }

    .door-page .foot { display: flex; flex-wrap: wrap; gap: 8px 28px; margin-top: 56px; font-size: .85rem; color: var(--muted); }
    .door-page .foot a { color: var(--muted); }
    @media (prefers-reduced-motion: reduce) { .door-page .shot, .door-page .cta { transition: none; } }
  </style>

  @php
    $roles = \App\Http\Controllers\NewInterfaceControllers\DemoController::ROLES;
    // The headmaster sees all of the school, so that is the way in offered first.
    $first = isset($people['headmaster']) ? 'headmaster' : collect($people)->keys()->reject(fn ($r) => $r === 'student')->first();
    $pupilDoor = route('student-login.select-grade');
  @endphp

  <div class="door-page">
    <div class="inner">
      <span class="logo">
        <svg viewBox="0 0 238.63 231.78" aria-hidden="true">
          <path d="M227.39,72.13l11.24-6.48L124.94,0,0,72.13,37.9,94v87.53l87,50.24,87-50.24V81l-87,50.26L49.13,87.53,113.7,50.25l-11.24-6.48L37.9,81,22.47,72.13,124.94,13ZM49.14,100.49l75.8,43.84,75.79-43.84V175l-75.79,43.83L49.14,175Z"/>
        </svg>
        KUBO
      </span>

      <h1>Get hands-on with the KUBO School Platform.</h1>
      <p class="lead">
        @if ($classCount)
          A demo school with {{ $classCount }} classes and {{ $pupilCount }} pupils. Change anything you like.
        @else
          A demo school. Change anything you like.
        @endif
      </p>

      <div class="doors">
        @if ($first)
          <section>
            <h2>Run the school</h2>

            <form method="POST" action="{{ route('demo.login', $first) }}">
              @csrf
              @if ($sheet)
                {{-- The same table as Scorebook > Results, without the page around it. --}}
                <button type="submit" class="shot sheet" tabindex="-1" aria-hidden="true">
                  <span class="zoom">
                  <span class="block title">Results</span>
                  <span class="block period">{{ $shown->displayName() }} &middot; {{ $sheet['period'] }}</span>
                  <table>
                    <thead>
                      <tr>
                        <th>No.</th>
                        <th class="name">Name</th>
                        <th>Total</th>
                        <th>Average</th>
                        <th>Position</th>
                        @foreach ($sheet['subjects'] as $subject)<th>{{ ucfirst(strtolower($subject->name)) }}</th>@endforeach
                      </tr>
                    </thead>
                    <tbody>
                      @foreach ($sheet['rows'] as $i => $r)
                        <tr>
                          <td class="no">{{ $i + 1 }}</td>
                          <td class="name">{{ $r['student']->first_name }} {{ $r['student']->last_name }}</td>
                          <td class="strong">{{ (int) round($r['total']) }}</td>
                          <td>{{ $r['average'] }}</td>
                          <td class="strong">{{ $r['positionLabel'] }}</td>
                          @foreach ($sheet['subjects'] as $subject)
                            @php $mark = $r['marks'][$subject->id] ?? null; @endphp
                            <td>{{ $mark === null ? '' : (int) round($mark) }}</td>
                          @endforeach
                        </tr>
                      @endforeach
                    </tbody>
                  </table>
                  </span>
                </button>
              @endif
              <div class="row">
                <button type="submit" class="cta">Open as {{ strtolower($roles[$first]['label']) }} <span aria-hidden="true">&rarr;</span></button>
              </div>
            </form>

            <div class="others">
              <span>or as</span>
              @foreach ($people as $role => $person)
                @continue($role === $first || $role === 'student')
                <form method="POST" action="{{ route('demo.login', $role) }}">
                  @csrf
                  <button type="submit">{{ strtolower($roles[$role]['label']) }}</button>
                </form>
              @endforeach
            </div>
          </section>
        @endif

        {{-- A pupil isn't handed a session: they sign in the way children do, by
             picking their class and their own name. That flow is part of what
             we're showing, so this door walks into it. --}}
        <section>
          <h2>Learn in it</h2>

          @if ($pupils->isNotEmpty())
            <a href="{{ $pupilDoor }}" class="shot names" tabindex="-1" aria-hidden="true">
              <span class="zoom">
              <span class="block title">{{ $shown->displayName() }}</span>
              <span class="block hint">Find your name and type it to log in</span>
              <ul>
                @foreach ($pupils as $pupil)
                  <li>
                    <x-kid-avatar :seed="$pupil->id" :label="mb_strtoupper(mb_substr($pupil->first_name, 0, 1))" class="w-7 h-7 text-xs" />
                    {{ $pupil->first_name }} {{ $pupil->last_name }}
                  </li>
                @endforeach
              </ul>
              </span>
            </a>
          @endif
          <div class="row">
            <a href="{{ $pupilDoor }}" class="cta ghost">Sign in as a pupil <span aria-hidden="true">&rarr;</span></a>
          </div>
        </section>
      </div>

      {{-- The sign-in screens are part of the product too: the demo hands out roles,
           but a visitor should be able to find the door a real school uses. --}}
      <p class="foot">
        <span><a href="{{ route('login', ['real' => 1]) }}">Staff sign-in screen</a> (password: secret)</span>
        <a href="https://kubo.global">Back to kubo.global</a>
      </p>
    </div>
  </div>
</x-page>
