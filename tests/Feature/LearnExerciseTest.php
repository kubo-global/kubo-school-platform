<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Offering;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Support\SchoolCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Models\CurriculumMap;
use App\Domain\Learning\Models\ExerciseRun;
use App\Domain\Learning\Models\LessonAssignment;
use App\Domain\Learning\Models\Skill;
use App\Domain\Learning\Models\StudentSkill;
use KuboKolibri\Services\KolibriProvisioner;
use App\Domain\Learning\SkillGraph;
use App\Livewire\ExerciseCreator;
use App\Domain\Learning\Models\AuthoredExercise;
use App\Domain\Learning\Models\AuthoredQuestion;
use KuboKolibri\Services\ChannelGenerator;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LearnExerciseTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected Subject $math;
    protected Grade $grade;
    protected Offering $offering;
    protected int $currentWeek;

    public function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create([
            'kolibri_facility_id' => 'test-facility-123',
        ]);

        $this->math = Subject::create(['name' => 'Mathematics']);

        $this->grade = Grade::factory()->create(['name' => 'Grade 3']);

        $this->offering = Offering::factory()->create([
            'schoolyear_id' => $this->schoolyear->id,
            'grade_id' => $this->grade->id,
        ]);

        Enrollment::create([
            'user_id' => $this->student->id,
            'offering_id' => $this->offering->id,
        ]);

        DB::table('users')
            ->where('id', $this->student->id)
            ->update(['kolibri_user_id' => 'kolibri-student-abc']);
        $this->student->refresh();

        $this->currentWeek = SchoolCalendar::currentSchoolWeek();
    }

    // ---- Helpers ----

    protected function createSkillWithExercise(array $overrides = []): array
    {
        $skill = Skill::create(array_merge([
            'school_id' => $this->school->id,
            'subject_id' => $this->math->id,
            'grade_id' => $this->grade->id,
            'name' => 'Addition',
            'level' => 1,
        ], $overrides));

        $map = CurriculumMap::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->math->id,
            'kolibri_channel_id' => fake()->uuid(),
            'kolibri_node_id' => fake()->uuid(),
            'content_kind' => 'exercise',
        ]);

        DB::table('skill_content')->insert([
            'skill_id' => $skill->id,
            'curriculum_map_id' => $map->id,
            'role' => 'practice',
        ]);

        return [$skill, $map];
    }

    protected function mockKolibriClient(): KolibriClient
    {
        $mock = Mockery::mock(KolibriClient::class);
        $mock->shouldReceive('proxyRenderUrl')->andReturn('/kolibri-proxy/render/test')->byDefault();
        $mock->shouldReceive('openSession')->andReturn([
            'kolibri' => 'sid-test',
            'kolibri_csrftoken' => 'csrf-test',
        ])->byDefault();
        $mock->shouldReceive('getContentNode')->andReturn([
            'id' => 'node-123',
            'content_id' => 'content-abc',
        ])->byDefault();
        $mock->shouldReceive('fetchExerciseScore')->andReturn([
            'total_questions' => 5,
            'correct_answers' => 4,
            'wrong_answers' => 1,
            'score' => 80.0,
        ])->byDefault();

        $this->app->instance(KolibriClient::class, $mock);

        return $mock;
    }

    protected function mockKolibriProvisioner(): KolibriProvisioner
    {
        $mock = Mockery::mock(KolibriProvisioner::class);
        $mock->shouldReceive('provisionLearner')->andReturnUsing(function ($user, $facilityId) {
            DB::table('users')->where('id', $user->id)
                ->update(['kolibri_user_id' => 'provisioned-' . $user->id]);
        })->byDefault();
        $mock->shouldReceive('kolibriUsername')->andReturn('kubo_test')->byDefault();
        $mock->shouldReceive('kolibriPassword')->andReturn('test-password')->byDefault();

        $this->app->instance(KolibriProvisioner::class, $mock);

        return $mock;
    }

    protected function createActiveRun(Skill $skill, CurriculumMap $map, ?int $userId = null): ExerciseRun
    {
        return ExerciseRun::create([
            'user_id' => $userId ?? $this->student->id,
            'skill_id' => $skill->id,
            'curriculum_map_id' => $map->id,
            'status' => 'active',
            'mode' => 'free',
            'started_at' => now(),
        ]);
    }

    protected function createCompletedRun(Skill $skill, CurriculumMap $map, array $overrides = []): ExerciseRun
    {
        return ExerciseRun::create(array_merge([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'curriculum_map_id' => $map->id,
            'status' => 'completed',
            'mode' => 'free',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
            'total_questions' => 5,
            'correct_answers' => 4,
            'wrong_answers' => 1,
            'score' => 80.00,
        ], $overrides));
    }

    // ===================== 1. PROVISIONING =====================

    #[Test]
    public function test_student_without_kolibri_id_gets_provisioned()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $run = $this->createActiveRun($skill, $map);

        DB::table('users')->where('id', $this->student->id)
            ->update(['kolibri_user_id' => null]);
        $this->student->refresh();

        $provisioner = $this->mockKolibriProvisioner();
        $this->mockKolibriClient();

        $provisioner->shouldReceive('provisionLearner')
            ->once()
            ->with(
                Mockery::on(fn ($u) => $u->id === $this->student->id),
                'test-facility-123'
            )
            ->andReturnUsing(function ($user, $facilityId) {
                DB::table('users')->where('id', $user->id)
                    ->update(['kolibri_user_id' => 'provisioned-' . $user->id]);
            });

        $this->actingAs($this->student)
            ->get(route('learn.exercise', ['skill' => $skill, 'run' => $run->id]))
            ->assertOk();
    }

    #[Test]
    public function test_student_with_existing_kolibri_id_reuses_it()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $run = $this->createActiveRun($skill, $map);

        $provisioner = $this->mockKolibriProvisioner();
        $this->mockKolibriClient();

        $provisioner->shouldNotReceive('provisionLearner');

        $this->actingAs($this->student)
            ->get(route('learn.exercise', ['skill' => $skill, 'run' => $run->id]))
            ->assertOk();
    }

    #[Test]
    public function test_failed_kolibri_sign_in_shows_the_error_instead_of_a_blank_frame()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $run = $this->createActiveRun($skill, $map);

        $this->mockKolibriProvisioner();
        $client = $this->mockKolibriClient();
        $client->shouldReceive('openSession')->andReturn(null);

        $response = $this->actingAs($this->student)
            ->get(route('learn.exercise', ['skill' => $skill, 'run' => $run->id]))
            ->assertOk()
            ->assertSee('You could not be signed in to the exercise server')
            ->assertSee('var ssoReady = false;', false);

        // No Kolibri session cookie: the frame would open anonymous and stay blank.
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringStartsNotWith('kolibri', $cookie->getName());
        }
    }

    #[Test]
    public function test_successful_kolibri_sign_in_loads_the_frame()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $run = $this->createActiveRun($skill, $map);

        $this->mockKolibriProvisioner();
        $this->mockKolibriClient();

        $this->actingAs($this->student)
            ->get(route('learn.exercise', ['skill' => $skill, 'run' => $run->id]))
            ->assertOk()
            ->assertSee('var ssoReady = true;', false)
            ->assertDontSee('You could not be signed in');
    }

    // ===================== 2. SCORE TRACKING =====================

    #[Test]
    public function test_complete_run_fetches_scores_from_kolibri()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $map->update(['kolibri_content_id' => 'content-abc']);
        $run = $this->createActiveRun($skill, $map);

        $client = $this->mockKolibriClient();
        $client->shouldReceive('fetchExerciseScore')
            ->once()
            ->andReturn([
                'total_questions' => 5,
                'correct_answers' => 4,
                'wrong_answers' => 1,
                'score' => 80.0,
            ]);

        $this->actingAs($this->student)
            ->post(route('learn.completeRun', $skill), ['run_id' => $run->id])
            ->assertRedirect();

        $run->refresh();
        $this->assertEquals(5, $run->total_questions);
        $this->assertEquals(4, $run->correct_answers);
        $this->assertEquals(1, $run->wrong_answers);
        $this->assertEquals('completed', $run->status);
    }

    #[Test]
    public function test_completion_page_shows_correct_score()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $run = $this->createCompletedRun($skill, $map, [
            'correct_answers' => 4,
            'total_questions' => 5,
            'score' => 80.00,
        ]);

        $this->actingAs($this->student)
            ->get(route('learn.complete', ['skill' => $skill, 'run' => $run->id]))
            ->assertOk()
            ->assertSee('4/5 correct');
    }

    #[Test]
    public function test_graceful_degradation_when_kolibri_unreachable()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $map->update(['kolibri_content_id' => 'content-abc']);
        $run = $this->createActiveRun($skill, $map);

        $client = $this->mockKolibriClient();
        $client->shouldReceive('fetchExerciseScore')
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->actingAs($this->student)
            ->post(route('learn.completeRun', $skill), ['run_id' => $run->id])
            ->assertRedirect();

        $run->refresh();
        $this->assertEquals('score_pending', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertEquals(0, $run->total_questions);
    }

    #[Test]
    public function test_timestamp_filtering_passes_run_started_at()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $map->update(['kolibri_content_id' => 'content-abc']);

        $startedAt = now()->subMinutes(5);
        $run = ExerciseRun::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'curriculum_map_id' => $map->id,
            'status' => 'active',
            'mode' => 'free',
            'started_at' => $startedAt,
        ]);

        $client = $this->mockKolibriClient();
        $client->shouldReceive('fetchExerciseScore')
            ->once()
            ->with(
                'kolibri-student-abc',
                'content-abc',
                Mockery::on(fn ($since) =>
                    $since instanceof \DateTimeInterface
                    && $since->format('Y-m-d H:i:s') === $startedAt->format('Y-m-d H:i:s')
                )
            )
            ->andReturn([
                'total_questions' => 3,
                'correct_answers' => 2,
                'wrong_answers' => 1,
                'score' => 66.67,
            ]);

        $this->actingAs($this->student)
            ->post(route('learn.completeRun', $skill), ['run_id' => $run->id])
            ->assertRedirect();
    }

    // ===================== 3. CONTENT ID RESOLUTION =====================

    #[Test]
    public function test_resolve_content_id_calls_api_and_caches()
    {
        [, $map] = $this->createSkillWithExercise();
        $this->assertNull($map->kolibri_content_id);

        $client = Mockery::mock(KolibriClient::class);
        $client->shouldReceive('getContentNode')
            ->once()
            ->with($map->kolibri_node_id)
            ->andReturn(['id' => 'node-123', 'content_id' => 'resolved-content-id']);

        $result = $map->resolveContentId($client);

        $this->assertEquals('resolved-content-id', $result);
        $map->refresh();
        $this->assertEquals('resolved-content-id', $map->kolibri_content_id);
    }

    #[Test]
    public function test_resolve_content_id_returns_cached_value()
    {
        [, $map] = $this->createSkillWithExercise();
        $map->update(['kolibri_content_id' => 'already-cached']);

        $client = Mockery::mock(KolibriClient::class);
        $client->shouldNotReceive('getContentNode');

        $result = $map->resolveContentId($client);
        $this->assertEquals('already-cached', $result);
    }

    #[Test]
    public function test_resolve_content_id_failure_returns_null()
    {
        [, $map] = $this->createSkillWithExercise();

        $client = Mockery::mock(KolibriClient::class);
        $client->shouldReceive('getContentNode')
            ->once()
            ->andReturn(null);

        $result = $map->resolveContentId($client);

        $this->assertNull($result);
        $map->refresh();
        $this->assertNull($map->kolibri_content_id);
    }

    // ===================== 4. MASTERY PROGRESSION =====================

    #[Test]
    public function test_one_run_stays_in_progress()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $this->createCompletedRun($skill, $map, ['score' => 100.00]);

        $graph = new SkillGraph();
        $studentSkill = $graph->recordAttempt($this->student->id, $skill, 100.0);

        $this->assertEquals('in_progress', $studentSkill->status);
    }

    #[Test]
    public function test_two_runs_above_80_becomes_mastered()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        $this->createCompletedRun($skill, $map, [
            'score' => 90.00,
            'started_at' => now()->subMinutes(20),
            'completed_at' => now()->subMinutes(10),
        ]);
        $this->createCompletedRun($skill, $map, [
            'score' => 85.00,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $graph = new SkillGraph();
        $studentSkill = $graph->recordAttempt($this->student->id, $skill, 85.0);

        $this->assertEquals('mastered', $studentSkill->status);
    }

    #[Test]
    public function test_three_low_score_runs_stays_in_progress()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        for ($i = 1; $i <= 3; $i++) {
            $this->createCompletedRun($skill, $map, [
                'score' => 30.00,
                'started_at' => now()->subMinutes(40 - $i * 10),
                'completed_at' => now()->subMinutes(30 - $i * 10),
            ]);
        }

        $graph = new SkillGraph();
        $studentSkill = $graph->recordAttempt($this->student->id, $skill, 30.0, 10);

        $this->assertEquals('in_progress', $studentSkill->status);
    }

    #[Test]
    public function test_five_low_score_runs_becomes_struggling()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        for ($i = 1; $i <= 5; $i++) {
            $this->createCompletedRun($skill, $map, [
                'score' => 30.00,
                'started_at' => now()->subMinutes(60 - $i * 10),
                'completed_at' => now()->subMinutes(50 - $i * 10),
            ]);
        }

        $graph = new SkillGraph();
        $studentSkill = $graph->recordAttempt($this->student->id, $skill, 30.0, 10);

        $this->assertEquals('struggling', $studentSkill->status);
        $this->assertNotNull($studentSkill->next_review_week);
        $this->assertEquals(12, $studentSkill->next_review_week); // week 10 + 2
        $this->assertEquals(0, $studentSkill->review_interval_index);
    }

    #[Test]
    public function test_struggling_skill_unlocks_dependents()
    {
        [$skill1] = $this->createSkillWithExercise(['name' => 'Skill A', 'level' => 1]);
        [$skill2] = $this->createSkillWithExercise(['name' => 'Skill B', 'level' => 2]);

        DB::table('skill_edges')->insert([
            'skill_id' => $skill2->id,
            'prerequisite_id' => $skill1->id,
        ]);

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill1->id,
            'status' => 'struggling',
            'mastery' => 40.0,
            'attempts' => 5,
            'next_review_week' => 12,
            'review_interval_index' => 0,
        ]);

        $graph = new SkillGraph();
        $ready = $graph->readySkills($this->student->id, $this->math->id, $this->grade->id);

        $this->assertTrue($ready->contains('id', $skill2->id));
        $this->assertFalse($ready->contains('id', $skill1->id));
    }

    #[Test]
    public function test_mastered_skill_excluded_from_frontier()
    {
        [$skill1] = $this->createSkillWithExercise(['name' => 'Skill A', 'level' => 1]);
        [$skill2] = $this->createSkillWithExercise(['name' => 'Skill B', 'level' => 2]);

        DB::table('skill_edges')->insert([
            'skill_id' => $skill2->id,
            'prerequisite_id' => $skill1->id,
        ]);

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill1->id,
            'status' => 'mastered',
            'mastery' => 95.0,
            'attempts' => 3,
            'mastered_at' => now(),
        ]);

        $graph = new SkillGraph();
        $diagnosis = $graph->diagnose($this->student->id, $this->math->id, $this->grade->id);

        $this->assertTrue($diagnosis['mastered']->contains('id', $skill1->id));
        $this->assertFalse($diagnosis['frontier']->contains('id', $skill1->id));
        $this->assertTrue($diagnosis['frontier']->contains('id', $skill2->id));
    }

    // ===================== 5. EXERCISE FLOW =====================

    #[Test]
    public function test_start_run_creates_active_run()
    {
        [$skill] = $this->createSkillWithExercise();

        $this->actingAs($this->student)
            ->post(route('learn.start', $skill))
            ->assertRedirect();

        $this->assertDatabaseHas('exercise_runs', [
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'status' => 'active',
            'mode' => 'free',
        ]);
    }

    #[Test]
    public function test_start_run_abandons_previous_active_runs()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $oldRun = $this->createActiveRun($skill, $map);

        $this->actingAs($this->student)
            ->post(route('learn.start', $skill))
            ->assertRedirect();

        $oldRun->refresh();
        $this->assertEquals('abandoned', $oldRun->status);

        $newRun = ExerciseRun::where('user_id', $this->student->id)
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($newRun);
        $this->assertNotEquals($oldRun->id, $newRun->id);
    }

    #[Test]
    public function test_start_run_homework_validates_assignment()
    {
        [$skill] = $this->createSkillWithExercise();

        $assignment = LessonAssignment::create([
            'offering_id' => $this->offering->id,
            'skill_id' => $skill->id,
            'week_number' => $this->currentWeek,
            'assigned_by' => $this->teacher->id,
        ]);

        $this->actingAs($this->student)
            ->post(route('learn.start', $skill), ['mode' => 'homework'])
            ->assertRedirect();

        $this->assertDatabaseHas('exercise_runs', [
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'mode' => 'homework',
            'lesson_assignment_id' => $assignment->id,
        ]);
    }

    #[Test]
    public function test_start_run_homework_downgrades_without_assignment()
    {
        [$skill] = $this->createSkillWithExercise();

        $this->actingAs($this->student)
            ->post(route('learn.start', $skill), ['mode' => 'homework'])
            ->assertRedirect();

        $this->assertDatabaseHas('exercise_runs', [
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'mode' => 'free',
            'lesson_assignment_id' => null,
        ]);
    }

    #[Test]
    public function test_complete_run_sets_completed_status()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $map->update(['kolibri_content_id' => 'content-abc']);
        $run = $this->createActiveRun($skill, $map);

        $this->mockKolibriClient();

        $this->actingAs($this->student)
            ->post(route('learn.completeRun', $skill), ['run_id' => $run->id])
            ->assertRedirect();

        $run->refresh();
        $this->assertEquals('completed', $run->status);
        $this->assertNotNull($run->completed_at);
    }

    // ===================== 6. LEARN INDEX =====================

    #[Test]
    public function test_up_next_shows_first_unmastered_frontier_skill()
    {
        $this->createSkillWithExercise(['name' => 'Counting', 'level' => 1]);
        $this->createSkillWithExercise(['name' => 'Addition', 'level' => 2]);

        $this->actingAs($this->student)
            ->get(route('learn.index'))
            ->assertOk()
            ->assertSee('Counting');
    }

    #[Test]
    public function test_grade_fallback_excludes_mastered_skills()
    {
        [$skill1] = $this->createSkillWithExercise(['name' => 'Counting', 'level' => 1]);
        $this->createSkillWithExercise(['name' => 'Addition', 'level' => 2]);

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill1->id,
            'status' => 'mastered',
            'mastery' => 95.0,
            'attempts' => 3,
            'mastered_at' => now(),
        ]);

        $this->actingAs($this->student)
            ->get(route('learn.index'))
            ->assertOk()
            ->assertSee('Addition');
    }

    #[Test]
    public function test_mastered_skill_still_browseable()
    {
        [$skill1] = $this->createSkillWithExercise(['name' => 'Counting', 'level' => 1]);

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill1->id,
            'status' => 'mastered',
            'mastery' => 95.0,
            'attempts' => 3,
            'mastered_at' => now(),
        ]);

        $this->actingAs($this->student)
            ->get(route('learn.index'))
            ->assertOk()
            ->assertSee('Counting');
    }

    #[Test]
    public function test_assigned_skill_shows_as_lesson()
    {
        [$skill] = $this->createSkillWithExercise(['name' => 'Multiplication']);

        LessonAssignment::create([
            'offering_id' => $this->offering->id,
            'skill_id' => $skill->id,
            'week_number' => $this->currentWeek,
            'assigned_by' => $this->teacher->id,
        ]);

        $this->actingAs($this->student)
            ->get(route('learn.index'))
            ->assertOk()
            ->assertSee("Homework", false);
    }

    // ===================== 7. MULTI-STUDENT ISOLATION =====================

    #[Test]
    public function test_different_students_get_different_scores()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        $student2 = User::create([
            'first_name' => 'Other',
            'last_name' => 'Student',
            'email' => 'other@test.com',
            'password' => bcrypt('secret'),
        ]);
        $student2->assignRole('student');
        Enrollment::create([
            'user_id' => $student2->id,
            'offering_id' => $this->offering->id,
        ]);

        $run1 = $this->createCompletedRun($skill, $map, [
            'correct_answers' => 4,
            'total_questions' => 5,
            'score' => 80.00,
        ]);

        $run2 = $this->createCompletedRun($skill, $map, [
            'user_id' => $student2->id,
            'correct_answers' => 2,
            'total_questions' => 5,
            'score' => 40.00,
        ]);

        $this->actingAs($this->student)
            ->get(route('learn.complete', ['skill' => $skill, 'run' => $run1->id]))
            ->assertSee('4/5 correct');

        $this->actingAs($student2)
            ->get(route('learn.complete', ['skill' => $skill, 'run' => $run2->id]))
            ->assertSee('2/5 correct');
    }

    // ===================== 8. EDGE CASES =====================

    #[Test]
    public function test_exercise_redirects_when_no_active_run()
    {
        [$skill] = $this->createSkillWithExercise();

        $this->mockKolibriClient();
        $this->mockKolibriProvisioner();

        $this->actingAs($this->student)
            ->get(route('learn.exercise', ['skill' => $skill, 'run' => 99999]))
            ->assertRedirect(route('learn.skill', $skill));
    }

    #[Test]
    public function test_start_run_fails_when_no_practice_content()
    {
        $skill = Skill::create([
            'school_id' => $this->school->id,
            'subject_id' => $this->math->id,
            'grade_id' => $this->grade->id,
            'name' => 'No Content Skill',
            'level' => 1,
        ]);

        $this->actingAs($this->student)
            ->post(route('learn.start', $skill))
            ->assertRedirect()
            ->assertSessionHas('error', 'No exercise available for this skill.');
    }

    // ===================== 9. SPACED REPETITION =====================

    #[Test]
    public function test_mastery_schedules_first_review()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        $this->createCompletedRun($skill, $map, [
            'score' => 90.00,
            'started_at' => now()->subMinutes(20),
            'completed_at' => now()->subMinutes(10),
        ]);
        $this->createCompletedRun($skill, $map, [
            'score' => 85.00,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $graph = new SkillGraph();
        $studentSkill = $graph->recordAttempt($this->student->id, $skill, 85.0, 10);

        $this->assertEquals('mastered', $studentSkill->status);
        $this->assertEquals(11, $studentSkill->next_review_week); // week 10 + 1
        $this->assertEquals(0, $studentSkill->review_interval_index);
    }

    #[Test]
    public function test_successful_review_advances_interval()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        // Set up mastered skill at interval 0, review due
        $studentSkill = StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'status' => 'mastered',
            'mastery' => 90.0,
            'attempts' => 3,
            'mastered_at' => now()->subWeeks(2),
            'next_review_week' => 10,
            'review_interval_index' => 0,
        ]);

        // Create a high-scoring run for the review
        $this->createCompletedRun($skill, $map, [
            'score' => 85.00,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $graph = new SkillGraph();
        $result = $graph->recordAttempt($this->student->id, $skill, 85.0, 11);

        $this->assertEquals('mastered', $result->status);
        $this->assertEquals(1, $result->review_interval_index);
        // Next review: week 11 + MASTERED_REVIEW_WEEKS[1] = 11 + 3 = 14
        $this->assertEquals(14, $result->next_review_week);
    }

    #[Test]
    public function test_failed_review_resets_interval()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        // Set up mastered skill at interval 2, review due
        $studentSkill = StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'status' => 'mastered',
            'mastery' => 85.0,
            'attempts' => 5,
            'mastered_at' => now()->subWeeks(8),
            'next_review_week' => 15,
            'review_interval_index' => 2,
        ]);

        // Create a low-scoring run for the review
        $this->createCompletedRun($skill, $map, [
            'score' => 40.00,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $graph = new SkillGraph();
        $result = $graph->recordAttempt($this->student->id, $skill, 40.0, 16);

        $this->assertEquals('mastered', $result->status); // stays mastered
        $this->assertEquals(0, $result->review_interval_index); // reset
        // Next review: week 16 + MASTERED_REVIEW_WEEKS[0] = 16 + 1 = 17
        $this->assertEquals(17, $result->next_review_week);
    }

    #[Test]
    public function test_struggling_becomes_mastered_on_good_review()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        $studentSkill = StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'status' => 'struggling',
            'mastery' => 50.0,
            'attempts' => 6,
            'next_review_week' => 12,
            'review_interval_index' => 0,
        ]);

        // Create a high-scoring run
        $this->createCompletedRun($skill, $map, [
            'score' => 90.00,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $graph = new SkillGraph();
        $result = $graph->recordAttempt($this->student->id, $skill, 90.0, 13);

        $this->assertEquals('mastered', $result->status);
        $this->assertNotNull($result->mastered_at);
        // Advances using MASTERED_REVIEW_WEEKS after status change
        $this->assertEquals(1, $result->review_interval_index);
        $this->assertEquals(16, $result->next_review_week); // 13 + 3
    }

    #[Test]
    public function test_graduated_review_clears_next_review_week()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        // Set up mastered skill at last interval
        $studentSkill = StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'status' => 'mastered',
            'mastery' => 95.0,
            'attempts' => 8,
            'mastered_at' => now()->subWeeks(16),
            'next_review_week' => 30,
            'review_interval_index' => 3, // last index in MASTERED_REVIEW_WEEKS
        ]);

        $this->createCompletedRun($skill, $map, [
            'score' => 90.00,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $graph = new SkillGraph();
        $result = $graph->recordAttempt($this->student->id, $skill, 90.0, 31);

        $this->assertEquals('mastered', $result->status);
        $this->assertNull($result->next_review_week); // graduated
    }

    #[Test]
    public function test_review_due_skill_returns_struggling_first()
    {
        [$skill1] = $this->createSkillWithExercise(['name' => 'Mastered Review', 'level' => 1]);
        [$skill2] = $this->createSkillWithExercise(['name' => 'Struggling Review', 'level' => 2]);

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill1->id,
            'status' => 'mastered',
            'mastery' => 90.0,
            'attempts' => 3,
            'mastered_at' => now()->subWeeks(2),
            'next_review_week' => 10,
            'review_interval_index' => 0,
        ]);

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill2->id,
            'status' => 'struggling',
            'mastery' => 40.0,
            'attempts' => 5,
            'next_review_week' => 11,
            'review_interval_index' => 0,
        ]);

        $graph = new SkillGraph();
        $review = $graph->reviewDueSkill($this->student->id, 12);

        $this->assertNotNull($review);
        $this->assertEquals($skill2->id, $review->id);
    }

    #[Test]
    public function test_struggling_skill_does_not_count_toward_mastery_percentage()
    {
        [$skill1] = $this->createSkillWithExercise(['name' => 'Mastered', 'level' => 1]);
        [$skill2] = $this->createSkillWithExercise(['name' => 'Struggling', 'level' => 2]);
        $this->createSkillWithExercise(['name' => 'Not started', 'level' => 3]);

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill1->id,
            'status' => 'mastered',
            'mastery' => 90.0,
            'attempts' => 3,
            'mastered_at' => now(),
        ]);

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill2->id,
            'status' => 'struggling',
            'mastery' => 40.0,
            'attempts' => 5,
        ]);

        $graph = new SkillGraph();
        $diagnosis = $graph->diagnose($this->student->id, $this->math->id, $this->grade->id);

        // Only 1 of 3 mastered = 33%
        $this->assertEquals(33, $diagnosis['mastery_percentage']);
    }

    // ===================== 10. SKILL GRAPH =====================

    #[Test]
    public function test_find_starting_point_with_cycle_does_not_infinite_loop()
    {
        [$skillA] = $this->createSkillWithExercise(['name' => 'Cycle A', 'level' => 1]);
        [$skillB] = $this->createSkillWithExercise(['name' => 'Cycle B', 'level' => 2]);

        // Create a cycle: A is prerequisite of B, B is prerequisite of A
        DB::table('skill_edges')->insert([
            ['skill_id' => $skillB->id, 'prerequisite_id' => $skillA->id],
            ['skill_id' => $skillA->id, 'prerequisite_id' => $skillB->id],
        ]);

        $graph = new SkillGraph();

        // Should return a skill without stack overflow
        $result = $graph->findStartingPoint($this->student->id, $skillB);

        $this->assertInstanceOf(Skill::class, $result);
    }

    #[Test]
    public function test_find_starting_point_walks_to_deepest_unmastered()
    {
        [$skillA] = $this->createSkillWithExercise(['name' => 'Basics', 'level' => 1]);
        [$skillB] = $this->createSkillWithExercise(['name' => 'Intermediate', 'level' => 2]);
        [$skillC] = $this->createSkillWithExercise(['name' => 'Advanced', 'level' => 3]);

        // A → B → C (A is prereq of B, B is prereq of C)
        DB::table('skill_edges')->insert([
            ['skill_id' => $skillB->id, 'prerequisite_id' => $skillA->id],
            ['skill_id' => $skillC->id, 'prerequisite_id' => $skillB->id],
        ]);

        // Student has mastered A only
        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skillA->id,
            'status' => 'mastered',
            'mastery' => 90.0,
            'attempts' => 3,
            'mastered_at' => now(),
        ]);

        $graph = new SkillGraph();
        $result = $graph->findStartingPoint($this->student->id, $skillC);

        $this->assertEquals($skillB->id, $result->id);
    }

    #[Test]
    public function test_find_starting_point_returns_target_when_all_mastered()
    {
        [$skillA] = $this->createSkillWithExercise(['name' => 'Prereq', 'level' => 1]);
        [$skillB] = $this->createSkillWithExercise(['name' => 'Target', 'level' => 2]);

        DB::table('skill_edges')->insert([
            'skill_id' => $skillB->id,
            'prerequisite_id' => $skillA->id,
        ]);

        // Both mastered
        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skillA->id,
            'status' => 'mastered',
            'mastery' => 90.0,
            'attempts' => 3,
            'mastered_at' => now(),
        ]);
        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skillB->id,
            'status' => 'mastered',
            'mastery' => 85.0,
            'attempts' => 2,
            'mastered_at' => now(),
        ]);

        $graph = new SkillGraph();
        $result = $graph->findStartingPoint($this->student->id, $skillB);

        $this->assertEquals($skillB->id, $result->id);
    }

    #[Test]
    public function test_record_attempt_weighted_average_calculation()
    {
        [$skill, $map] = $this->createSkillWithExercise(['name' => 'Weighted Avg']);

        // Create 5 completed runs with different scores (oldest to newest)
        $scores = [60, 70, 80, 90, 100];
        foreach ($scores as $i => $score) {
            $this->createCompletedRun($skill, $map, [
                'score' => (float) $score,
                'started_at' => now()->subMinutes(50 - $i * 10),
                'completed_at' => now()->subMinutes(40 - $i * 10),
            ]);
        }

        $graph = new SkillGraph();
        $studentSkill = $graph->recordAttempt($this->student->id, $skill, 100.0);

        // Runs are ordered latest first: [100, 90, 80, 70, 60]
        // Weights: [0.40, 0.25, 0.15, 0.12, 0.08]
        // Weighted sum: 100*0.40 + 90*0.25 + 80*0.15 + 70*0.12 + 60*0.08
        //             = 40 + 22.5 + 12 + 8.4 + 4.8 = 87.7
        // Total weight: 0.40 + 0.25 + 0.15 + 0.12 + 0.08 = 1.0
        // Mastery: 87.7 / 1.0 = 87.7
        $this->assertEquals(87.7, $studentSkill->mastery);
    }

    #[Test]
    public function test_record_attempt_fallback_without_runs()
    {
        [$skill] = $this->createSkillWithExercise(['name' => 'No Runs']);

        // No ExerciseRun records — call recordAttempt with score 70
        $graph = new SkillGraph();
        $studentSkill = $graph->recordAttempt($this->student->id, $skill, 70.0);

        // Fallback: (0 * 0.3) + (70 * 0.7) = 49
        $this->assertEquals(49.0, $studentSkill->mastery);
        $this->assertEquals('in_progress', $studentSkill->status);
    }

    // ===================== 11. MISSING COVERAGE =====================

    #[Test]
    public function test_complete_run_abandons_when_no_questions_and_no_kolibri_error()
    {
        [$skill, $map] = $this->createSkillWithExercise();
        $map->update(['kolibri_content_id' => 'content-abc']);
        $run = $this->createActiveRun($skill, $map);

        $client = $this->mockKolibriClient();
        $client->shouldReceive('fetchExerciseScore')
            ->once()
            ->andReturn([
                'total_questions' => 0,
                'correct_answers' => 0,
                'wrong_answers' => 0,
                'score' => 0.0,
            ]);
        $client->shouldReceive('getProgressForUser')
            ->andReturn(collect([]));

        $this->actingAs($this->student)
            ->post(route('learn.completeRun', $skill), ['run_id' => $run->id])
            ->assertRedirect();

        $run->refresh();
        $this->assertEquals('abandoned', $run->status);
    }

    #[Test]
    public function test_struggling_review_with_low_score_reschedules()
    {
        [$skill, $map] = $this->createSkillWithExercise();

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'status' => 'struggling',
            'mastery' => 40.0,
            'attempts' => 5,
            'next_review_week' => 10,
            'review_interval_index' => 0,
        ]);

        $this->createCompletedRun($skill, $map, [
            'score' => 40.00,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $graph = new SkillGraph();
        $result = $graph->recordAttempt($this->student->id, $skill, 40.0, 11);

        $this->assertEquals('struggling', $result->status);
        $this->assertEquals(0, $result->review_interval_index);
        // Next review: week 11 + STRUGGLING_REVIEW_WEEKS[0] = 11 + 2 = 13
        $this->assertEquals(13, $result->next_review_week);
    }

    #[Test]
    public function test_review_due_skill_returns_null_when_no_reviews_due()
    {
        [$skill] = $this->createSkillWithExercise();

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'status' => 'mastered',
            'mastery' => 90.0,
            'attempts' => 3,
            'mastered_at' => now(),
            'next_review_week' => 20,
            'review_interval_index' => 0,
        ]);

        $graph = new SkillGraph();
        $review = $graph->reviewDueSkill($this->student->id, 10);

        $this->assertNull($review);
    }

    // ===================== 12. EXERCISE CREATOR & ASSIGNMENTS =====================

    #[Test]
    public function test_exercise_creator_saves_exercise_with_questions()
    {
        [$skill] = $this->createSkillWithExercise(['name' => 'Creator Skill']);

        $this->mock(ChannelGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andReturn(['exercises' => 1]);
        });

        $this->actingAs($this->teacher);

        Livewire::test(ExerciseCreator::class)
            ->call('selectSkill', $skill->id)
            ->set('questionText', 'Is 1+1 equal to 2?')
            ->set('questionType', 'radio')
            ->set('choices', ['Yes', 'No', 'Maybe'])
            ->set('correctChoice', 0)
            ->call('nextQuestion')
            ->set('questionText', 'Is 2+2 equal to 5?')
            ->set('questionType', 'radio')
            ->set('choices', ['Yes', 'No', 'Maybe'])
            ->set('correctChoice', 1)
            ->call('goToReview')
            ->call('save');

        $exercise = AuthoredExercise::where('skill_id', $skill->id)->first();
        $this->assertNotNull($exercise);
        $this->assertEquals(2, AuthoredQuestion::where('authored_exercise_id', $exercise->id)->count());
    }

    #[Test]
    public function test_exercise_creator_requires_two_questions_for_review()
    {
        [$skill] = $this->createSkillWithExercise(['name' => 'Review Gate Skill']);

        $this->actingAs($this->teacher);

        Livewire::test(ExerciseCreator::class)
            ->call('selectSkill', $skill->id)
            ->set('questionText', 'Only one question?')
            ->set('questionType', 'radio')
            ->set('choices', ['Yes', 'No', 'Maybe'])
            ->set('correctChoice', 0)
            ->call('goToReview')
            ->assertSet('formstep', 1) // did NOT advance to review step
            ->assertSee('at least 2');
    }

    #[Test]
    public function test_exercise_creator_loads_existing_exercise()
    {
        [$skill] = $this->createSkillWithExercise(['name' => 'Load Skill']);

        $exercise = AuthoredExercise::create([
            'school_id' => $this->school->id,
            'title' => 'Existing Exercise',
            'subject_id' => $this->math->id,
            'skill_id' => $skill->id,
            'mastery_m' => 3,
            'mastery_n' => 5,
            'randomize' => true,
            'created_by' => $this->teacher->id,
        ]);

        AuthoredQuestion::create([
            'authored_exercise_id' => $exercise->id,
            'sort_order' => 0,
            'question_text' => 'Q1?',
            'question_type' => 'radio',
            'choices' => [
                ['text' => 'A', 'correct' => true],
                ['text' => 'B', 'correct' => false],
            ],
        ]);

        AuthoredQuestion::create([
            'authored_exercise_id' => $exercise->id,
            'sort_order' => 1,
            'question_text' => 'Q2?',
            'question_type' => 'radio',
            'choices' => [
                ['text' => 'X', 'correct' => false],
                ['text' => 'Y', 'correct' => true],
            ],
        ]);

        $this->actingAs($this->teacher);

        Livewire::test(ExerciseCreator::class, ['exerciseId' => $exercise->id])
            ->assertSet('exerciseTitle', 'Existing Exercise')
            ->assertCount('questions', 2);
    }

    #[Test]
    public function test_lesson_assignment_is_for_student_whole_class()
    {
        [$skill] = $this->createSkillWithExercise();

        $assignment = LessonAssignment::create([
            'offering_id' => $this->offering->id,
            'skill_id' => $skill->id,
            'week_number' => $this->currentWeek,
            'assigned_by' => $this->teacher->id,
        ]);

        // No entries in lesson_assignment_students — whole class
        $this->assertTrue($assignment->isForStudent($this->student->id));
        $this->assertTrue($assignment->isForStudent(99999)); // any ID
    }

    #[Test]
    public function test_lesson_assignment_is_for_student_targeted()
    {
        [$skill] = $this->createSkillWithExercise();

        $assignment = LessonAssignment::create([
            'offering_id' => $this->offering->id,
            'skill_id' => $skill->id,
            'week_number' => $this->currentWeek,
            'assigned_by' => $this->teacher->id,
        ]);

        // Target only this student
        DB::table('lesson_assignment_students')->insert([
            'lesson_assignment_id' => $assignment->id,
            'user_id' => $this->student->id,
        ]);

        $this->assertTrue($assignment->isForStudent($this->student->id));

        // A different student should not be targeted
        $otherStudent = User::create([
            'first_name' => 'Other',
            'last_name' => 'Learner',
            'email' => 'other-learner@test.com',
            'password' => bcrypt('secret'),
        ]);
        $otherStudent->assignRole('student');

        $this->assertFalse($assignment->isForStudent($otherStudent->id));
    }

    #[Test]
    public function test_assigned_skill_respects_student_targeting()
    {
        [$skill] = $this->createSkillWithExercise(['name' => 'Targeted Skill']);

        $studentA = $this->student;

        $studentB = User::create([
            'first_name' => 'Student',
            'last_name' => 'B',
            'email' => 'studentb@test.com',
            'password' => bcrypt('secret'),
        ]);
        $studentB->assignRole('student');
        Enrollment::create([
            'user_id' => $studentB->id,
            'offering_id' => $this->offering->id,
        ]);

        $assignment = LessonAssignment::create([
            'offering_id' => $this->offering->id,
            'skill_id' => $skill->id,
            'week_number' => $this->currentWeek,
            'assigned_by' => $this->teacher->id,
        ]);

        // Target only student A
        DB::table('lesson_assignment_students')->insert([
            'lesson_assignment_id' => $assignment->id,
            'user_id' => $studentA->id,
        ]);

        $graph = new SkillGraph();

        $resultA = $graph->assignedSkillForStudent($studentA->id, $this->offering->id, $this->currentWeek);
        $this->assertNotNull($resultA);
        $this->assertEquals($skill->id, $resultA->id);

        $resultB = $graph->assignedSkillForStudent($studentB->id, $this->offering->id, $this->currentWeek);
        $this->assertNull($resultB);
    }

    // ===================== 13. PROGRESS & CONTENT BROWSER =====================

    #[Test]
    public function test_progress_index_shows_teacher_offerings()
    {
        DB::table('teacher_offering')->insert([
            'user_id' => $this->teacher->id,
            'offering_id' => $this->offering->id,
        ]);

        $this->actingAs($this->teacher)
            ->get(route('progress.index'))
            ->assertOk()
            ->assertSee($this->grade->name);
    }

    #[Test]
    public function test_progress_show_displays_student_data()
    {
        DB::table('teacher_offering')->insert([
            'user_id' => $this->teacher->id,
            'offering_id' => $this->offering->id,
        ]);

        [$skill, $map] = $this->createSkillWithExercise();

        StudentSkill::create([
            'user_id' => $this->student->id,
            'skill_id' => $skill->id,
            'status' => 'mastered',
            'mastery' => 90.0,
            'attempts' => 3,
            'mastered_at' => now(),
        ]);

        $this->createCompletedRun($skill, $map);

        $this->actingAs($this->teacher)
            ->get(route('progress.show', $this->offering))
            ->assertOk()
            ->assertSee($this->student->first_name);
    }

    #[Test]
    public function test_student_cannot_access_progress()
    {
        $this->actingAs($this->student)
            ->get(route('progress.index'))
            ->assertForbidden();
    }

    // The exercise & video mapper (content.index) is now a Livewire admin workspace,
    // covered by CurriculumMapperTest. Only the access rule is asserted here.
    #[Test]
    public function test_student_cannot_access_content_browser()
    {
        $this->actingAs($this->student)
            ->get(route('content.index'))
            ->assertForbidden();
    }
}
