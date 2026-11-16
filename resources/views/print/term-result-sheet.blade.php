<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 30px 26px; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1f2937; font-size: 11px; }
    .header { text-align: center; border-bottom: 2px solid #3d5494; padding-bottom: 6px; margin-bottom: 10px; }
    .school { font-size: 16px; font-weight: bold; }
    .sub { font-size: 11px; color: #4b5563; }
    .sub b { color: #1f2937; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th, table.grid td { border: 1px solid #cbd5e1; padding: 3px 5px; }
    table.grid thead th { background: #eef1f8; color: #34486f; font-size: 9.5px; text-align: center; vertical-align: bottom; }
    td.name { text-align: left; white-space: nowrap; }
    td.c, th.c { text-align: center; }
    tr.stripe td { background: #f7f8fb; }
    .fail { color: #dc2626; font-weight: bold; }
    .absent { color: #9ca3af; }
    td.total, td.pos { font-weight: bold; }
    .foot { margin-top: 12px; font-size: 9.5px; color: #6b7280; }
    .foot .sig { margin-top: 16px; }
  </style>
</head>
<body>
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
        <th class="c">Total</th>
        <th class="c">Ave</th>
        <th class="c">Pos</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows as $i => $r)
        <tr class="{{ $i % 2 ? 'stripe' : '' }}">
          <td class="c">{{ $i + 1 }}</td>
          <td class="name">{{ $r['student']->first_name }} {{ $r['student']->last_name }}</td>
          @foreach ($displaySubjects ?? $subjects as $s)
            @php $m = $r['marks'][$s->id] ?? null; @endphp
            @if (! array_key_exists($s->id, $r['marks']))
              {{-- graded subject, filled in by hand on the printed sheet --}}
              <td class="c"></td>
            @elseif ($m === null)
              <td class="c absent">x</td>
            @else
              <td class="c {{ ($passMark !== null && $m < ($failBelow[$s->id] ?? $passMark)) ? 'fail' : '' }}">{{ (int) round($m) }}</td>
            @endif
          @endforeach
          <td class="c total">{{ (int) round($r['total']) }}</td>
          <td class="c">{{ $r['average'] }}</td>
          <td class="c pos">{{ $r['positionLabel'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="foot">
    @if ($passMark !== null)<span style="color:#dc2626;">Red</span> = fail (below {{ $passMark }}) &middot; @endif x = absent.
    <div class="sig">Class teacher: {{ $teacher ? $teacher->first_name.' '.$teacher->last_name : '__________________' }}
      &nbsp;&nbsp;&nbsp; Signature: __________________</div>
  </div>
</body>
</html>
