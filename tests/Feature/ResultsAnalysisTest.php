<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Offering;
use App\Models\Profile;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The full-term result analysis: the By term page's analysis/histogram as PDFs,
 * and `results:analysis` printing them for every class of a term at once.
 */
class ResultsAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    public function setUp(): void
    {
        parent::setUp();
        $this->school = School::first() ?? School::factory()->create();
        $this->school->configs()->updateOrCreate(['key' => 'scorebook_period_mode'], ['value' => 'tests']);
        AssessmentType::factory()->test()->create(['school_id' => $this->school->id]);
        AssessmentType::factory()->exam()->create(['school_id' => $this->school->id]);
    }

    /**
     * A class with Mathematics and three pupils whose combined term mark lands one
     * in each band: 88 (mastery), 52 (pass), 20 (fail). Test and exam carry the
     * same mark, and each splits 25/75 without a half, so the term mark IS the mark.
     */
    private function classOf(string $gradeName, array $marks = [88, 52, 20]): Offering
    {
        $offering = Offering::factory()->create(['schoolyear_id' => $this->schoolyear->id, 'grade_id' => Grade::factory()->create(['name' => $gradeName])->id]);
        $maths = Subject::firstOrCreate(['name' => 'Mathematics'], ['counts_toward_total' => true]);
        $offering->subjects($this->term->id)->save($maths, ['term_id' => $this->term->id]);

        $assessments = [];
        foreach (['Test', 'Exam'] as $type) {
            $typeId = AssessmentType::where('school_id', $this->school->id)->where('name', $type)->value('id');
            $assessments[] = Assessment::factory()->create([
                'assessment_type_id' => $typeId, 'offering_id' => $offering->id, 'term_id' => $this->term->id,
                'subject_id' => $maths->id, 'max_score' => 100, 'date' => $this->term->start,
            ]);
        }

        foreach ($marks as $i => $mark) {
            $pupil = Student::factory()->create(['first_name' => 'Pupil', 'last_name' => $gradeName.' '.$i]);
            Profile::factory()->create(['user_id' => $pupil->id, 'gender' => $i % 2 ? 'F' : 'M']);
            Enrollment::factory()->create(['user_id' => $pupil->id, 'offering_id' => $offering->id]);
            foreach ($assessments as $a) {
                AssessmentScore::factory()->create(['user_id' => $pupil->id, 'assessment_id' => $a->id, 'score' => $mark]);
            }
        }

        return $offering;
    }

    #[Test]
    public function the_by_term_page_links_to_full_term_analysis_and_histogram_pdfs(): void
    {
        $offering = $this->classOf('Grade 4');
        $params = ['offering' => $offering, 'term' => $this->term->id, 'scope' => 'term'];

        $this->actingAs($this->headmaster)
            ->get(route('term-grid.overview', ['offering' => $offering, 'term' => $this->term->id]))
            ->assertOk()
            ->assertSee('term-analysis?term='.$this->term->id.'&amp;scope=term', false)
            ->assertSee('term-histogram?term='.$this->term->id.'&amp;scope=term', false);

        $analysis = $this->actingAs($this->headmaster)->get(route('term-grid.analysis', $params));
        $analysis->assertOk()->assertDownload('Analysis Grade 4 '.$this->term->name.' (full term).pdf');
        $text = $this->pdfText($analysis->getContent());
        // Overall: 3 in class, 3 sat, one fail (20), one pass (52), one mastery (88), average 53.
        $this->assertStringContainsString('\\(fullterm\\)', $text); // DomPDF escapes the brackets
        $this->assertStringContainsString("Overall\n3\n3\n1\n34%\n1\n33%\n1\n33%\n53\n", $text);
        // Split by sex: two boys (88, 20), one girl (52).
        $this->assertStringContainsString("Male\n2\n2\n1\n50%\n0\n0%\n1\n50%\n54\n", $text);
        $this->assertStringContainsString("Female\n1\n1\n0\n0%\n1\n100%\n0\n0%\n52\n", $text);

        $histogram = $this->actingAs($this->headmaster)->get(route('term-grid.histogram', $params));
        $histogram->assertOk()->assertDownload('Histogram Grade 4 '.$this->term->name.' (full term).pdf');
        $text = $this->pdfText($histogram->getContent());
        $this->assertStringContainsString("NUMBEROFSTUDENTSINTHECLASS:\n3\n", $text);
        $this->assertStringContainsString("NUMBEROFSTUDENTSSAT:\n3\n", $text);
    }

    #[Test]
    public function the_command_prints_analysis_and_histogram_for_every_class_of_the_term(): void
    {
        $this->classOf('Grade 1');
        $this->classOf('Grade 2', [88, 88, 88]);
        $this->classOf('Grade 3', []); // enrolled nobody, so nothing sat
        $out = sys_get_temp_dir().'/kubo-analysis-'.uniqid();

        $this->artisan('results:analysis', ['--term' => $this->term->name, '--out' => $out])
            ->expectsOutputToContain('3 classes')
            ->expectsOutputToContain('Grade 1')
            ->expectsOutputToContain('no marks this term, skipped')
            ->assertSuccessful();

        $files = collect(scandir($out))->filter(fn ($f) => str_ends_with($f, '.pdf'))->values()->all();
        $this->assertSame([
            'Analysis Grade 1 '.$this->term->name.'.pdf', 'Analysis Grade 2 '.$this->term->name.'.pdf',
            'Histogram Grade 1 '.$this->term->name.'.pdf', 'Histogram Grade 2 '.$this->term->name.'.pdf',
        ], $files);

        $text = $this->pdfText(file_get_contents($out.'/Analysis Grade 2 '.$this->term->name.'.pdf'));
        $this->assertStringContainsString("Overall\n3\n3\n0\n0%\n0\n0%\n3\n100%\n88\n", $text);

        // --class narrows to the named classes; --no-histogram leaves the tables only.
        $only = $out.'-only';
        $this->artisan('results:analysis', ['--term' => $this->term->name, '--out' => $only, '--class' => ['Grade 1'], '--no-histogram' => true])
            ->assertSuccessful();
        $this->assertSame(['Analysis Grade 1 '.$this->term->name.'.pdf'],
            collect(scandir($only))->filter(fn ($f) => str_ends_with($f, '.pdf'))->values()->all());
    }

    #[Test]
    public function the_command_names_what_it_cannot_find(): void
    {
        $this->classOf('Grade 1');

        $this->artisan('results:analysis', ['--term' => 'Term 9'])
            ->expectsOutputToContain('No term named "Term 9"')
            ->assertFailed();
        $this->artisan('results:analysis', ['--term' => $this->term->name, '--class' => ['Grade 7']])
            ->expectsOutputToContain('No class named "Grade 7"')
            ->assertFailed();
        $this->artisan('results:analysis', ['--year' => '1999-2000'])
            ->expectsOutputToContain('No school year named "1999-2000"')
            ->assertFailed();
    }
}
