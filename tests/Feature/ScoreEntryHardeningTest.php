<?php

namespace Tests\Feature;

use App\Domain\Reporting\Services\ReportReadiness;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Offering;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Foolproofing the two score-entry paths (term grid + wizard) against each
 * other and against mistakes: no silent retyping, maxima respected, closed
 * terms locked, class access enforced, strays surfaced, duplicates impossible.
 */
class ScoreEntryHardeningTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Offering $offering;

    private Subject $maths;

    private Term $openTerm;

    private AssessmentType $test;

    private AssessmentType $exam;

    private Student $pupil;

    public function setUp(): void
    {
        parent::setUp();

        $this->school = School::first() ?? School::factory()->create();
        $this->test = AssessmentType::factory()->test()->create(['school_id' => $this->school->id]);
        $this->exam = AssessmentType::factory()->exam()->create(['school_id' => $this->school->id]);

        $this->offering = Offering::factory()->create([
            'schoolyear_id' => $this->schoolyear->id,
            'grade_id' => Grade::factory()->create(['name' => 'Grade 2'])->id,
        ]);
        $this->maths = Subject::factory()->create(['name' => 'Mathematics']);
        // Four months wide, so the tests-mode buckets (first, second, last month)
        // leave one month uncovered — room to test stray historic marks.
        $this->openTerm = Term::create([
            'name' => 'Open Term', 'schoolyear_id' => $this->schoolyear->id,
            'start' => now()->subMonths(2)->startOfMonth(), 'end' => now()->addMonth(),
        ]);
        $this->offering->subjects($this->openTerm->id)->save($this->maths, ['term_id' => $this->openTerm->id]);

        $this->pupil = Student::factory()->create();
        Enrollment::factory()->create(['user_id' => $this->pupil->id, 'offering_id' => $this->offering->id]);
    }

    private function gridSave(array $payload, $as = null)
    {
        $base = [
            'term' => $this->openTerm->id,
            'month' => Carbon::parse($this->openTerm->start)->format('Y-m'),
            'type' => 'Test',
        ];

        return $this->actingAs($as ?? $this->headmaster)
            ->post(route('term-grid.save', $this->offering), $base + $payload);
    }

    private function wizardAssessment(AssessmentType $type, int $max, ?string $date = null): Assessment
    {
        return Assessment::factory()->create([
            'assessment_type_id' => $type->id,
            'offering_id' => $this->offering->id,
            'term_id' => $this->openTerm->id,
            'subject_id' => $this->maths->id,
            'max_score' => $max,
            'name' => 'wizard-made',
            'date' => $date ?? Carbon::parse($this->openTerm->start)->format('Y-m-d'),
        ]);
    }

    // ---- entry-path collisions ---------------------------------------------

    #[Test]
    public function the_grid_never_retypes_an_assessment_that_already_has_scores(): void
    {
        $examAssessment = $this->wizardAssessment($this->exam, 75);
        AssessmentScore::factory()->create(['assessment_id' => $examAssessment->id, 'user_id' => $this->pupil->id, 'score' => 60]);

        // Saving the grid as "Test" must NOT flip the exam to a 0.25-weight test.
        $this->gridSave(['scores' => [$this->maths->id => [$this->pupil->id => '20']]])
            ->assertSessionHas('error');

        $this->assertSame($this->exam->id, $examAssessment->fresh()->assessment_type_id);
        // And the 20 was not written into the exam either.
        $this->assertSame(60, (int) AssessmentScore::where('assessment_id', $examAssessment->id)->where('user_id', $this->pupil->id)->value('score'));
    }

    #[Test]
    public function a_scoreless_assessment_may_still_be_retyped_on_save(): void
    {
        $examAssessment = $this->wizardAssessment($this->exam, 75);

        $this->gridSave(['scores' => [$this->maths->id => [$this->pupil->id => '20']]])
            ->assertSessionMissing('error');

        $this->assertSame($this->test->id, $examAssessment->fresh()->assessment_type_id);
    }

    #[Test]
    public function marks_above_a_columns_own_maximum_are_refused(): void
    {
        $this->wizardAssessment($this->test, 25);

        // 80 > max 25: the column is refused (80/25 would be 320%).
        $this->gridSave(['scores' => [$this->maths->id => [$this->pupil->id => '80']]])
            ->assertSessionHas('error');
        $this->assertSame(0, AssessmentScore::where('user_id', $this->pupil->id)->count());

        // 20 fits and saves.
        $this->gridSave(['scores' => [$this->maths->id => [$this->pupil->id => '20']]])
            ->assertSessionMissing('error');
        $this->assertSame(20, (int) AssessmentScore::where('user_id', $this->pupil->id)->value('score'));
    }

    // ---- locking & access ---------------------------------------------------

    // ---- maxima and percentages -------------------------------------------

    private function testsMode(): void
    {
        $this->school->configs()->updateOrCreate(['key' => 'scorebook_period_mode'], ['value' => 'tests']);
    }

    private function periodParams(): array
    {
        return [
            'offering' => $this->offering,
            'term' => $this->openTerm->id,
            'month' => Carbon::parse($this->openTerm->start)->format('Y-m'),
        ];
    }

    #[Test]
    public function a_column_without_an_assessment_limits_its_cells_to_the_default_maximum(): void
    {
        // Tests mode scores a test out of 25. The cells of a column that has no
        // assessment yet must say so too, not accept anything up to 100.
        $this->testsMode();

        $html = $this->actingAs($this->headmaster)
            ->get(route('term-grid.edit', $this->periodParams() + ['edit' => 1]))
            ->assertOk()
            ->getContent();

        $cell = 'name="scores['.$this->maths->id.']['.$this->pupil->id.']"';
        $this->assertMatchesRegularExpression('/max="25"\s+'.preg_quote($cell, '/').'/', $html);
    }

    #[Test]
    public function the_example_fill_scales_to_each_columns_maximum(): void
    {
        $this->app['config']->set('app.demo', true);
        $this->testsMode();

        $html = $this->actingAs($this->headmaster)
            ->get(route('term-grid.edit', $this->periodParams() + ['edit' => 1]))
            ->assertOk()
            ->getContent();

        // Only mark cells, and each mark as a share of its column's maximum.
        $this->assertStringContainsString("input[type=number][name^='scores[']", $html);
        $this->assertStringContainsString('/ 100 * max', $html);
    }

    #[Test]
    public function the_period_analysis_reads_a_mark_against_its_maximum(): void
    {
        // 18 out of 25 is 72%: a pass. Read as a raw 18 it fell below 40 and
        // every pupil of a tests-mode school came out as failed.
        $this->testsMode();
        $assessment = $this->wizardAssessment($this->test, 25);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'user_id' => $this->pupil->id, 'score' => 18]);

        $analysis = $this->actingAs($this->headmaster)
            ->get(route('term-grid.report', $this->periodParams()))
            ->assertOk()
            ->viewData('analysis');

        $overall = $analysis->first()['overall'];
        $this->assertSame(1, $overall['sat']);
        $this->assertSame(0, $overall['fail']);
        $this->assertSame(1, $overall['pass']);
        $this->assertSame(72, $overall['average']);
    }

    #[Test]
    public function the_fail_mark_on_a_result_sheet_follows_the_columns_maximum(): void
    {
        // Months mode marks fails in red below 40%. In a column out of 50 that
        // is below 20, so 30 passes and 15 fails.
        $assessment = $this->wizardAssessment($this->test, 50);
        AssessmentScore::factory()->create(['assessment_id' => $assessment->id, 'user_id' => $this->pupil->id, 'score' => 30]);

        $response = $this->actingAs($this->headmaster)->get(route('term-grid.report', $this->periodParams()))->assertOk();
        $this->assertEquals(20, $response->viewData('failBelow')[$this->maths->id]);
        $response->assertDontSee('text-red-600 font-semibold', false);

        AssessmentScore::where('assessment_id', $assessment->id)->update(['score' => 15]);
        $this->actingAs($this->headmaster)->get(route('term-grid.report', $this->periodParams()))
            ->assertOk()
            ->assertSee('text-red-600 font-semibold', false);
    }

    #[Test]
    public function the_wizard_starts_a_tests_mode_slot_at_the_schools_scale(): void
    {
        $this->testsMode();

        $this->actingAs($this->headmaster)
            ->get(route('reporting.assessment.create', ['type' => $this->test->id]))
            ->assertOk()
            ->assertSee('id="max_score" value="25"', false);

        $this->actingAs($this->headmaster)
            ->get(route('reporting.assessment.create', ['type' => $this->exam->id]))
            ->assertOk()
            ->assertSee('id="max_score" value="75"', false);
    }

    #[Test]
    public function a_teacher_cannot_save_the_grid_of_a_closed_term(): void
    {
        DB::table('teacher_offering')->insert(['user_id' => $this->teacher->id, 'offering_id' => $this->offering->id, 'principal' => 0]);
        $this->offering->subjects($this->term->id)->save($this->maths, ['term_id' => $this->term->id]);

        $this->actingAs($this->teacher)->post(route('term-grid.save', $this->offering), [
            'term' => $this->term->id, // TestCase's term ended long ago
            'month' => Carbon::parse($this->term->start)->format('Y-m'),
            'type' => 'Test',
            'scores' => [$this->maths->id => [$this->pupil->id => '50']],
        ])->assertForbidden();
    }

    #[Test]
    public function a_headmaster_may_still_correct_a_closed_term(): void
    {
        $this->offering->subjects($this->term->id)->save($this->maths, ['term_id' => $this->term->id]);

        $this->actingAs($this->headmaster)->post(route('term-grid.save', $this->offering), [
            'term' => $this->term->id,
            'month' => Carbon::parse($this->term->start)->format('Y-m'),
            'type' => 'Test',
            'scores' => [$this->maths->id => [$this->pupil->id => '50']],
        ])->assertRedirect()->assertSessionMissing('error');
    }

    #[Test]
    public function a_teacher_unrelated_to_the_class_cannot_save_its_grid(): void
    {
        // No teacher_offering row, no teacher_assignments row: not their class.
        $this->gridSave(['scores' => [$this->maths->id => [$this->pupil->id => '50']]], $this->teacher)
            ->assertForbidden();
    }

    // ---- semantics ----------------------------------------------------------

    #[Test]
    public function absent_saves_a_null_score_like_the_wizard_does(): void
    {
        $this->gridSave(['absent' => [$this->maths->id => [$this->pupil->id => 1]]])
            ->assertSessionMissing('error');

        $row = AssessmentScore::where('user_id', $this->pupil->id)->first();
        $this->assertSame(1, (int) $row->absent);
        $this->assertNull($row->score);
    }

    #[Test]
    public function the_wizard_rejects_an_over_max_score_with_a_form_error_not_a_500(): void
    {
        $assessment = $this->wizardAssessment($this->test, 25);

        $this->actingAs($this->headmaster)
            ->from(route('reporting.assessment.scores', $assessment))
            ->post(route('reporting.assessment.saveScores', $assessment), [
                'scores' => [$this->pupil->id => 90],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('validation');

        $this->assertSame(0, AssessmentScore::where('assessment_id', $assessment->id)->count());
    }

    #[Test]
    public function graded_subjects_hide_from_the_grid_until_asked_for_or_marked(): void
    {
        $pe = Subject::factory()->create(['name' => 'Physical Education', 'counts_toward_total' => false]);
        $this->offering->subjects($this->openTerm->id)->save($pe, ['term_id' => $this->openTerm->id]);

        $params = ['offering' => $this->offering, 'edit' => 1, 'term' => $this->openTerm->id];

        // Hidden by default (letter-only, filled in by hand), with a toggle.
        $this->actingAs($this->headmaster)->get(route('term-grid.edit', $params))
            ->assertOk()
            ->assertDontSee('Physical Education')
            ->assertSee('Show 1 graded subject');

        // Shown when asked.
        $this->actingAs($this->headmaster)->get(route('term-grid.edit', $params + ['graded' => 1]))
            ->assertOk()
            ->assertSee('Physical Education');

        // Once it carries marks this period, it can no longer be out of sight.
        $this->gridSave(['scores' => [$pe->id => [$this->pupil->id => '70']], 'graded' => 1]);
        $this->actingAs($this->headmaster)->get(route('term-grid.edit', $params))
            ->assertOk()
            ->assertSee('Physical Education');
    }

    #[Test]
    public function marks_outside_the_canonical_buckets_get_their_own_period(): void
    {
        $this->school->configs()->updateOrCreate(['key' => 'scorebook_period_mode'], ['value' => 'tests']);

        // An exam entered before the school switched to tests mode, dated in a
        // month none of the three buckets cover.
        $stray = \Illuminate\Support\Carbon::parse($this->openTerm->start)->addMonths(2)->format('Y-m');
        $this->wizardAssessment($this->exam, 75, $stray.'-10');

        $response = $this->actingAs($this->headmaster)->get(route('term-grid.report', [
            'offering' => $this->offering, 'term' => $this->openTerm->id, 'month' => $stray,
        ]));

        // The URL's month resolves to a real period (named by month) instead of
        // silently falling back to the Exam bucket.
        $response->assertOk()->assertSee(\Illuminate\Support\Carbon::parse($stray.'-01')->format('F'));
    }

    // ---- strays & duplicates ------------------------------------------------

    #[Test]
    public function a_subject_with_more_same_type_assessments_than_the_class_is_flagged(): void
    {
        // Two more subjects with ONE test each define the class norm; Maths gets two.
        foreach (['English', 'Science'] as $name) {
            $subject = Subject::factory()->create(['name' => $name]);
            $this->offering->subjects($this->openTerm->id)->save($subject, ['term_id' => $this->openTerm->id]);
            Assessment::factory()->create([
                'assessment_type_id' => $this->test->id, 'offering_id' => $this->offering->id,
                'term_id' => $this->openTerm->id, 'subject_id' => $subject->id, 'max_score' => 100, 'name' => 'test1',
            ]);
        }
        $this->wizardAssessment($this->test, 100);
        $this->wizardAssessment($this->test, 100, Carbon::parse($this->openTerm->start)->addDays(20)->format('Y-m-d'));

        $flagged = (new ReportReadiness())->duplicateAssessments($this->offering, $this->openTerm, $this->school);

        $this->assertTrue($flagged->contains(fn ($f) => $f['subject'] === 'Mathematics' && $f['count'] === 2));
        $this->assertFalse($flagged->contains(fn ($f) => $f['subject'] === 'English'));
    }

    #[Test]
    public function a_subject_cannot_be_attached_twice_to_the_same_class_and_term(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('subject_term_offering')->insert([
            'subject_id' => $this->maths->id, 'term_id' => $this->openTerm->id, 'offering_id' => $this->offering->id,
        ]);
    }
}
