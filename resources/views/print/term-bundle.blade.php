<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 26px 26px; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .section { page-break-after: always; }
    .section.last { page-break-after: auto; }

    .header { text-align: center; border-bottom: 2px solid #3d5494; padding-bottom: 6px; margin-bottom: 10px; }
    .school { font-size: 16px; font-weight: bold; }
    .sub { font-size: 11px; color: #4b5563; }
    .sub b { color: #1f2937; }
    .intro { font-size: 11px; margin: 8px 0 12px; line-height: 1.4; }

    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th, table.grid td { border: 1px solid #cbd5e1; padding: 3px 5px; }
    table.grid thead th { background: #eef1f8; color: #34486f; font-size: 9.5px; text-align: center; vertical-align: bottom; }
    td.name { text-align: left; white-space: nowrap; }
    td.c, th.c { text-align: center; }
    td.subject { font-weight: bold; text-align: left; vertical-align: middle; background: #f7f8fb; }
    td.sex { text-align: left; }
    tr.stripe td { background: #f7f8fb; }
    tr.overall td { font-weight: bold; background: #f3f6fc; }
    .fail { color: #dc2626; font-weight: bold; }
    .absent { color: #9ca3af; }
    td.total, td.pos { font-weight: bold; }
    .foot { margin-top: 12px; font-size: 9.5px; color: #6b7280; }
    .foot .sig { margin-top: 16px; }

    table.box { width: 100%; border: 1.5px solid #111; border-collapse: collapse; margin-bottom: 12px; }
    table.box td { padding: 4px 8px; font-size: 10px; border: 1px solid #111; }
    td.yaxis { vertical-align: top; padding: 0 3px 0 0; }
    td.yaxis .tick { height: 37.1px; font-size: 9px; line-height: 1; text-align: right; color: #374151; }
    table.chart { border-collapse: collapse; }
    td.bc { vertical-align: bottom; height: 780px; padding: 0; }
    td.bc .bar { margin: 0 auto; }
    tr.base td { border-top: 1.2px solid #111; height: 0; line-height: 0; font-size: 0; padding: 0; }
    td.mfo { font-size: 8px; text-align: center; color: #374151; padding: 1px 0; }
    td.cat { font-size: 7.5px; font-weight: bold; text-align: center; border: 1px solid #333; padding: 2px 0; }
    td.subj { font-size: 9.5px; font-weight: bold; text-align: center; border: 1px solid #333; padding: 3px 0; }
    .legend { margin-top: 16px; font-size: 13px; }
    .legend .lt { font-weight: bold; font-size: 14px; margin-bottom: 6px; }
    .legend i { display: inline-block; width: 16px; height: 16px; margin-right: 6px; vertical-align: middle; }
    .legend span { margin-right: 26px; }

    @if ($outline)
    {{-- B&W variant: colour alone can't carry meaning on a mono printer, so
         fails switch to underline and every tint becomes a plain grey. --}}
    .header { border-bottom-color: #111; }
    body, .sub, .sub b { color: #111; }
    table.grid thead th { background: #e5e5e5; color: #111; }
    td.subject, tr.stripe td { background: #f0f0f0; }
    tr.overall td { background: #e5e5e5; }
    .fail { color: #111; text-decoration: underline; }
    .absent { color: #555; }
    .foot, td.yaxis .tick, td.mfo { color: #333; }
    @endif
  </style>
</head>
<body>

  {{-- ============ 1. Result sheet ============ --}}
  <div class="section">
    <div class="header">
      <div class="school">{{ $school->name ?? 'School' }}</div>
      <div class="sub">Internal Assessment Result Sheet</div>
      <div class="sub"><b>{{ $term->name }} &middot; {{ $periodTitle }}</b> &middot; <b>{{ $offering->displayName() }}</b> &middot; {{ $offering->schoolyear->name ?? '' }}</div>
    </div>
    <table class="grid">
      <thead>
        <tr>
          <th style="width:22px;">No</th>
          <th style="text-align:left;">Name</th>
          @foreach ($displaySubjects ?? $subjects as $s)<th class="c">{{ $s->name }}</th>@endforeach
          <th class="c">Total</th><th class="c">Ave</th><th class="c">Pos</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($rows as $i => $r)
          <tr class="{{ $i % 2 ? 'stripe' : '' }}">
            <td class="c">{{ $i + 1 }}</td>
            <td class="name">{{ $r['student']->first_name }} {{ $r['student']->last_name }}</td>
            @foreach ($displaySubjects ?? $subjects as $s)
              @php $m = $r['marks'][$s->id] ?? null; @endphp
              @if (! array_key_exists($s->id, $r['marks']))<td class="c"></td>
              @elseif ($m === null)<td class="c absent">x</td>
              @elseif ($passMark !== null && $m < ($failBelow[$s->id] ?? $passMark))<td class="c"><span class="fail">{{ (int) round($m) }}</span></td>
              @else<td class="c">{{ (int) round($m) }}</td>@endif
            @endforeach
            <td class="c total">{{ (int) round($r['total']) }}</td>
            <td class="c">{{ $r['average'] }}</td>
            <td class="c pos">{{ $r['positionLabel'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <div class="foot">
      @if ($passMark !== null)@if ($outline)<span class="fail">Underlined</span>@else<span style="color:#dc2626;">Red</span>@endif = fail (below {{ $passMark }}) &middot; @endif x = absent.
      <div class="sig">Class teacher: {{ $teacher ? $teacher->first_name.' '.$teacher->last_name : '__________________' }}
        &nbsp;&nbsp;&nbsp; Signature: __________________</div>
    </div>
  </div>

  {{-- ============ 2. Analysis ============ --}}
  <div class="section">
    <div class="header">
      <div class="school">{{ $school->name ?? 'School' }}</div>
      <div class="sub">Internal Assessment &middot; Result Analysis</div>
    </div>
    <div class="intro">
      Below is the result analysis of the <b>{{ $term->name }} {{ $periodTitle }}</b> for the
      <b>{{ $offering->displayName() }}</b> pupils in {{ $offering->schoolyear->name ?? '' }}.
    </div>
    <table class="grid">
      <thead>
        <tr>
          <th style="text-align:left;">Subject</th><th style="text-align:left;">Sex/Roll</th>
          <th>Number of<br>students</th><th>Number<br>sat</th>
          <th>Number<br>fail</th><th>%<br>fail</th>
          <th>Number<br>pass</th><th>%<br>pass</th>
          <th>Number<br>mastery</th><th>%<br>mastery</th><th>Average</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($analysis as $row)
          @foreach (['male' => 'Male', 'female' => 'Female', 'overall' => 'Overall'] as $key => $label)
            @php $g = $row[$key]; @endphp
            <tr class="{{ $key === 'overall' ? 'overall' : '' }}">
              @if ($key === 'male')<td class="subject" rowspan="3">{{ $row['subject']->name }}</td>@endif
              <td class="sex">{{ $label }}</td>
              <td class="c">{{ $g['students'] }}</td><td class="c">{{ $g['sat'] }}</td>
              <td class="c">{{ $g['fail'] }}</td><td class="c">{{ $g['failPct'] }}%</td>
              <td class="c">{{ $g['pass'] }}</td><td class="c">{{ $g['passPct'] }}%</td>
              <td class="c">{{ $g['mastery'] }}</td><td class="c">{{ $g['masteryPct'] }}%</td>
              <td class="c">{{ $g['average'] }}</td>
            </tr>
          @endforeach
        @endforeach
      </tbody>
    </table>
    <div class="foot">Fail below 40 &middot; Pass 40&ndash;79 &middot; Mastery 80 and above. Percentages are of the number who sat.</div>
  </div>

  {{-- ============ 3. Histogram (up to 4 subjects per page, else split) ============ --}}
  @php
    $cats = ['fail' => ['Fail', '#ef6b7d'], 'pass' => ['Pass', '#4caf50'], 'mastery' => ['Mastery', '#7aa0d0']];
    $overallColor = '#e8913c';
    $blank = $outline ?? false;
    $n = max(1, count($analysis));
    $pages = (int) ceil($n / 4);
    $perPage = (int) ceil($n / max(1, $pages));
    $bc = max(10, min(20, (int) round(700 / (10.97 * $perPage - 1.1))));
    $barW = max(6, $bc - 2);
    $gapW = max(3, (int) round($bc / 2.3));
    $sgapW = max(6, (int) round($bc * 1.1));
    $chunks = collect($analysis)->values()->chunk($perPage);
  @endphp

  <style>
    td.bc { width: {{ $bc }}px; }
    td.bc .bar { width: {{ $barW }}px; }
    td.gap { width: {{ $gapW }}px; }
    td.sgap { width: {{ $sgapW }}px; }
  </style>

  @foreach ($chunks as $ci => $chunk)
    @php $cols = count($chunk) * 11 + max(0, count($chunk) - 1); @endphp
    <div class="{{ $loop->last ? 'section last' : 'section' }}">
      <div class="intro">
        The below graph shows the result performance of the <b>{{ $offering->displayName() }}</b> pupils
        in the <b>{{ $term->name }} {{ $periodTitle }}</b> {{ $offering->schoolyear->name ?? '' }} academic year.@if ($pages > 1) <b>(part {{ $ci + 1 }} of {{ $pages }})</b>@endif
      </div>
      <table class="box">
        <tr>
          <td><b>GRADE:</b> {{ $offering->grade->name ?? '' }} {{ $offering->name }}</td>
          <td><b>ANALYSIS YEAR:</b> {{ $offering->schoolyear->name ?? '' }}</td>
        </tr>
        <tr>
          <td><b>NUMBER OF STUDENTS IN THE CLASS:</b> {{ $studentCount }}</td>
          <td><b>NUMBER OF STUDENTS SAT:</b> {{ $satCount }}</td>
        </tr>
      </table>

      <table style="margin: 0 auto; border-collapse: collapse;">
        <tr>
          <td class="yaxis">
            @for ($v = 100; $v >= 0; $v -= 5)<div class="tick">{{ $v }}</div>@endfor
          </td>
          <td style="vertical-align: top;">
            <table class="chart">
              <tr>
                @foreach ($chunk as $row)
                  @foreach ($cats as $catKey => $cat)
                    @foreach (['male', 'female', 'overall'] as $g)
                      @php $pct = $row[$g][$catKey.'Pct']; $c = $g === 'overall' ? $overallColor : $cat[1]; @endphp
                      <td class="bc"><div class="bar" style="height: {{ max(1, round($pct * 7.75)) }}px; {{ $blank ? 'border: 1.2px solid #333; background: #fff;' : 'background: '.$c.';' }}"></div></td>
                    @endforeach
                    @if (! $loop->last)<td class="gap"></td>@endif
                  @endforeach
                  @if (! $loop->last)<td class="sgap"></td>@endif
                @endforeach
              </tr>
              <tr class="base"><td colspan="{{ $cols }}"></td></tr>
              <tr>
                @foreach ($chunk as $row)
                  @foreach ($cats as $catKey => $cat)
                    <td class="mfo">M</td><td class="mfo">F</td><td class="mfo">O</td>
                    @if (! $loop->last)<td class="gap"></td>@endif
                  @endforeach
                  @if (! $loop->last)<td class="sgap"></td>@endif
                @endforeach
              </tr>
              <tr>
                @foreach ($chunk as $row)
                  @foreach ($cats as $catKey => $cat)
                    <td class="cat" colspan="3">{{ $cat[0] }}</td>
                    @if (! $loop->last)<td class="gap"></td>@endif
                  @endforeach
                  @if (! $loop->last)<td class="sgap"></td>@endif
                @endforeach
              </tr>
              <tr>
                @foreach ($chunk as $row)
                  <td class="subj" colspan="11">{{ strtoupper($row['subject']->name) }}</td>
                  @if (! $loop->last)<td class="sgap"></td>@endif
                @endforeach
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <div class="legend">
        <div class="lt">Key features @if ($blank)<span style="font-weight: normal; color: #6b7280;">(colour each bar to match its group)</span>@endif</div>
        <span><i style="{{ $blank ? 'border: 1px solid #333; background: #fff;' : 'background: #4caf50;' }}"></i>Pass</span>
        <span><i style="{{ $blank ? 'border: 1px solid #333; background: #fff;' : 'background: #ef6b7d;' }}"></i>Fail</span>
        <span><i style="{{ $blank ? 'border: 1px solid #333; background: #fff;' : 'background: #7aa0d0;' }}"></i>Mastery</span>
        <span><i style="{{ $blank ? 'border: 1px solid #333; background: #fff;' : 'background: #e8913c;' }}"></i>Overall</span>
        <div style="margin-top: 5px; font-size: 11px; color: #6b7280;">bars are % of pupils &middot; M = male, F = female, O = overall</div>
      </div>
    </div>
  @endforeach

</body>
</html>
