<x-page title="Term report">
  <div class="px-6 py-6 lg:px-8" x-data="{ tab: 'sheet' }">

    <nav class="text-sm text-gray-500">
      <a href="{{ route('reporting.grades') }}" class="text-indigo-600 hover:underline">Scorebook</a>
      <span class="mx-1 text-gray-500">&rsaquo;</span>
      <a href="{{ route('reporting.grades', ['year' => $offering->schoolyear_id]) }}" class="text-indigo-600 hover:underline">{{ $offering->schoolyear->name ?? '' }}</a>
      <span class="mx-1 text-gray-500">&rsaquo;</span>
      <span class="text-gray-700">{{ $offering->grade->name ?? 'Class' }}</span>
    </nav>

    @php $byLabel = (\App\Models\School::first()?->config(\App\Models\SchoolConfig::SCOREBOOK_PERIOD_MODE, 'months') === 'tests') ? 'By test' : 'By month'; @endphp
    {{-- View switch: by subject / by test|month / by term (this view — all rounds combined) --}}
    <div class="inline-flex p-0.5 mt-3 rounded-lg bg-gray-100">
      <a href="{{ route('scorebook.class', $offering) }}" class="px-3 py-1.5 text-sm rounded-md text-gray-600 hover:text-gray-900">By subject</a>
      <a href="{{ route('term-grid.report', ['offering' => $offering, 'term' => $term?->id]) }}" class="px-3 py-1.5 text-sm rounded-md text-gray-600 hover:text-gray-900">{{ $byLabel }}</a>
      <span class="px-3 py-1.5 text-sm font-semibold text-gray-900 bg-white rounded-md shadow-sm">By term</span>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-3 mt-4 mb-5">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Term report</h1>
        <p class="text-sm text-gray-500">{{ $offering->displayName() }}{{ $term ? ' · '.$term->name : '' }} · each subject combines Test 1, Test 2 and the Exam.</p>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        @if ($terms->isNotEmpty())
          <form method="GET" action="{{ route('term-grid.overview', $offering) }}" class="flex items-end gap-2">
            <div>
              <label class="block text-xs font-medium text-gray-500">Term</label>
              <select name="term" onchange="this.form.submit()"
                class="block px-3 py-2 mt-1 text-sm bg-white border border-gray-300 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                @foreach ($terms as $t)
                  <option value="{{ $t->id }}" @selected($term && $t->id === $term->id)>{{ $t->name }}</option>
                @endforeach
              </select>
            </div>
          </form>
        @endif
        @if ($term)
          <a href="{{ route('term-report.print-for-class', [$offering->schoolyear_id, $term->id, $offering->grade_id]) }}" target="_blank" rel="noopener"
            class="px-4 py-2.5 text-sm font-semibold rounded-lg text-indigo-700 bg-white ring-1 ring-indigo-200 hover:bg-indigo-50">Report cards (PDF)</a>
        @endif
      </div>
    </div>

    @if (! $term)
      <p class="text-sm text-gray-500">This class has no terms set up yet.</p>
    @elseif ($subjects->isEmpty())
      <p class="text-sm text-gray-500">No subjects are set up for this class. Add them in Settings.</p>
    @elseif ($rows->isEmpty())
      <p class="text-sm text-gray-500">No pupils are enrolled in this class yet.</p>
    @else
      @if ($analysis)
        <div class="border-b border-gray-200 mb-5">
          <nav class="flex gap-1 -mb-px">
            @foreach (['sheet' => 'Term marks', 'analysis' => 'Analysis', 'histogram' => 'Histogram'] as $key => $label)
              <button type="button" @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
                class="px-4 py-2.5 text-sm font-semibold border-b-2">{{ $label }}</button>
            @endforeach
          </nav>
        </div>
      @endif
      <div x-show="tab === 'sheet'">
      <div class="w-fit min-w-full bg-white border border-gray-200 rounded-lg shadow-sm">
        <table class="min-w-full text-sm border-collapse">
          <thead class="sticky top-0 z-20 bg-gray-50">
            <tr class="text-xs text-gray-500 uppercase bg-gray-50">
              <th class="px-2 py-2 border-b border-gray-200">No</th>
              <th class="px-3 py-2 text-left border-b border-gray-200">Name</th>
              @foreach ($subjects as $s)
                <th class="px-2 py-2 text-center border-b border-l border-gray-200">
                  {{ $s->name }}
                  @unless ($s->countsTowardTotalResolved())<span class="block text-[9px] font-normal normal-case text-amber-600">graded</span>@endunless
                </th>
              @endforeach
              <th class="px-2 py-2 text-center border-b border-l border-gray-200">Total</th>
              <th class="px-2 py-2 text-center border-b border-gray-200">Ave</th>
              <th class="px-2 py-2 text-center border-b border-gray-200">Pos</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($rows as $i => $r)
              <tr class="{{ $i % 2 ? 'bg-indigo-50/40' : 'bg-white' }}">
                <td class="px-2 py-1.5 text-center text-gray-500">{{ $i + 1 }}</td>
                <td class="px-3 py-1.5 font-medium whitespace-nowrap">
                  @if ($r['enrollment_id'])
                    <a href="{{ route('term-report.show', [$r['enrollment_id'], $term->id]) }}" class="text-indigo-600 hover:underline">{{ $r['student']->first_name }} {{ $r['student']->last_name }}</a>
                  @else
                    <span class="text-gray-800">{{ $r['student']->first_name }} {{ $r['student']->last_name }}</span>
                  @endif
                </td>
                @foreach ($subjects as $s)
                  @php $m = $r['marks'][$s->name] ?? null; @endphp
                  @if ($m === null)
                    <td class="px-2 py-1.5 text-center text-gray-300 border-l border-gray-100">x</td>
                  @elseif (! $s->countsTowardTotalResolved())
                    {{-- Graded: show the letter grade (from the marks entered), not the number. --}}
                    <td class="px-2 py-1.5 text-center border-l border-gray-100 text-gray-700">{{ \App\Models\GradingScale::resolve($school, (float) $m, $gradeNum)?->label ?? (int) round($m) }}</td>
                  @else
                    <td class="px-2 py-1.5 text-center border-l border-gray-100 {{ ($passMark !== null && $m < $passMark) ? 'text-red-600 font-semibold' : 'text-gray-800' }}">{{ (int) round($m) }}</td>
                  @endif
                @endforeach
                <td class="px-2 py-1.5 font-semibold text-center text-gray-900 border-l border-gray-100">{{ (int) round($r['total']) }}</td>
                <td class="px-2 py-1.5 text-center text-gray-700">{{ (int) round($r['average']) }}</td>
                <td class="px-2 py-1.5 text-center text-gray-700">{{ $r['position'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p class="mt-3 text-xs text-gray-500">Term mark per subject (Tests averaged at 25%, Exam 75%). <span class="text-amber-600">Graded</span> subjects show but are not added to the Total. Open <b>Report cards (PDF)</b> for the printable term reports.</p>
      </div>
      @if ($analysis)
        @include('pages.scorebook._analysis-tabs', [
          'analysisTitle' => ($term->name ?? 'Term').' (full term)',
          'pdfParams' => ['offering' => $offering, 'term' => $term->id, 'scope' => 'term'],
        ])
      @endif
    @endif
  </div>
</x-page>
