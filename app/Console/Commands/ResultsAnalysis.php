<?php

namespace App\Console\Commands;

use App\Domain\Reporting\Services\ResultAnalysis;
use App\Models\Grade;
use App\Models\Offering;
use App\Models\School;
use App\Models\Schoolyear;
use App\Models\Term;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Print the end-of-term result analysis for every class at once: per class the
 * analysis table (subject × male/female/overall, fail/pass/mastery, average) and
 * the histogram, on the combined term marks (tests 25% + exam 75%), as PDFs in
 * one folder. The same figures the By term page shows; this is for when the
 * head teacher asks for "the results analysed by class" and six classes would
 * otherwise mean twelve downloads.
 *
 *   php artisan results:analysis                          # current year, current (else last) term, every class
 *   php artisan results:analysis --term="Term 3"          # a named term
 *   php artisan results:analysis --year=2025-2026 --term="Term 3" --class="Grade 4"
 *   php artisan results:analysis --out=/tmp/analysis      # elsewhere than storage/app/analysis/<year>/<term>
 */
class ResultsAnalysis extends Command
{
    protected $signature = 'results:analysis
        {--year= : School year name (default: the current year, else the latest)}
        {--term= : Term name, e.g. "Term 3" (default: the term we are in, else the year\'s last term)}
        {--class=* : Only these classes, by display name (e.g. "Grade 4" or "Grade 1 - A"); repeatable}
        {--out= : Output folder (default: storage/app/analysis/<year>/<term>)}
        {--no-histogram : Analysis table only}';

    protected $description = 'Print the full-term result analysis (and histogram) PDF for every class of a term';

    public function handle(ResultAnalysis $analysis): int
    {
        $school = School::first();
        if (! $school) {
            $this->error('No school is set up yet.');

            return self::FAILURE;
        }

        $year = $this->option('year')
            ? Schoolyear::where('name', $this->option('year'))->first()
            : (Schoolyear::current() ?? Schoolyear::latest());
        if (! $year) {
            $this->error($this->option('year') ? 'No school year named "'.$this->option('year').'".' : 'No school year found.');

            return self::FAILURE;
        }

        $terms = $year->terms()->orderBy('start')->get();
        $now = now();
        $term = $this->option('term')
            ? $terms->firstWhere('name', $this->option('term'))
            : ($terms->first(fn (Term $t) => $t->start <= $now && $t->end >= $now) ?? $terms->last());
        if (! $term) {
            $this->error($this->option('term')
                ? 'No term named "'.$this->option('term').'" in '.$year->name.'. Terms: '.$terms->pluck('name')->join(', ').'.'
                : 'No terms in '.$year->name.'.');

            return self::FAILURE;
        }

        $offerings = Offering::where('schoolyear_id', $year->id)->with('grade', 'schoolyear')->get()
            ->sortBy(fn (Offering $o) => Grade::sortKey($o->grade?->name).'-'.$o->name) // the scorebook's class order
            ->values();
        if ($wanted = $this->option('class')) {
            $offerings = $offerings->filter(fn (Offering $o) => in_array($o->displayName(), $wanted, true))->values();
            $missing = array_diff($wanted, $offerings->map->displayName()->all());
            if ($missing) {
                $this->error('No class named '.implode(', ', array_map(fn ($c) => '"'.$c.'"', $missing)).' in '.$year->name.'.');

                return self::FAILURE;
            }
        }
        if ($offerings->isEmpty()) {
            $this->error('No classes in '.$year->name.'.');

            return self::FAILURE;
        }

        $out = $this->option('out') ?: storage_path('app/analysis/'.Str::slug($year->name).'/'.Str::slug($term->name));
        if (! is_dir($out) && ! @mkdir($out, 0775, true)) {
            $this->error('Cannot create '.$out);

            return self::FAILURE;
        }

        $this->info($school->name.' · '.$term->name.' '.$year->name.' · '.$offerings->count().' '.Str::plural('class', $offerings->count()));

        foreach ($offerings as $offering) {
            $name = $offering->displayName();
            $data = $analysis->forTerm($offering, $term, $school);
            if ($data['satCount'] === 0) {
                $this->line(sprintf('  %-16s no marks this term, skipped', $name));
                continue;
            }

            $files = ['Analysis '.$name.' '.$term->name.'.pdf' => $analysis->termAnalysisPdf($offering, $term, $school)];
            if (! $this->option('no-histogram')) {
                $files['Histogram '.$name.' '.$term->name.'.pdf'] = $analysis->termHistogramPdf($offering, $term, $school);
            }
            foreach ($files as $file => $pdf) {
                file_put_contents($out.'/'.$file, $pdf->output());
            }

            $this->line(sprintf('  %-16s %d of %d sat · %s', $name, $data['satCount'], $data['studentCount'], implode(', ', array_keys($files))));
        }

        $this->info('Written to '.$out);

        return self::SUCCESS;
    }
}
