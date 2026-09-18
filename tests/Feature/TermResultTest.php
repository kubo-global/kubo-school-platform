<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\Enrollment;
use App\Models\Offering;
use App\Models\School;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\AlbredaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The "by month" term-marks grid and the result sheet / analysis / histogram it
 * produces, exercised against the seeded Albreda Grade 6A.
 */
class TermResultTest extends TestCase
{
    use RefreshDatabase;

    private Offering $offering;
    private User $head;
    private Term $term2;
    private School $school;

    public function setUp(): void
    {
        parent::setUp();
        $this->seed(AlbredaSeeder::class);
        $this->school = School::where('name', 'Albreda Lower Basic School')->firstOrFail();
        $this->offering = Offering::where('name', 'A')->whereHas('grade', fn ($q) => $q->where('name', 'Grade 6'))->firstOrFail();
        $this->head = User::where('first_name', 'Mr')->where('last_name', 'Badjie')->firstOrFail();
        $this->term2 = Term::where('name', 'Term 2')->firstOrFail();
    }

    private function params(array $extra = []): array
    {
        return array_merge(['offering' => $this->offering, 'term' => $this->term2->id, 'month' => '2026-04', 'type' => 'Exam'], $extra);
    }

    private function testType(string $name): AssessmentType
    {
        return AssessmentType::where('school_id', $this->school->id)->where('name', $name)->firstOrFail();
    }

    #[Test]
    public function the_month_grid_is_edit_only_and_lands_on_the_results_view(): void
    {
        // Opening the grid without ?edit sends you to the results view.
        $resp = $this->actingAs($this->head)->get(route('term-grid.edit', $this->params()));
        $resp->assertRedirect();
        $this->assertStringContainsString('term-report', $resp->headers->get('Location'));

        // Edit mode shows the score inputs.
        $this->actingAs($this->head)->get(route('term-grid.edit', $this->params(['edit' => 1])))
            ->assertOk()
            ->assertSee('name="scores', false)
            ->assertSee('Done');
    }

    #[Test]
    public function saving_a_months_marks_stores_them_and_flags_absentees(): void
    {
        $english = Subject::where('name', 'English language')->firstOrFail();
        $ids = Enrollment::where('offering_id', $this->offering->id)->pluck('user_id');
        [$present, $absent] = [$ids[0], $ids[1]];

        $this->actingAs($this->head)->post(route('term-grid.save', $this->offering), [
            'term' => $this->term2->id, 'month' => '2026-02', 'type' => 'Test',
            'scores' => [$english->id => [$present => 72]],
            'absent' => [$english->id => [$absent => 1]],
        ])->assertRedirect();

        // A February Test assessment for English was created (topic-free).
        $exam = Assessment::where('subject_id', $english->id)
            ->where('term_id', $this->term2->id)
            ->where('assessment_type_id', $this->testType('Test')->id)
            ->whereMonth('date', 2)->first();
        $this->assertNotNull($exam);

        $this->assertEquals(72, AssessmentScore::where('assessment_id', $exam->id)->where('user_id', $present)->value('score'));
        $this->assertSame(1, (int) AssessmentScore::where('assessment_id', $exam->id)->where('user_id', $absent)->value('absent'));
    }

    #[Test]
    public function a_pupil_absent_earlier_can_be_marked_later(): void
    {
        $english = Subject::where('name', 'English language')->firstOrFail();
        $pupil = Enrollment::where('offering_id', $this->offering->id)->value('user_id');
        $base = ['term' => $this->term2->id, 'month' => '2026-03', 'type' => 'Test'];

        // Marked absent when the test is first entered.
        $this->actingAs($this->head)->post(route('term-grid.save', $this->offering), $base + ['absent' => [$english->id => [$pupil => 1]]]);
        // Sits it later; the teacher fills the mark in.
        $this->actingAs($this->head)->post(route('term-grid.save', $this->offering), $base + ['scores' => [$english->id => [$pupil => 64]]]);

        $exam = Assessment::where('subject_id', $english->id)
            ->where('term_id', $this->term2->id)
            ->where('assessment_type_id', $this->testType('Test')->id)
            ->whereMonth('date', 3)->first();
        $score = AssessmentScore::where('assessment_id', $exam->id)->where('user_id', $pupil)->first();

        $this->assertEquals(64, $score->score);
        $this->assertSame(0, (int) $score->absent);   // no longer absent
    }

    #[Test]
    public function clearing_a_period_removes_its_marks(): void
    {
        $countApril = fn () => Assessment::where('offering_id', $this->offering->id)
            ->where('term_id', $this->term2->id)->whereMonth('date', 4)->count();
        $this->assertGreaterThan(0, $countApril());

        $this->actingAs($this->head)->post(route('term-grid.clear', $this->offering), [
            'term' => $this->term2->id, 'month' => '2026-04',
        ])->assertRedirect();

        $this->assertSame(0, $countApril());
    }

    #[Test]
    public function the_report_page_shows_the_three_sections_on_screen(): void
    {
        $this->actingAs($this->head)->get(route('term-grid.report', $this->params()))
            ->assertOk()
            ->assertSee('Result sheet')
            ->assertSee('Analysis')
            ->assertSee('Histogram')
            ->assertSee('Kumba'); // top pupil, in the result sheet tab
    }

    #[Test]
    public function the_result_bundle_and_each_part_generate_as_pdf(): void
    {
        foreach (['term-grid.bundle', 'term-grid.result-sheet', 'term-grid.analysis', 'term-grid.histogram'] as $route) {
            $response = $this->actingAs($this->head)->get(route($route, $this->params()));
            $response->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    #[Test]
    public function students_sat_counts_pupils_with_a_real_mark_not_the_class_size(): void
    {
        $english = Subject::where('name', 'English language')->firstOrFail();
        $ids = Enrollment::where('offering_id', $this->offering->id)->pluck('user_id');

        // Rebuild the April exam period from scratch: three pupils sat English,
        // one was absent, the rest have no marks at all.
        $this->actingAs($this->head)->post(route('term-grid.clear', $this->offering), [
            'term' => $this->term2->id, 'month' => '2026-04',
        ]);
        $this->actingAs($this->head)->post(route('term-grid.save', $this->offering), [
            'term' => $this->term2->id, 'month' => '2026-04', 'type' => 'Exam',
            'scores' => [$english->id => [$ids[0] => 55, $ids[1] => 60, $ids[2] => 65]],
            'absent' => [$english->id => [$ids[3] => 1]],
        ]);

        foreach (['term-grid.histogram', 'term-grid.bundle'] as $route) {
            $text = $this->pdfText($this->actingAs($this->head)->get(route($route, $this->params()))->getContent());
            $this->assertStringContainsString("NUMBEROFSTUDENTSINTHECLASS:\n{$ids->count()}\n", $text, $route);
            $this->assertStringContainsString("NUMBEROFSTUDENTSSAT:\n3\n", $text, $route);
        }
    }

    #[Test]
    public function subject_columns_follow_the_pivot_sort_order_when_set(): void
    {
        $pivots = \DB::table('subject_term_offering')
            ->where('offering_id', $this->offering->id)->where('term_id', $this->term2->id)
            ->orderBy('subject_id')->get();
        $names = Subject::whereIn('id', $pivots->pluck('subject_id'))->orderBy('id')->pluck('name')->all();

        // Reverse the configured order; the result-sheet columns must follow it.
        foreach ($pivots as $i => $p) {
            \DB::table('subject_term_offering')->where('id', $p->id)->update(['sort_order' => count($pivots) - $i]);
        }
        $this->actingAs($this->head)->get(route('term-grid.report', $this->params()))
            ->assertSeeInOrder(array_reverse($names));

        // Without sort_order the historic subject-id order still applies.
        \DB::table('subject_term_offering')->where('offering_id', $this->offering->id)->update(['sort_order' => null]);
        $this->actingAs($this->head)->get(route('term-grid.report', $this->params()))
            ->assertSeeInOrder($names);
    }

    #[Test]
    public function pass_band_excludes_mastery_so_the_bands_total_100(): void
    {
        $english = Subject::where('name', 'English language')->firstOrFail();
        $ids = Enrollment::where('offering_id', $this->offering->id)->pluck('user_id');

        // One fail (30), one plain pass (50), one mastery (85).
        $this->actingAs($this->head)->post(route('term-grid.clear', $this->offering), [
            'term' => $this->term2->id, 'month' => '2026-04',
        ]);
        $this->actingAs($this->head)->post(route('term-grid.save', $this->offering), [
            'term' => $this->term2->id, 'month' => '2026-04', 'type' => 'Exam',
            'scores' => [$english->id => [$ids[0] => 30, $ids[1] => 50, $ids[2] => 85]],
        ]);

        $text = $this->pdfText($this->actingAs($this->head)->get(route('term-grid.analysis', $this->params()))->getContent());
        // Overall row: students 30 (class), sat 3, fail 1, pass 1 — NOT 2 — mastery 1, average 55.
        // Largest-remainder rounding makes the three percentages total exactly 100 (34+33+33).
        $this->assertStringContainsString("Overall\n30\n3\n1\n34%\n1\n33%\n1\n33%\n55\n", $text);
        $this->assertStringNotContainsString("(includes mastery)", $text);
    }

    #[Test]
    public function analysis_covers_only_core_subjects_when_flagged(): void
    {
        $english = Subject::where('name', 'English language')->firstOrFail();
        $maths = Subject::where('name', 'Mathematics')->firstOrFail();
        \DB::table('subject_term_offering')
            ->where('offering_id', $this->offering->id)->where('term_id', $this->term2->id)
            ->whereIn('subject_id', [$english->id, $maths->id])
            ->update(['core' => 1]);

        $sheet = $this->pdfText($this->actingAs($this->head)->get(route('term-grid.result-sheet', $this->params()))->getContent());
        $analysis = $this->pdfText($this->actingAs($this->head)->get(route('term-grid.analysis', $this->params()))->getContent());

        // The result sheet keeps every counting subject; the analysis shrinks to core.
        $this->assertStringContainsString('Science', $sheet);
        $this->assertStringContainsString('S.E.S.', $sheet);
        $this->assertStringContainsString('English', $analysis);
        $this->assertStringContainsString('Mathematics', $analysis);
        $this->assertStringNotContainsString('Science', $analysis);
        $this->assertStringNotContainsString('S.E.S.', $analysis);

        // Without flags the analysis covers everything (other offerings untouched).
        \DB::table('subject_term_offering')->update(['core' => null]);
        $analysis = $this->pdfText($this->actingAs($this->head)->get(route('term-grid.analysis', $this->params()))->getContent());
        $this->assertStringContainsString('Science', $analysis);
    }

    #[Test]
    public function graded_subjects_appear_as_blank_columns_on_the_result_sheet(): void
    {
        // A hand-graded subject (no marks in KUBO, excluded from totals) must
        // still get a blank column on the master list, and stay out of the analysis.
        $irk = Subject::create(['name' => 'I.R.K', 'school_id' => $this->school->id, 'counts_toward_total' => false]);
        \DB::table('subject_term_offering')->insert([
            'offering_id' => $this->offering->id, 'term_id' => $this->term2->id,
            'subject_id' => $irk->id, 'sort_order' => 9,
        ]);

        $before = $this->pdfText($this->actingAs($this->head)->get(route('term-grid.result-sheet', $this->params()))->getContent());
        $this->assertStringContainsString('I.R.K', $before);

        $analysis = $this->pdfText($this->actingAs($this->head)->get(route('term-grid.analysis', $this->params()))->getContent());
        $this->assertStringNotContainsString('I.R.K', $analysis);
    }

    #[Test]
    public function the_bw_bundle_marks_fails_without_relying_on_colour(): void
    {
        $coloured = $this->pdfText($this->actingAs($this->head)->get(route('term-grid.bundle', $this->params()))->getContent());
        $bw = $this->pdfText($this->actingAs($this->head)->get(route('term-grid.bundle', $this->params(['outline' => 1])))->getContent());

        // The colour version explains "Red = fail"; on a mono printer red is just
        // grey, so the B&W version must switch to an ink-only marker.
        $this->assertStringContainsString("Red\n", $coloured);
        $this->assertStringContainsString("Underlined\n", $bw);
        $this->assertStringNotContainsString("Red\n", $bw);
    }
}
