<x-page title="Term marks">
  <div class="px-6 py-6 lg:px-8">

    <nav class="text-sm text-gray-500">
      <a href="{{ route('reporting.grades') }}" class="text-indigo-600 hover:underline">Scorebook</a>
      <span class="mx-1 text-gray-500">&rsaquo;</span>
      <a href="{{ route('reporting.grades', ['year' => $offering->schoolyear_id]) }}" class="text-indigo-600 hover:underline">{{ $offering->schoolyear->name ?? '' }}</a>
      <span class="mx-1 text-gray-500">&rsaquo;</span>
      <span class="text-gray-700">{{ $offering->grade->name ?? 'Class' }}</span>
    </nav>

    @php $tests = ($period['mode'] ?? 'months') === 'tests'; $byLabel = $tests ? 'By test' : 'By month'; @endphp
    {{-- View switch: by subject / by test|month (this grid) / by term (combined).
         Hidden while entering marks: the what-to-enter choice was already made,
         and navigating away mid-entry would silently drop unsaved marks. --}}
    @unless (request('edit'))
    <div class="inline-flex p-0.5 mt-3 rounded-lg bg-gray-100">
      <a href="{{ route('scorebook.class', $offering) }}" class="px-3 py-1.5 text-sm rounded-md text-gray-600 hover:text-gray-900">By subject</a>
      <span class="px-3 py-1.5 text-sm font-semibold text-gray-900 bg-white rounded-md shadow-sm">{{ $byLabel }}</span>
      <a href="{{ route('term-grid.overview', ['offering' => $offering, 'term' => isset($period) ? $period['term']?->id : $term?->id]) }}" class="px-3 py-1.5 text-sm rounded-md text-gray-600 hover:text-gray-900">By term</a>
    </div>
    @endunless

    <div class="flex flex-wrap items-end justify-between gap-3 mt-4 mb-5">
      <div>
        <h1 class="text-xl font-bold text-gray-900">{{ request('edit') ? 'Enter marks' : 'Marks' }}</h1>
        <p class="text-sm text-gray-500">
          {{ request('edit')
            ? 'Type each mark, then save. Tap abs to mark a pupil absent for that subject.'
            : 'Test 1, Test 2 or the Exam. Edit to change the marks, or print the result sheet, analysis and histogram.' }}
        </p>
      </div>

      @if (request('edit'))
        {{-- The choice was made before entering; switching here would drop unsaved
             marks. A static badge + a way back to change it safely. --}}
        <div class="flex items-center gap-2 text-sm">
          <span class="px-3 py-1.5 font-semibold text-gray-900 bg-gray-100 rounded-lg">{{ $period['term']?->name }} · {{ $period['month']['label'] ?? '' }}</span>
          <a href="{{ route('term-grid.report', ['offering' => $offering, 'term' => $period['term']?->id, 'month' => $period['month']['value'] ?? null]) }}"
            class="text-indigo-600 hover:underline">Change</a>
        </div>
      @elseif ($period['terms']->isNotEmpty())
        <form method="GET" action="{{ route('term-grid.edit', $offering) }}" class="flex items-end gap-2">
          @if ($showGraded ?? false)<input type="hidden" name="graded" value="1">@endif
          <div>
            <label class="block text-xs font-medium text-gray-500">Term</label>
            <select name="term" onchange="this.form.submit()"
              class="block px-3 py-2 mt-1 text-sm bg-white border border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
              @foreach ($period['terms'] as $t)
                <option value="{{ $t->id }}" @selected($period['term'] && $t->id === $period['term']->id)>{{ $t->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500">{{ $tests ? 'Test' : 'Month' }}</label>
            <select name="month" onchange="this.form.submit()"
              class="block px-3 py-2 mt-1 text-sm bg-white border border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
              @foreach ($period['months'] as $mo)
                <option value="{{ $mo['value'] }}" @selected($period['month'] && $mo['value'] === $period['month']['value'])>{{ $mo['label'] }}</option>
              @endforeach
            </select>
          </div>
        </form>
      @endif
    </div>

    @php $pp = ['offering' => $offering, 'term' => $period['term']?->id, 'month' => $period['month']['value'] ?? null]; @endphp

    @if (! $period['term'])
      <p class="text-sm text-gray-500">This class has no terms set up yet.</p>
    @elseif ($students->isEmpty())
      <p class="text-sm text-gray-500">No pupils are enrolled in this class yet.</p>
    @elseif ($subjects->isEmpty())
      <p class="text-sm text-gray-500">No subjects are set up for this class this term. Add them in Settings.</p>
    @elseif (request('edit'))
      {{-- Edit mode: the editable grid --}}
      {{-- Marks autosave a moment after each change; the button remains as the
           explicit finish (and the only path without JavaScript). --}}
      <form method="POST" action="{{ route('term-grid.save', $offering) }}"
        x-data="{
          saving: false, savedAt: null, dirty: false,
          async autosave() {
            if (! this.dirty || this.saving) return;
            this.dirty = false; this.saving = true;
            try {
              const res = await fetch(this.$el.action, {
                method: 'POST', body: new FormData(this.$el),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
              });
              if (res.ok) this.savedAt = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } finally { this.saving = false; if (this.dirty) this.autosave(); }
          },
          nav(e) {
            const el = e.target;
            if (! el.matches('input[data-r]')) return;
            const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1], Enter: [1, 0] };
            const step = moves[e.key];
            if (! step) return;
            e.preventDefault();
            let r = parseInt(el.dataset.r) + step[0], c = parseInt(el.dataset.c) + step[1];
            // skip gaps: locked/graded cells (no input) and absent cells (disabled
            // input, cannot be typed into) are stepped over, up to the grid edge.
            for (let i = 0; i < 40; i++) {
              const next = this.$el.querySelector(`input[data-r='${r}'][data-c='${c}']`);
              if (next && ! next.disabled) { next.focus(); next.select(); next.scrollIntoView({ block: 'nearest' }); return; }
              r += step[0]; c += step[1];
              if (r < 0 || c < 0) return;
            }
          },
        }"
        @keydown="nav($event)"
        @input="dirty = true" @input.debounce.2000ms="autosave()">
        @csrf
        <input type="hidden" name="term" value="{{ $period['term']->id }}">
        <input type="hidden" name="month" value="{{ $period['month']['value'] ?? '' }}">

        @php $editableSubjects = $editableSubjects ?? null; $restricted = is_array($editableSubjects); @endphp
        <div class="flex flex-wrap items-center gap-3 mb-4">
          <span class="text-sm text-gray-600">Entering marks for
            <span class="font-semibold text-gray-900">{{ $period['month']['label'] ?? '' }}</span>.
          </span>
          @if (($gradedHidden ?? 0) > 0)
            <a href="{{ route('term-grid.edit', $pp + ['edit' => 1, 'graded' => 1]) }}"
              class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
              Show {{ $gradedHidden }} graded subject{{ $gradedHidden === 1 ? '' : 's' }}
              <span class="font-normal text-gray-500">(letter only, by hand)</span>
            </a>
          @elseif ($showGraded ?? false)
            <a href="{{ route('term-grid.edit', $pp + ['edit' => 1]) }}"
              class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14"/></svg>
              Hide graded subjects
            </a>
          @endif
          @if (empty($period['month']['type']))
            {{-- Public schools: each month is a Test or an Exam the teacher picks. --}}
            <div class="inline-flex overflow-hidden border border-gray-300 rounded-lg">
              <label>
                <input type="radio" name="type" value="Test" class="sr-only peer/test" @checked($period['typeName'] === 'Test')>
                <span class="block px-5 py-2 text-sm font-semibold cursor-pointer select-none text-gray-600 hover:bg-gray-50 peer-checked/test:bg-indigo-600 peer-checked/test:text-white">Test</span>
              </label>
              <label class="border-l border-gray-300">
                <input type="radio" name="type" value="Exam" class="sr-only peer/exam" @checked($period['typeName'] === 'Exam')>
                <span class="block px-5 py-2 text-sm font-semibold cursor-pointer select-none text-gray-600 hover:bg-gray-50 peer-checked/exam:bg-indigo-600 peer-checked/exam:text-white">Exam</span>
              </label>
            </div>
          @endif
          @if ($restricted)
            <span class="text-xs text-gray-500">You can edit: <b>{{ $subjects->whereIn('id', $editableSubjects)->pluck('name')->join(', ') ?: 'none of these subjects' }}</b>. Other columns are view-only.</span>
          @endif
          {{-- The public demo runs APP_ENV=production, so gate on demo mode too. --}}
          @if (config('app.demo') || ! app()->environment('production'))
            <button type="button" onclick="fillExampleMarks()"
              class="px-3 py-1.5 ml-auto text-sm font-medium rounded-lg text-amber-800 bg-amber-50 ring-1 ring-amber-200 hover:bg-amber-100">Fill example marks (demo)</button>
          @endif
        </div>

        <div class="w-fit min-w-full bg-white border border-gray-200 rounded-lg shadow-sm">
          <table class="text-sm border-collapse table-fixed" style="width: 100%; min-width: {{ 200 + $subjects->count() * 120 }}px;">
            <thead class="sticky top-0 z-20 bg-gray-50">
              <tr class="bg-gray-50">
                <th class="sticky left-0 z-30 px-3 py-2 text-xs font-semibold text-left text-gray-500 uppercase bg-gray-50 border-b border-gray-200" style="width: 200px;">Pupil<span class="block mt-0.5 text-[10px] font-medium normal-case text-gray-400 whitespace-nowrap">{{ $offering->grade->name ?? '' }} &middot; {{ $period['term']?->name }} &middot; {{ $period['month']['label'] ?? '' }}</span></th>
                <th class="px-2 py-2 text-xs font-semibold text-center text-gray-500 uppercase border-b border-l border-gray-200" style="width: 60px;" title="The pupil's term total so far (saved marks, counting subjects)">Total</th>
                @foreach ($subjects as $s)
                  @php
                    $locked = $restricted && ! in_array($s->id, $editableSubjects);
                    $meta = $columnMeta[$s->id] ?? null;
                    $colMax = $meta['max'] ?? ($defaultMax ?? 100);
                    // A column whose assessment already carries scores under ANOTHER type
                    // is pinned there; save() refuses to rewrite it. Only flag the
                    // mismatch — on the matching period the label is just noise.
                    $pinnedType = ($meta && ($meta['locked_type'] ?? false) && $meta['type'] !== ($period['typeName'] ?? null))
                        ? $meta['type'] : null;
                  @endphp
                  <th class="group px-3 py-2.5 text-xs font-semibold text-center border-b border-l border-gray-200 {{ $locked ? 'text-gray-400 bg-gray-100' : (! $meta ? 'text-gray-400' : 'text-gray-600') }}" style="min-width: 148px;">
                    {{ $s->name }}
                    @if (! $meta && ! $locked)
                      {{-- No assessment yet for this period: announce the default max, allow changing it before first save. --}}
                      @if ($tests)
                      {{-- The Swallow's scales are fixed: tests /25, exams /75 — no per-column max. --}}
                      <span class="block mt-0.5 text-[11px] font-normal normal-case text-gray-500">
                        <span class="inline-block px-1.5 py-0.5 rounded bg-gray-200/80 text-gray-600 whitespace-nowrap">not started</span>
                        <span class="inline-block whitespace-nowrap">out of {{ $defaultMax }}</span>
                      </span>
                      @else
                      <span class="block mt-0.5 text-[11px] font-normal normal-case text-gray-500" x-data="{ editMax: false }">
                        <template x-if="! editMax">
                          <span>
                            <span class="inline-block px-1.5 py-0.5 rounded bg-gray-200/80 text-gray-600 whitespace-nowrap">not started</span>
                            <span class="inline-block whitespace-nowrap">out of {{ $defaultMax }}
                              <button type="button" @click="editMax = true" class="ml-0.5 underline text-indigo-500 hover:text-indigo-700">change</button>
                            </span>
                          </span>
                        </template>
                        <template x-if="editMax">
                          <span>out of <input type="number" name="max[{{ $s->id }}]" value="{{ $defaultMax }}" min="1" max="100"
                            class="w-14 px-1 py-0.5 text-center border border-gray-300 rounded"></span>
                        </template>
                      </span>
                      @endif
                    @elseif ($tests)
                      <span class="block mt-0.5 text-[11px] font-normal normal-case text-gray-500">out of {{ $colMax }}</span>
                    @elseif (! $locked)
                      <span class="block mt-0.5 text-[11px] font-normal normal-case text-gray-500" x-data="{ editMax: false }">
                        <template x-if="! editMax">
                          <span class="inline-block whitespace-nowrap">out of {{ $colMax }}
                            <button type="button" @click="editMax = true"
                              class="ml-0.5 text-gray-400 underline decoration-dotted underline-offset-2 hover:text-indigo-600">change</button>
                          </span>
                        </template>
                        <template x-if="editMax">
                          <span>out of <input type="number" name="max[{{ $s->id }}]" value="{{ $colMax }}" min="1" max="100"
                            class="w-14 px-1 py-0.5 text-center border border-gray-300 rounded"></span>
                        </template>
                      </span>
                    @elseif ($colMax !== 100)<span class="block mt-0.5 text-[11px] font-normal normal-case text-gray-500">out of {{ $colMax }}</span>@endif
                    @if ($pinnedType)<span class="block text-[9px] font-normal normal-case text-amber-600">saved as {{ $pinnedType }}</span>@endif
                    @if ($locked)<span class="block text-[9px] font-normal normal-case text-gray-400">view only</span>@endif
                  </th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @foreach ($students as $i => $st)
                <tr class="{{ $i % 2 ? 'bg-indigo-50/60' : 'bg-white' }}">
                  <td class="sticky left-0 z-10 px-3 py-2 font-medium text-gray-800 whitespace-nowrap {{ $i % 2 ? 'bg-indigo-50' : 'bg-white' }}">
                    {{ $st->first_name }} {{ $st->last_name }}
                  </td>
                  <td class="px-3 py-2.5 text-center text-gray-500 border-l border-gray-100">{{ isset($termTotals[$st->id]) ? (int) round($termTotals[$st->id]['total']) : '—' }}</td>
                  @foreach ($subjects as $s)
                    @php
                      $entry = $existing[$s->id][$st->id] ?? null;
                      $isAbsent = ($entry === '');
                      $val = is_numeric($entry) ? $entry : '';
                      $locked = $restricted && ! in_array($s->id, $editableSubjects);
                    @endphp
                    <td class="px-3 py-3 border-l border-gray-100 {{ $locked ? 'bg-gray-50' : (! isset($columnMeta[$s->id]) ? 'bg-gray-100/80' : '') }}" x-data="{ absent: {{ $isAbsent ? 'true' : 'false' }} }">
                      @if ($locked)
                        {{-- Not this teacher's subject: show the mark read-only, no inputs submitted. --}}
                        <div class="text-center text-gray-400">{{ $isAbsent ? 'abs' : ($val === '' ? '—' : $val) }}</div>
                      @else
                      <div class="flex items-center justify-center gap-2">
                        <input type="number" inputmode="numeric" min="0" max="{{ ($columnMeta[$s->id]['max'] ?? ($defaultMax ?? 100)) }}"
                          name="scores[{{ $s->id }}][{{ $st->id }}]" value="{{ $val }}"
                          data-r="{{ $i }}" data-c="{{ $loop->index }}" @focus="$event.target.select()"
                          x-bind:disabled="absent" :class="absent && 'opacity-40 bg-gray-100'"
                          aria-label="{{ $st->first_name }} {{ $st->last_name }} — {{ $s->name }}"
                          class="w-16 px-2 py-2.5 sm:py-1.5 text-center text-gray-900 border rounded-md scroll-mt-24 scroll-mb-24 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 {{ ! isset($columnMeta[$s->id]) ? 'border-dashed border-gray-400 bg-white/60' : 'border-gray-300' }}">
                        <input type="hidden" name="absent[{{ $s->id }}][{{ $st->id }}]" :value="absent ? 1 : 0">
                        <button type="button" @click="absent = !absent; $dispatch('input')" title="Mark {{ $st->first_name }} absent for {{ $s->name }}"
                          :class="absent ? 'bg-gray-400 text-white' : 'text-gray-400 hover:bg-gray-100 ring-1 ring-gray-200'"
                          class="px-3 py-2.5 text-[11px] sm:px-2 sm:py-1.5 sm:text-[10px] font-semibold uppercase rounded-md shrink-0">abs</button>
                      </div>
                      @endif
                    </td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        {{-- Floating action pill: fixed to the bottom-right of the viewport, always
             in reach; the spacer keeps it clear of the table's last rows. --}}
        <div class="h-20"></div>
        <div class="fixed inset-x-0 bottom-0 z-40 flex items-center justify-end gap-4 px-6 py-3 bg-white/95 backdrop-blur border-t border-gray-200">
          <span class="text-sm text-gray-500" aria-live="polite">
            <span x-show="saving">Saving&hellip;</span>
            <span x-show="! saving && savedAt" x-cloak>Saved &check; <span x-text="savedAt"></span></span>
          </span>
          <button type="submit" title="Marks save automatically as you type; Done checks for warnings and returns to the results."
            class="px-5 py-2.5 text-sm font-semibold text-white rounded-lg bg-indigo-600 hover:bg-indigo-700">Done</button>
        </div>
      </form>
    @else
      {{-- View mode: read-only marks; editing is a deliberate step --}}
      @if ($period['existingType'])
        <p class="mb-3 text-sm text-gray-600">Showing the <span class="font-semibold">{{ $period['month']['label'] ?? '' }} {{ $period['typeName'] }}</span>.</p>
      @else
        <p class="mb-3 text-sm text-gray-500">No marks entered for {{ $period['month']['label'] ?? 'this month' }} yet &mdash; click <span class="font-medium">Edit marks</span> to add them, choosing test or exam.</p>
      @endif
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="text-xs text-gray-500">
          {{ $students->count() }} pupils · {{ $subjects->count() }} subjects
          <div class="mt-1">
            Or download a single sheet:
            <a href="{{ route('term-grid.result-sheet', $pp) }}" class="text-indigo-600 hover:underline">Result sheet</a> ·
            <a href="{{ route('term-grid.analysis', $pp) }}" class="text-indigo-600 hover:underline">Analysis</a> ·
            <a href="{{ route('term-grid.histogram', $pp) }}" class="text-indigo-600 hover:underline">Histogram</a>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <a href="{{ route('term-grid.report', $pp) }}"
            class="px-4 py-2.5 text-sm font-semibold rounded-lg text-indigo-700 bg-white ring-1 ring-indigo-200 hover:bg-indigo-50">View results</a>
          <a href="{{ route('term-grid.edit', array_merge($pp, ['edit' => 1])) }}"
            class="px-5 py-2.5 text-sm font-semibold text-white rounded-lg bg-indigo-600 hover:bg-indigo-700">Edit marks</a>
        </div>
      </div>

      <div class="w-fit min-w-full bg-white border border-gray-200 rounded-lg shadow-sm">
        <table class="min-w-full text-sm border-collapse">
          <thead class="sticky top-0 z-20 bg-gray-50">
            <tr class="bg-gray-50">
              <th class="sticky left-0 z-30 px-3 py-2 text-xs font-semibold text-left text-gray-500 uppercase bg-gray-50 border-b border-gray-200">Pupil<span class="block mt-0.5 text-[10px] font-medium normal-case text-gray-400 whitespace-nowrap">{{ $offering->grade->name ?? '' }} &middot; {{ $period['term']?->name }} &middot; {{ $period['month']['label'] ?? '' }}</span></th>
              @foreach ($subjects as $s)
                <th class="px-3 py-2 text-xs font-semibold text-center text-gray-600 border-b border-l border-gray-200" style="min-width: 148px;">{{ $s->name }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach ($students as $i => $st)
              <tr class="{{ $i % 2 ? 'bg-indigo-50/60' : 'bg-white' }}">
                <td class="sticky left-0 z-10 px-3 py-2 font-medium text-gray-800 whitespace-nowrap {{ $i % 2 ? 'bg-indigo-50' : 'bg-white' }}">
                  {{ $st->first_name }} {{ $st->last_name }}
                </td>
                @foreach ($subjects as $s)
                  @php $v = $existing[$s->id][$st->id] ?? null; @endphp
                  <td class="px-3 py-2 text-center border-l border-gray-100 {{ (is_numeric($v) && $v < 40 && ($period['mode'] ?? 'months') !== 'tests') ? 'text-red-600 font-semibold' : 'text-gray-800' }}">
                    @if ($v === '')<span class="text-gray-400">abs</span>@elseif ($v === null)<span class="text-gray-300">&ndash;</span>@else{{ $v }}@endif
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

    @endif

  </div>

  <script>
    // A mouse-wheel over a focused number cell must not change the mark: blur so the page scrolls instead.
    document.querySelectorAll('input[type=number]').forEach(function (el) {
      el.addEventListener('wheel', function () { this.blur(); }, { passive: true });
    });

    // Warn before leaving the edit grid with unsaved marks.
    (function () {
      var form = document.querySelector('form[method="POST"]');
      if (!form) return;
      var dirty = false;
      var markDirty = function () { dirty = true; };
      form.addEventListener('input', markDirty);
      form.addEventListener('change', markDirty);
      form.addEventListener('submit', function () { dirty = false; }); // saving: allow navigation
      window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
      });
    })();

    // Demo helper: fill every non-absent cell with a plausible spread of marks
    // (fails, passes and a few mastery) so the results are worth showing. The
    // pool is in percent and each mark is scaled to its own column's maximum
    // (25 for a test, 75 for an exam, or what the teacher set), so the example
    // never exceeds what the column accepts. Only mark cells are filled, never
    // the "out of" fields.
    function fillExampleMarks() {
      var pool = [92, 85, 78, 70, 66, 60, 55, 50, 45, 40, 35, 28, 63, 48, 73, 58, 22, 88];
      var form = document.querySelector('form[method="POST"]');
      form.querySelectorAll('input[type=number][name^="scores["]').forEach(function (el, i) {
        if (el.disabled) return;
        var subject = el.name.slice(7, el.name.indexOf(']'));
        var typed = form.querySelector('input[name="max[' + subject + ']"]');
        var max = Number(typed && typed.value) || Number(el.max) || 100;
        el.value = Math.round(pool[(i * 5 + 3) % pool.length] / 100 * max);
        el.dispatchEvent(new Event('input', { bubbles: true }));
      });
    }
  </script>
</x-page>
