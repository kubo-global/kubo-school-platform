<?php

namespace App\Domain\Reporting\Services;

use App\Models\Offering;
use App\Models\Profile;
use App\Models\School;
use App\Models\Term;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Collection;

/**
 * The "Result Analysis" a Gambian school hands in after each assessment: per
 * subject, split male / female / overall, how many pupils sat and how many
 * fell in each band (fail below 40, pass 40-79, mastery 80 and above), with
 * the average. One set of rules for both readings of a term:
 *
 *  - a single period (the exam, or one test) on its raw /100 marks — the
 *    scorebook's By test view hands in the marks itself via {@see bands()};
 *  - the whole term combined (tests 25% + exam 75%) via {@see forTerm()},
 *    which is what the head teacher analyses at the end of a term and what
 *    `results:analysis` prints for every class at once.
 */
class ResultAnalysis
{
    /**
     * The combined-term analysis for a class: the same term marks the report
     * cards and the positions carry, so the three never disagree.
     *
     * @return array{analysis: Collection, studentCount: int, satCount: int}
     */
    public function forTerm(Offering $offering, Term $term, School $school): array
    {
        return $this->fromRanked($offering, $term, (new PositionService)->rankedReports($offering, $term, $school));
    }

    /** As {@see forTerm()}, on ranked reports the caller already has (the By term page ranks the class anyway). */
    public function fromRanked(Offering $offering, Term $term, Collection $ranked): array
    {
        $marks = [];
        foreach ($ranked as $r) {
            foreach ($r['report']['results']['subjectResults'] ?? [] as $subjectName => $sr) {
                $marks[$subjectName][$r['student_id']] = ($sr['hasScores'] ?? false) ? $sr['subjectTotal'] : null;
            }
        }

        $students = $this->students($offering);

        return [
            'analysis' => $this->bands($students, $this->subjectsFor($offering, $term),
                fn ($subj, $st) => $marks[$subj->name][$st->id] ?? null),
            'studentCount' => $students->count(),
            'satCount' => $ranked->count(), // rankedReports already drops pupils without a single real mark
        ];
    }

    /** The full-term analysis table as a PDF, the same one the By term page shows on screen. */
    public function termAnalysisPdf(Offering $offering, Term $term, School $school): PdfDocument
    {
        $offering->loadMissing('grade', 'schoolyear');

        return PDF::loadView('print.term-analysis', [
            'offering' => $offering, 'school' => $school, 'term' => $term,
            'periodTitle' => '(full term)',
            'analysis' => $this->forTerm($offering, $term, $school)['analysis'],
        ])->setPaper('a4', 'portrait');
    }

    /** The full-term histogram as a PDF; $outline gives the blank black-and-white version to colour in. */
    public function termHistogramPdf(Offering $offering, Term $term, School $school, bool $outline = false): PdfDocument
    {
        $offering->loadMissing('grade', 'schoolyear');
        $data = $this->forTerm($offering, $term, $school);

        return PDF::loadView('print.term-histogram', [
            'offering' => $offering, 'school' => $school, 'term' => $term,
            'periodTitle' => '(full term)',
            'studentCount' => $data['studentCount'],
            'satCount' => $data['satCount'],
            'analysis' => $data['analysis'],
            'outline' => $outline,
        ])->setPaper('a4', 'portrait');
    }

    /**
     * Subjects the analysis covers: the class's flagged core subjects when any
     * are set (a school's promotion analysis runs on core subjects only), else
     * every counting subject — so schools without flags keep the full analysis.
     */
    public function coreOrAll(Collection $countingSubjects): Collection
    {
        $core = $countingSubjects->filter(fn ($s) => (int) ($s->pivot->core ?? 0) === 1)->values();

        return $core->isNotEmpty() ? $core : $countingSubjects;
    }

    /** The class's subjects for a term in the school's own column order, counting ones first. */
    public function subjects(Offering $offering, Term $term): Collection
    {
        return $offering->subjects($term->id)->get()
            ->sortBy(fn ($s) => [
                $s->countsTowardTotalResolved() ? 0 : 1,
                $s->pivot->sort_order !== null ? (int) $s->pivot->sort_order : PHP_INT_MAX,
                $s->id,
            ])
            ->values();
    }

    /** The counting subjects the analysis reports on, see {@see coreOrAll()}. */
    public function subjectsFor(Offering $offering, Term $term): Collection
    {
        return $this->coreOrAll($this->subjects($offering, $term)->filter(fn ($s) => $s->countsTowardTotalResolved())->values());
    }

    /** The class's active pupils, alphabetical. */
    public function students(Offering $offering): Collection
    {
        return $offering->enrollments()
            ->join('users', 'enrollments.user_id', '=', 'users.id')
            ->where('users.archived', false)
            ->orderBy('users.last_name')->orderBy('users.first_name')
            ->with('student')->select('enrollments.*')->get()
            ->map(fn ($e) => $e->student)->filter()->values();
    }

    /**
     * The band machinery: $markOf(subject, student) returns a /100 mark, or null
     * when the pupil was absent or has no mark. Returns one row per subject keyed
     * male / female / overall.
     */
    public function bands(Collection $students, Collection $subjects, callable $markOf): Collection
    {
        $genders = Profile::whereIn('user_id', $students->pluck('id'))->pluck('gender', 'user_id');

        $statsFor = function ($subset, $subj) use ($markOf) {
            $marks = [];
            foreach ($subset as $st) {
                $m = $markOf($subj, $st);
                if ($m !== null) {
                    $marks[] = $m;
                }
            }
            $sat = count($marks);
            $count = fn ($fn) => count(array_filter($marks, $fn));
            // The three bands are exclusive so fail + pass + mastery totals 100%.
            $fail = $count(fn ($m) => $m < 40);
            $pass = $count(fn ($m) => $m >= 40 && $m < 80);
            $mastery = $count(fn ($m) => $m >= 80);

            // Percentages must also total exactly 100, so round by largest
            // remainder instead of independently (which can give 99 or 101).
            $pcts = [0, 0, 0];
            if ($sat) {
                $shares = array_map(fn ($n) => $n / $sat * 100, [$fail, $pass, $mastery]);
                $pcts = array_map('intval', array_map('floor', $shares));
                $left = 100 - array_sum($pcts);
                $order = array_keys($shares);
                usort($order, fn ($a, $b) => ($shares[$b] - floor($shares[$b])) <=> ($shares[$a] - floor($shares[$a])) ?: $a <=> $b);
                for ($i = 0; $i < $left; $i++) {
                    $pcts[$order[$i % 3]]++;
                }
            }

            return [
                'students' => $subset->count(), 'sat' => $sat,
                'fail' => $fail, 'failPct' => $pcts[0],
                'pass' => $pass, 'passPct' => $pcts[1],
                'mastery' => $mastery, 'masteryPct' => $pcts[2],
                'average' => $sat ? (int) round(array_sum($marks) / $sat) : 0,
            ];
        };

        $males = $students->filter(fn ($s) => ($genders[$s->id] ?? null) === 'M');
        $females = $students->filter(fn ($s) => ($genders[$s->id] ?? null) === 'F');

        return $subjects->map(fn ($subj) => [
            'subject' => $subj,
            'male' => $statsFor($males, $subj),
            'female' => $statsFor($females, $subj),
            'overall' => $statsFor($students, $subj),
        ]);
    }
}
