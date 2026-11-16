<x-page title="Results">
  @php
    $pp = ['offering' => $offering, 'term' => $period['term']?->id, 'month' => $period['month']['value'] ?? null];
    $cats = ['fail' => ['Fail', '#ef6b7d'], 'pass' => ['Pass', '#4caf50'], 'mastery' => ['Mastery', '#7aa0d0']];
    $overallColor = '#e8913c';
  @endphp

  <div class="px-6 py-6 lg:px-8" x-data="{ tab: 'sheet' }">

    <nav class="text-sm text-gray-500">
      <a href="{{ route('reporting.grades') }}" class="text-indigo-600 hover:underline">Scorebook</a>
      <span class="mx-1 text-gray-500">&rsaquo;</span>
      <a href="{{ route('reporting.grades', ['year' => $offering->schoolyear_id]) }}" class="text-indigo-600 hover:underline">{{ $offering->schoolyear->name ?? '' }}</a>
      <span class="mx-1 text-gray-500">&rsaquo;</span>
      <span class="text-gray-700">{{ $offering->grade->name ?? 'Class' }}</span>
    </nav>

    @php $tests = ($period['mode'] ?? 'months') === 'tests'; $byLabel = $tests ? 'By test' : 'By month'; @endphp
    {{-- View switch: by subject / by test|month (this results view) / by term (combined) --}}
    <div class="inline-flex p-0.5 mt-3 rounded-lg bg-gray-100">
      <a href="{{ route('scorebook.class', $offering) }}" class="px-3 py-1.5 text-sm rounded-md text-gray-600 hover:text-gray-900">By subject</a>
      <span class="px-3 py-1.5 text-sm font-semibold text-gray-900 bg-white rounded-md shadow-sm">{{ $byLabel }}</span>
      <a href="{{ route('term-grid.overview', ['offering' => $offering, 'term' => isset($period) ? $period['term']?->id : $term?->id]) }}" class="px-3 py-1.5 text-sm rounded-md text-gray-600 hover:text-gray-900">By term</a>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-3 mt-4 mb-5">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Results</h1>
        <p class="text-sm text-gray-500">{{ $offering->displayName() }} &middot; {{ $period['term']->name }} &middot; {{ $period['label'] }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        @if ($period['terms']->isNotEmpty())
          <form method="GET" action="{{ route('term-grid.report', $offering) }}" class="flex items-end gap-2">
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
        <a href="{{ route('term-grid.edit', array_merge($pp, ['edit' => 1])) }}"
          class="px-5 py-2.5 text-sm font-semibold text-white rounded-lg bg-indigo-600 hover:bg-indigo-700">Edit marks</a>
        <a href="{{ route('term-grid.bundle', $pp) }}"
          class="px-4 py-2.5 text-sm font-semibold rounded-lg text-indigo-700 bg-white ring-1 ring-indigo-200 hover:bg-indigo-50">Download all (PDF)</a>
        <a href="{{ route('term-grid.bundle', array_merge($pp, ['outline' => 1])) }}"
          class="px-4 py-2.5 text-sm font-medium rounded-lg text-gray-700 bg-white ring-1 ring-gray-200 hover:bg-gray-50">All (B&amp;W to colour in)</a>
        @unless (app()->environment('production'))
          <form method="POST" action="{{ route('term-grid.clear', $offering) }}"
            onsubmit="return confirm('Clear all saved marks for this test? (demo reset)')">
            @csrf
            <input type="hidden" name="term" value="{{ $period['term']?->id }}">
            <input type="hidden" name="month" value="{{ $period['month']['value'] ?? '' }}">
            <button type="submit" class="px-4 py-2.5 text-sm font-medium rounded-lg text-red-700 bg-red-50 ring-1 ring-red-200 hover:bg-red-100">Clear (demo)</button>
          </form>
        @endunless
      </div>
    </div>

    @if (! $hasMarks)
      <div class="p-10 mt-2 text-center bg-white border border-gray-200 rounded-lg">
        <p class="text-gray-600">No marks have been entered for {{ $period['month']['label'] ?? 'this month' }} yet.</p>
        <a href="{{ route('term-grid.edit', array_merge($pp, ['edit' => 1])) }}"
          class="inline-block px-5 py-2.5 mt-4 text-sm font-semibold text-white rounded-lg bg-indigo-600 hover:bg-indigo-700">Edit marks</a>
      </div>
    @else

    {{-- Tabs: tests-mode schools read analysis on the By-term view, where the /100 bands apply --}}
    <div class="border-b border-gray-200" @if ($tests) style="display:none" @endif>
      <nav class="flex gap-1 -mb-px">
        @foreach ($tests ? ['sheet' => 'Result sheet'] : ['sheet' => 'Result sheet', 'analysis' => 'Analysis', 'histogram' => 'Histogram'] as $key => $label)
          <button type="button" @click="tab = '{{ $key }}'"
            :class="tab === '{{ $key }}' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
            class="px-4 py-2.5 text-sm font-semibold border-b-2">{{ $label }}</button>
        @endforeach
      </nav>
    </div>

    {{-- ============ Result sheet ============ --}}
    <div x-show="tab === 'sheet'" class="mt-5">
      <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
        <p class="text-sm text-gray-700">Internal assessment result sheet for <b>{{ $offering->displayName() }}</b> &middot; {{ $period['term']->name }} {{ $period['label'] }} &middot; {{ $offering->schoolyear->name ?? '' }}.</p>
        <a href="{{ route('term-grid.result-sheet', $pp) }}" class="text-sm text-indigo-600 hover:underline whitespace-nowrap">Download this sheet (PDF)</a>
      </div>
      <div class="w-fit min-w-full bg-white border border-gray-200 rounded-lg shadow-sm">
        <table class="min-w-full text-sm border-collapse">
          <thead class="sticky top-0 z-20 bg-gray-50">
            <tr class="text-xs text-gray-500 uppercase bg-gray-50">
              {{-- Total/Ave/Pos sit next to the name: with a dozen subject columns
                   they would otherwise only be visible after scrolling all the way right. --}}
              <th class="px-2 py-2 border-b border-gray-200">No</th>
              <th class="px-3 py-2 text-left border-b border-gray-200">Name<span class="block mt-0.5 text-[10px] font-medium normal-case text-gray-400 whitespace-nowrap">{{ $offering->grade->name ?? '' }} &middot; {{ $period['term']?->name }} &middot; {{ $period['month']['label'] ?? '' }}</span></th>
              <th class="px-2 py-2 text-center border-b border-l border-gray-200">Total</th>
              <th class="px-2 py-2 text-center border-b border-gray-200">Ave</th>
              <th class="px-2 py-2 text-center border-b border-gray-200">Pos</th>
              @foreach ($displaySubjects ?? $subjects as $s)<th class="px-2 py-2 text-center border-b border-l border-gray-200">{{ $s->name }}</th>@endforeach
            </tr>
          </thead>
          <tbody>
            @foreach ($rows as $i => $r)
              <tr class="{{ $i % 2 ? 'bg-indigo-50/40' : 'bg-white' }}">
                <td class="px-2 py-1.5 text-center text-gray-500">{{ $i + 1 }}</td>
                <td class="px-3 py-1.5 font-medium text-gray-800 whitespace-nowrap">{{ $r['student']->first_name }} {{ $r['student']->last_name }}</td>
                <td class="px-2 py-1.5 font-bold text-center text-gray-900 border-l border-gray-100">{{ (int) round($r['total']) }}</td>
                <td class="px-2 py-1.5 text-center text-gray-700">{{ $r['average'] }}</td>
                <td class="px-2 py-1.5 font-semibold text-center text-gray-900">{{ $r['positionLabel'] }}</td>
                @foreach ($displaySubjects ?? $subjects as $s)
                  @php $m = $r['marks'][$s->id] ?? null; @endphp
                  @if (! array_key_exists($s->id, $r['marks']))
                    <td class="px-2 py-1.5 text-center border-l border-gray-100"></td>
                  @elseif ($m === null)
                    <td class="px-2 py-1.5 text-center text-gray-300 border-l border-gray-100">x</td>
                  @else
                    <td class="px-2 py-1.5 text-center border-l border-gray-100 {{ ($passMark !== null && $m < ($failBelow[$s->id] ?? $passMark)) ? 'text-red-600 font-semibold' : 'text-gray-800' }}">{{ (int) round($m) }}</td>
                  @endif
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p class="mt-2 text-xs text-gray-500">@if ($passMark !== null)<span class="text-red-600">Red</span> = fail (below {{ $passMark }}) &middot; @endif x = absent</p>
    </div>

    @unless ($tests)
      @include('pages.scorebook._analysis-tabs', [
        'analysisTitle' => $period['term']->name.' '.$period['label'],
        'pdfParams' => $pp,
      ])
    @endunless

    @endif

  </div>
</x-page>
