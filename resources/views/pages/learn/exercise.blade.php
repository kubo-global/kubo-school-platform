<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $skill->name }} | KUBO</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

        .kubo-bar {
            display: flex; align-items: center; justify-content: space-between;
            height: 48px; padding: 0 1rem;
            background: #fff; border-bottom: 1px solid #e5e7eb;
            z-index: 100; position: relative;
        }
        .kubo-bar a { text-decoration: none; font-size: 0.875rem; color: #6b7280; }
        .kubo-bar a:hover { color: #111827; }
        .kubo-bar .title { font-weight: 600; color: #111827; font-size: 0.9375rem; }
        .kubo-bar .done-btn {
            display: inline-block; padding: 0.375rem 1rem;
            background: #22c55e; color: #fff; text-decoration: none;
            border-radius: 0.375rem; font-weight: 600; font-size: 0.875rem;
            border: none; cursor: pointer;
        }
        .kubo-bar .done-btn:hover { background: #16a34a; }
        .kubo-bar .done-btn.hidden { display: none; }
        .kubo-bar .done-btn.pulse { animation: pulse-btn 2s ease-in-out infinite; }
        @keyframes pulse-btn { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.08); box-shadow: 0 0 12px rgba(34,197,94,0.5); } }

        .lesson-badge {
            display: inline-block; padding: 0.125rem 0.5rem;
            background: #6366f1; color: #fff; border-radius: 999px;
            font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .exercise-wrap {
            position: relative; height: calc(100% - 48px); overflow: hidden;
        }
        .exercise-wrap iframe {
            position: absolute; top: -48px; left: 0;
            width: 100%; height: calc(100% + 48px); border: none;
        }

        .loading {
            position: absolute; inset: 0; z-index: 50;
            display: flex; align-items: center; justify-content: center;
            background: #fff;
        }
        .loading.hidden { display: none; }
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid #e5e7eb; border-top-color: #2563eb;
            border-radius: 50%; animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="kubo-bar">
        <a href="{{ route('learn.index') }}">&larr; Back</a>
        @if($mode === 'homework')
        <span class="lesson-badge">Lesson</span>
        @elseif($mode === 'review')
        <span class="lesson-badge" style="background: #f97316">Review</span>
        @else
        <span class="title">{{ $skill->name }}</span>
        @endif
        <button type="button" class="done-btn @if($isReattempt) pulse @endif" id="done-btn">Done</button>
    </div>

    @if($isReattempt)
    <div style="background:#f0fdf4; border-bottom:1px solid #bbf7d0; padding:0.5rem 1rem; text-align:center; font-size:0.8125rem; color:#15803d;">
        Extra practice &mdash; press <strong>Done</strong> when finished
    </div>
    @endif

    <div class="exercise-wrap" @if($isReattempt) style="height: calc(100% - 48px - 33px)" @endif>
        <div class="loading" id="loading">
            <div>
                <div class="spinner" style="margin: 0 auto"></div>
                <p style="margin-top:1rem; font-size:0.875rem; color:#6b7280">Loading your exercise...</p>
            </div>
        </div>
        <div id="load-error" style="display:none; position:absolute; inset:0; z-index:50; background:#fff">
            <div style="display:flex; align-items:center; justify-content:center; height:100%">
                <div style="text-align:center; padding:2rem; max-width:20rem">
                    <div style="width:3rem; height:3rem; margin:0 auto 1rem; border-radius:50%; background:#fef3c7; display:flex; align-items:center; justify-content:center">
                        <svg width="24" height="24" fill="none" stroke="#d97706" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/>
                        </svg>
                    </div>
                    <p style="font-weight:600; color:#111827; margin-bottom:0.5rem">Could not load exercise</p>
                    @if($ssoReady)
                    <p style="font-size:0.875rem; color:#6b7280; margin-bottom:1.5rem">The exercise server is not reachable. Check that the server is running and try again.</p>
                    @else
                    <p style="font-size:0.875rem; color:#6b7280; margin-bottom:1.5rem">You could not be signed in to the exercise server. Try again in a minute; if it keeps happening, tell your teacher.</p>
                    @endif
                    <button onclick="location.reload()" style="padding:0.5rem 1.5rem; background:#2563eb; color:#fff; border:none; border-radius:0.375rem; font-weight:600; font-size:0.875rem; cursor:pointer">Try again</button>
                </div>
            </div>
        </div>
        <iframe
            id="kolibri-frame"
            allow="autoplay; fullscreen"
            onload="document.getElementById('loading').classList.add('hidden')"
        ></iframe>
    </div>

    <form id="complete-form" method="POST" action="{{ route('learn.completeRun', $skill) }}" style="display:none">
        @csrf
        <input type="hidden" name="run_id" value="{{ $run->id }}">
    </form>

    <script>
        var completing = false;
        var doneBtn = document.getElementById('done-btn');
        var frame = document.getElementById('kolibri-frame');

        function submitComplete() {
            if (completing) return;
            completing = true;
            document.getElementById('complete-form').submit();
        }

        // "Done" button — confirm before ending the exercise
        doneBtn.addEventListener('click', function() {
            if (confirm('Are you finished with this exercise?')) {
                submitComplete();
            }
        });

        // Pulse the Done button after 30s to hint the student can finish
        setTimeout(function() {
            if (!completing) doneBtn.classList.add('pulse');
        }, 30000);

        // Hide previous session's attempt checkmarks.
        // Polls until Kolibri renders them, hides non-placeholder items, then stops.
        var attemptsCleaned = false;
        var attemptsInterval = setInterval(function() {
            if (attemptsCleaned) { clearInterval(attemptsInterval); return; }
            try {
                var doc = frame.contentDocument;
                if (!doc) return;
                var items = doc.querySelectorAll('.exercise-attempts > :not(.placeholder)');
                if (items.length > 0) {
                    for (var i = 0; i < items.length; i++) items[i].style.display = 'none';
                    attemptsCleaned = true;
                }
            } catch(e) {}
        }, 200);
        // Stop trying after 10 seconds (no old attempts to hide)
        setTimeout(function() { attemptsCleaned = true; }, 10000);

        // Poll iframe for completion text. Kolibri's SES lockdown prevents
        // XHR/fetch interception, so DOM polling is the only reliable method.
        // Transition-based: only fires when going from practicing → completed.
        var sawPracticing = false;
        setInterval(function() {
            if (completing) return;
            try {
                var doc = frame.contentDocument;
                if (!doc || !doc.body) return;
                var text = doc.body.innerText || '';

                var isComplete = text.indexOf('Stay and practice') !== -1 ||
                    text.indexOf('Resource completed') !== -1;

                if (!isComplete && text.length > 100) {
                    sawPracticing = true;
                }

                if (isComplete && sawPracticing) {
                    submitComplete();
                }
            } catch(e) {}
        }, 500);

        // Load the exercise. KUBO established the learner's Kolibri session
        // server-side (the session cookie is already set on this page, scoped to
        // the proxy path), so we load the content directly — the learner's
        // password never reaches the browser.
        //
        // If that sign-in failed, do not load the frame: an anonymous Kolibri
        // session cannot track an exercise (its first progress call answers 500)
        // and the frame stays blank behind the spinner, which reads as "Kolibri
        // is down" while Kolibri is fine. Say what happened instead; "Try again"
        // reloads, and the page provisions and signs in again on the way.
        (function() {
            var contentUrl = @json($contentUrl);
            var ssoReady = @json($ssoReady);

            function loadExercise() {
                frame.src = contentUrl;
            }

            function showLoadError() {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('load-error').style.display = 'block';
            }

            if (!ssoReady) {
                showLoadError();
                return;
            }

            // Timeout: if the exercise doesn't load within 15s, show error.
            var loadTimeout = setTimeout(function() {
                if (!frame.src) showLoadError();
            }, 15000);

            frame.addEventListener('load', function() { clearTimeout(loadTimeout); });

            loadExercise();
        })();

        // Inject CSS into iframe to hide Kolibri chrome
        frame.addEventListener('load', function() {
            try {
                var doc = this.contentDocument;
                if (!doc) return;
                var style = doc.createElement('style');
                style.textContent = '[class*="MasteryModel"],[class*="PointsPopover"]{display:none!important}.overall-status{display:none!important}';
                doc.head.appendChild(style);

                // Hide previous session's attempt indicators via CSS immediately,
                // then reveal the overall-status once the student starts answering.
                var style2 = doc.createElement('style');
                style2.textContent = '.overall-status{display:none!important}';
                doc.head.appendChild(style2);
            } catch(e) {}
        });
    </script>
</body>
</html>
