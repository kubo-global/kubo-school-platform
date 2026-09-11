<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\NatConfig;
use App\Models\Offering;
use App\Models\Period;
use App\Models\Profile;
use App\Models\School;
use App\Models\Schoolyear;
use App\Models\StaffProfile;
use App\Models\StaffStatus;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Comprehensive demo data: three school years of a Gambian lower-basic school,
 * with prod-like names, multiple classes (sections) in some grades, Test/Exam
 * scores per subject/term, and National Assessment Test (NAT) results for the
 * grades that sit it (Grade 3 & Grade 5).
 *
 * Self-contained and idempotent-ish: it firstOrCreates the school, roles,
 * assessment types and grading scales, so it can run standalone or after the
 * structural seeders. Reproducible — the RNG is seeded.
 *
 * Names come from database/seeders/data/gambian_names.php (mined from the real
 * roster). NAT applies to Grade 3 and Grade 5 (see $natSubjects).
 */
class DemoSeeder extends Seeder
{
    /**
     * School years to build: label => [startYear, endYear]. Last entry is "current".
     * Derived from today in run(): three years ending in the one that spans today.
     * The list was hardcoded up to 2025 - 2026, so from 1 September 2026 nothing
     * spanned today, `kolibri:provision` refused ("No current schoolyear") after every
     * nightly reset, and no pupil on the demo could open an exercise.
     */
    private array $years = [];

    /** The day the demo pretends it is: today. Dates in the current year are seeded up to here. */
    private Carbon $today;

    /** Grades in order; value = sections. null section => single class ("Grade 1"). */
    private array $gradeSections = [
        'Nursery 1' => [null],
        'Nursery 2' => [null],
        'Nursery 3' => [null],
        'Grade 1'   => ['A', 'B'],   // multiple classes per grade
        'Grade 2'   => [null],
        'Grade 3'   => ['A', 'B'],   // multiple classes (and a NAT grade)
        'Grade 4'   => [null],
        'Grade 5'   => [null],
        'Grade 6'   => [null],
    ];

    private array $nurserySubjects = ['Phonics', 'Reading', 'Numeracy', 'Health', 'Physical Education', 'Art and craft'];
    private array $primarySubjects = ['English language', 'Mathematics', 'Integrated studies', 'Science', 'Social & Environmental Studies', 'French', 'Religious Knowledge', 'Physical Education'];

    /** Which subjects each NAT grade sits. */
    private array $natSubjects = [
        'Grade 3' => ['English language', 'Mathematics', 'Integrated studies'],
        'Grade 5' => ['English language', 'Mathematics', 'Science', 'Social & Environmental Studies'],
    ];

    private array $names;
    private School $school;
    private array $subjectIds = [];      // name => id
    private array $assessmentTypes = []; // name => model
    private array $teachers = [];        // pool of teacher user ids
    private array $periods = [];         // non-break Period models, in order
    private array $scoreRows = [];       // buffered for bulk insert
    private array $currentStudentIds = []; // current-year pupils (for health records)
    private ?int $caregiverId = null;
    private ?int $demoTeacherId = null;  // the role picker's Teacher (grade teacher persona)
    private string $pw;                    // demo password hash — computed once, reused for every account

    public function run(): void
    {
        mt_srand(20260627);
        $this->today = Carbon::today();
        $this->years = $this->schoolyearsUpToToday(3);
        $this->names = require database_path('seeders/data/gambian_names.php');
        // Demo accounts all share the password "secret". Hash it once at a low cost
        // factor and reuse the string — hashing 500+ users at the default rounds was
        // the bulk of the seed time. Demo-only; production hashing is untouched.
        $this->pw = bcrypt('secret', ['rounds' => 4]);

        $this->command->info('Demo: base structure…');
        // An invented school. The demo is public: it must not carry a real school's
        // name, nor its real staff (see the staff names below).
        $this->school = School::firstOrCreate(
            ['name' => 'Sunrise Lower Basic School'],
            ['motto' => 'Empowering quality education for all', 'timezone' => 'Africa/Banjul']
        );
        $this->ensureRoles();
        $this->ensureAssessmentTypes();
        $this->ensureGradingScales();
        $this->ensureSubjects();
        $this->ensurePeriods();
        $this->makeStaff();

        $gradeModels = $this->ensureGrades();

        foreach ($this->years as $label => [$startY, $endY]) {
            $isCurrent = $label === array_key_last($this->years);
            $this->command->info("Demo: school year {$label}…");
            $year = Schoolyear::firstOrCreate(
                ['school_id' => $this->school->id, 'name' => $label],
                ['start' => "{$startY}-09-01", 'end' => "{$endY}-08-31"]
            );
            $terms = $this->makeTerms($year, $startY, $endY);
            $this->makeNatConfig($year, $gradeModels);

            foreach ($this->gradeSections as $gradeName => $sections) {
                $grade = $gradeModels[$gradeName];
                $isNursery = str_starts_with($gradeName, 'Nursery');
                $subjects = $isNursery ? $this->nurserySubjects : $this->primarySubjects;

                foreach ($sections as $section) {
                    $offering = Offering::firstOrCreate(
                        ['schoolyear_id' => $year->id, 'grade_id' => $grade->id, 'name' => $section],
                        ['activated' => 1]
                    );
                    $this->attachTeachers($offering, $subjects);
                    if ($isCurrent && in_array($gradeName, ['Grade 1', 'Grade 4'], true) && $section === $sections[0]) {
                        $this->makeDemoTeacherPrincipal($offering);
                    }
                    $students = $this->makeStudents($offering, mt_rand(16, 24));
                    $this->linkSubjects($offering, $subjects, $terms);
                    $this->makeScores($offering, $subjects, $terms, $students, $isCurrent, $endY);

                    if (isset($this->natSubjects[$gradeName])) {
                        $this->makeNatScores($offering, $gradeName, $students, $endY);
                    }
                    if ($isCurrent) {
                        $this->makeTimetable($offering, $subjects);
                        $this->makeAttendance($offering, $students, $endY);
                        $this->makeInstructionalHours($offering, $endY);
                        $this->currentStudentIds = array_merge($this->currentStudentIds, $students);
                    }
                }
            }
        }

        $this->flushScores();
        $this->command->info('Demo: health records…');
        $this->seedHealth();
        $this->command->info('Demo: academic config (subjects, grade key, hours)…');
        $this->call(SwallowConfigSeeder::class);

        // Learn: the skill graph and the Kolibri curriculum mapping. Pure DB, so it
        // seeds anywhere; launching an exercise additionally needs a Kolibri server
        // with these nodes imported (the demo box has them).
        $this->command->info('Demo: exercises and skills (Kolibri mapping)…');
        $this->call(SwallowExercisesSeeder::class);

        $this->command->info('Demo: done.');
    }

    private function ensureRoles(): void
    {
        if (! class_exists(\Spatie\Permission\Models\Role::class)) {
            return;
        }
        foreach (['admin', 'headmaster', 'teacher', 'student', 'caregiver', 'assistant_coordinator'] as $r) {
            \Spatie\Permission\Models\Role::findOrCreate($r, 'web');
        }
    }

    private function ensureAssessmentTypes(): void
    {
        $defs = [
            ['Test', 0.25, 1],
            ['Exam', 0.75, 2],
            ['National Assessment Test', 0.0, 99],
        ];
        foreach ($defs as [$name, $weight, $order]) {
            $this->assessmentTypes[$name] = AssessmentType::firstOrCreate(
                ['school_id' => $this->school->id, 'name' => $name],
                ['weight' => $weight, 'display_order' => $order]
            );
        }
    }

    private function ensureGradingScales(): void
    {
        // The Gambian lower-basic scheme (grade 1 = best): 1/4/5/6/8/9, with x = absent
        // handled separately on the report book. Schools can edit this in Settings.
        $bands = [
            ['1', 80, 100, 'Excellent', 1], ['4', 70, 79.99, 'Very Good', 2],
            ['5', 60, 69.99, 'Good', 3], ['6', 50, 59.99, 'Average', 4],
            ['8', 40, 49.99, 'Pass', 5], ['9', 0, 39.99, 'Fail', 6],
        ];
        // Replace any prior term bands so a reseed can't leave a stale label behind.
        DB::table('grading_scales')->where('school_id', $this->school->id)->whereNull('purpose')->delete();
        foreach ($bands as [$label, $min, $max, $remark, $order]) {
            DB::table('grading_scales')->updateOrInsert(
                ['school_id' => $this->school->id, 'purpose' => null, 'label' => $label],
                ['min_score' => $min, 'max_score' => $max, 'remark' => $remark, 'display_order' => $order]
            );
        }
        $nat = [['Fail', 0, 39.99, 0], ['Pass', 40, 79.99, 1], ['Mastery', 80, 100, 2]];
        foreach ($nat as [$label, $min, $max, $order]) {
            DB::table('grading_scales')->updateOrInsert(
                ['school_id' => $this->school->id, 'purpose' => 'nat', 'label' => $label],
                ['min_score' => $min, 'max_score' => $max, 'remark' => $label, 'display_order' => $order]
            );
        }
    }

    private function ensureSubjects(): void
    {
        $all = array_unique(array_merge($this->nurserySubjects, $this->primarySubjects));
        foreach ($all as $name) {
            $this->subjectIds[$name] = Subject::firstOrCreate(
                ['school_id' => $this->school->id, 'name' => $name]
            )->id;
        }
    }

    /** @return array<string,Grade> name => model */
    private function ensureGrades(): array
    {
        $out = [];
        foreach (array_keys($this->gradeSections) as $name) {
            $out[$name] = Grade::firstOrCreate(['school_id' => $this->school->id, 'name' => $name]);
        }
        return $out;
    }

    private function ensurePeriods(): void
    {
        $defs = [
            ['Period 1', '08:00', '08:40', false], ['Period 2', '08:40', '09:20', false],
            ['Period 3', '09:20', '10:00', false], ['Break', '10:00', '10:20', true],
            ['Period 4', '10:20', '11:00', false], ['Period 5', '11:00', '11:40', false],
            ['Lunch', '11:40', '12:20', true], ['Period 6', '12:20', '13:00', false],
            ['Period 7', '13:00', '13:40', false],
        ];
        foreach ($defs as $order => [$label, $start, $end, $isBreak]) {
            $p = Period::firstOrCreate(
                ['school_id' => $this->school->id, 'label' => $label],
                ['start_time' => $start, 'end_time' => $end, 'is_break' => $isBreak, 'display_order' => $order]
            );
            if (! $isBreak) {
                $this->periods[] = $p;
            }
        }
    }

    private function makeTimetable(Offering $offering, array $subjects): void
    {
        if (! $this->periods || ! $subjects) {
            return;
        }
        $teacherIds = $offering->teachers()->pluck('users.id')->all() ?: $this->teachers;
        $i = 0;
        foreach (range(1, 5) as $day) {                 // Mon–Fri
            foreach ($this->periods as $period) {
                $subjectName = $subjects[$i % count($subjects)];
                Lesson::updateOrCreate(
                    ['offering_id' => $offering->id, 'period_id' => $period->id, 'day' => $day],
                    ['subject_id' => $this->subjectIds[$subjectName], 'teacher_id' => $teacherIds[$i % count($teacherIds)]]
                );
                $i++;
            }
        }
    }

    /**
     * Daily attendance for the last ~12 school days of a current-year class.
     * Mostly present, with a sprinkling of absent/late so totals and the report
     * book's Time present/absent have realistic data.
     */
    /**
     * The last $count school years, the last one spanning today. A school year runs
     * 1 September to 31 August, so a date before September belongs to the year
     * that started the previous calendar year.
     */
    private function schoolyearsUpToToday(int $count): array
    {
        $currentStart = $this->today->month >= 9 ? $this->today->year : $this->today->year - 1;
        $years = [];
        for ($startY = $currentStart - $count + 1; $startY <= $currentStart; $startY++) {
            $years["{$startY} - ".($startY + 1)] = [$startY, $startY + 1];
        }
        return $years;
    }

    /**
     * Up to twelve school days ending today (or in late June for a year that is
     * over), never earlier than the year's first day of school.
     */
    private function recentSchoolDays(int $endY): array
    {
        $dates = [];
        $d = $this->today->lt(Carbon::parse("{$endY}-06-26")) ? $this->today->copy() : Carbon::parse("{$endY}-06-26");
        $firstDay = Carbon::parse(($endY - 1)."-09-01");
        while (count($dates) < 12 && $d->gte($firstDay)) {
            if ($d->isWeekday()) {
                $dates[] = $d->copy();
            }
            $d->subDay();
        }
        return $dates;
    }

    private function makeAttendance(Offering $offering, array $students, int $endY): void
    {
        if (! $students) {
            return;
        }
        // 12 school days counting back from today (deterministic within a day).
        $dates = array_map(fn (Carbon $d) => $d->toDateString(), $this->recentSchoolDays($endY));
        $teacherIds = $offering->teachers()->pluck('users.id')->all() ?: $this->teachers;
        $recordedBy = $teacherIds[0] ?? null;
        $stamp = Carbon::now();

        $rows = [];
        foreach ($students as $uid) {
            foreach ($dates as $date) {
                $roll = mt_rand(1, 100);
                $status = $roll <= 88 ? 'present' : ($roll <= 96 ? 'absent' : 'late');
                $rows[] = [
                    'school_id' => $this->school->id, 'user_id' => $uid, 'offering_id' => $offering->id,
                    'date' => $date, 'status' => $status, 'type' => 'student',
                    'recorded_by' => $recordedBy, 'note' => null,
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attendances')->insert($chunk);
        }
    }

    /** Log actual/lost instructional hours for the same recent weekdays (expected comes from the timetable). */
    private function makeInstructionalHours(Offering $offering, int $endY): void
    {
        $expected = \App\Http\Controllers\NewInterfaceControllers\InstructionalHoursController::expectedByWeekday($offering);
        if (! array_filter($expected)) {
            return; // no timetable yet — nothing to base hours on
        }

        $dates = $this->recentSchoolDays($endY);
        $recordedBy = $offering->teachers()->pluck('users.id')->first() ?? ($this->teachers[0] ?? null);
        $stamp = Carbon::now();
        $lostPattern = [0, 0, 0.5, 0, 1, 0.25, 0, 0.5, 0, 0.75, 0, 0.5];

        $rows = [];
        foreach ($dates as $i => $date) {
            $exp = $expected[$date->dayOfWeekIso] ?? 0;
            if ($exp <= 0) {
                continue;
            }
            $lost = min($lostPattern[$i % count($lostPattern)], $exp);
            $rows[] = [
                'offering_id' => $offering->id, 'date' => $date->toDateString(),
                'actual_hours' => round($exp - $lost, 2), 'lost_hours' => $lost,
                'remarks' => $lost > 0 ? 'staff meeting' : null,
                'recorded_by' => $recordedBy, 'created_at' => $stamp, 'updated_at' => $stamp,
            ];
        }
        if ($rows) {
            DB::table('instructional_hours')->insert($rows);
        }
    }

    /**
     * Health records (wound cases, incidents, health reports) for current-year
     * pupils. Vocabulary mirrors the real production data, not invented text.
     */
    private function seedHealth(): void
    {
        if (! $this->currentStudentIds) {
            return;
        }
        $now = Carbon::now()->toDateTimeString();
        $pick = fn (array $a) => $a[array_rand($a)];
        $student = fn () => $this->currentStudentIds[array_rand($this->currentStudentIds)];

        // --- Wound cases (+ follow-up visits) ---
        $diagnoses = ['Cut on the knee', 'Grazed elbow', 'Splinter in the foot', 'Small burn on the hand', 'Bruised shin', 'Insect bite, swollen'];
        $treatments = ['Cleaned, advised rest', 'Removed splinter, cleaned', 'Disinfected, plaster applied', 'Cleaned and bandaged', 'Cool water, dressing'];
        $woundRemarks = ['Visit again in 2 days', 'Healing well'];
        for ($i = 0; $i < 9; $i++) {
            $opened = $this->today->copy()->subDays(mt_rand(3, 90));
            $open = $i < 5; // some still open, some closed
            $caseId = DB::table('wound_cases')->insertGetId([
                'user_id' => $student(), 'opened_on' => $opened->toDateString(), 'diagnosis' => $pick($diagnoses),
                'closed_on' => $open ? null : $opened->copy()->addDays(mt_rand(4, 10))->toDateString(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (range(1, mt_rand(1, 3)) as $v) {
                DB::table('wound_care_visits')->insert([
                    'wound_case_id' => $caseId, 'recorded_by' => $this->caregiverId,
                    'visited_on' => $opened->copy()->addDays($v * 2)->toDateString(),
                    'treatment' => $pick($treatments), 'remarks' => $pick($woundRemarks),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // --- Incident reports ---
        // Complaint, place, treatment and medication belong together: drawing each
        // from its own bag gave "Nosebleed in class" on the football field, treated
        // with an ORS sachet and taken to hospital. Each incident is one story.
        $incidents = [
            ['Fell during break and hurt the elbow', 'Playground', 'Wound cleaned and dressed', 'Antiseptic and a plaster', false],
            ['Headache and mild fever', 'Classroom 2', 'Rested in the sick bay', 'Paracetamol 250 mg', true],
            ['Stomach ache after lunch', 'Dining hall', 'Given water and rest', 'ORS sachet', false],
            ['Twisted ankle playing football', 'Football field', 'Ice pack, foot kept up', null, false],
            ['Nosebleed in class', 'Classroom 2', 'Head forward, pinched the nose until it stopped', null, false],
            ['Cut finger on a desk edge', 'Classroom 2', 'Wound cleaned and dressed', 'Antiseptic and a plaster', false],
            ['Felt dizzy during assembly', 'Assembly hall', 'Sat down in the shade, given water', null, false],
            ['Bee sting on the arm', 'Playground', 'Sting removed, cold compress', 'Antihistamine 5 mg', false],
        ];
        for ($i = 0; $i < 14; $i++) {
            [$complaint, $location, $action, $medication, $feverish] = $incidents[$i % count($incidents)];
            $occurred = $this->today->copy()->subDays(mt_rand(1, 90))->setTime(mt_rand(8, 14), mt_rand(0, 59));
            $sentHome = $feverish || mt_rand(0, 4) === 0;
            $hospital = mt_rand(0, 12) === 0;
            $open = $i < 3; // a few still need follow-up
            DB::table('incident_reports')->insert([
                'user_id' => $student(), 'recorded_by' => $this->caregiverId,
                'occurred_at' => $occurred->toDateTimeString(),
                'location' => $location, 'temperature' => $feverish ? mt_rand(375, 385) / 10 : null,
                'complaint' => $complaint, 'action_taken' => $action,
                'first_aid_given' => 1, 'sent_home' => $sentHome, 'taken_to_hospital' => $hospital,
                'medication_given' => $medication,
                'parents_contacted' => ($sentHome || $hospital) ? 1 : (int) (mt_rand(0, 3) === 0),
                'closed_on' => $open ? null : $occurred->copy()->addDays(mt_rand(0, 2))->toDateString(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->seedCheckups();
    }

    /**
     * Routine checkups: a growth series per pupil, not a single dot.
     *
     * Three or four checkups spread over the demo's school years, so the growth
     * charts show a line. Each pupil keeps their own offset from the WHO median
     * (drawn once, mostly within one SD) and grows along it, so the record reads
     * like a healthy child being measured now and then, not like an emergency.
     */
    private function seedCheckups(): void
    {
        $now = Carbon::now()->toDateTimeString();
        $pick = fn (array $a) => $a[array_rand($a)];

        // Weighted towards "nothing to report", with the odd mild complaint.
        $conditions = array_merge(
            array_fill(0, 6, 'Generally healthy'),
            ['Occasional headaches', 'Mild cough', 'Complains of stomach ache now and then', 'Teeth need attention', 'Catches cold easily']
        );
        // Mirrors the form's own options; mostly fine, rarely poor.
        $cond = ['Good', 'Good', 'Good', 'Good', 'Excellent', 'Excellent', 'Poor'];

        $profiles = DB::table('profiles')
            ->whereIn('user_id', $this->currentStudentIds)
            ->get()
            ->keyBy('user_id');

        $rows = [];

        foreach ($this->currentStudentIds as $uid) {
            $profile = $profiles[$uid] ?? null;
            if (! $profile?->birth_date) {
                continue;
            }
            $birth = Carbon::parse($profile->birth_date);

            // The pupil's own line: an offset from the median they keep for life.
            // Averaging two draws keeps most pupils near the middle and makes an
            // extreme child rare, which is what a real class looks like.
            $z = fn () => (mt_rand(-120, 120) + mt_rand(-120, 120)) / 200;
            $zHeight = $z();
            $zBmi = $z();

            // One checkup per school year, and for some pupils a recent extra.
            $monthsAgo = [30, 18, 6];
            if (mt_rand(0, 1)) {
                $monthsAgo[] = 1;
            }

            foreach ($monthsAgo as $ago) {
                $on = Carbon::now()->subMonths($ago)->subDays(mt_rand(0, 20));
                $ageMonths = $birth->diffInMonths($on);
                if ($ageMonths < 60) {
                    continue; // below the WHO 2007 reference; the chart wouldn't plot it
                }

                // A measurement is never exactly on the child's own line.
                $noise = fn () => mt_rand(-15, 15) / 100;
                $height = GrowthCurve::heightCm($profile->gender, $ageMonths, $zHeight + $noise());
                $weight = GrowthCurve::weightKg($profile->gender, $ageMonths, $height, $zBmi + $noise());

                $rows[] = [
                    'user_id' => $uid,
                    'general_condition' => $pick($conditions),
                    'height_in_cm' => (int) round($height),
                    'weight_in_gram' => (int) round($weight * 1000),
                    'teeth_condition' => $pick($cond), 'eyes_condition' => $pick($cond),
                    'ears_condition' => $pick($cond), 'hair_condition' => $pick($cond),
                    'nails_condition' => $pick($cond),
                    'wound_and_bruise_observations' => null,
                    'worm_treatment_received' => mt_rand(0, 1),
                    'created_at' => $on->toDateTimeString(),   // the checkup date IS created_at
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('health_reports')->insert($chunk);
        }
    }

    private function makeStaff(): void
    {
        // Configurable employment-status codes (Gambian civil-service grades).
        $statuses = collect(['DHMC', 'HMA', 'SMC', 'DHMA', 'SMB', 'QT', 'ECD'])
            ->map(fn ($label, $i) => StaffStatus::firstOrCreate(
                ['school_id' => $this->school->id, 'label' => $label],
                ['display_order' => $i]
            ))->values();

        // Named staff, one per role: the demo lets a visitor step into each of them.
        // These are invented people. The demo is public, so no real headmaster,
        // caregiver or teacher of an actual school appears in it.
        $this->person('Baboucarr', 'Sowe', 'admin', 'admin@demo.kubo.global');
        $this->person('Isatou', 'Camara', 'headmaster', 'headmaster@demo.kubo.global');
        $this->caregiverId = $this->person('Fatoumata', 'Danso', 'caregiver', 'caregiver@demo.kubo.global')->id;
        $this->person('Alieu', 'Gaye', 'assistant_coordinator', 'coordinator@demo.kubo.global');
        // The role picker's Teacher: created BEFORE the pool (the picker takes the
        // lowest-id teacher) and made class teacher of Grade 1 and Grade 4 below —
        // a grade teacher entering real term marks, the flow schools actually run.
        $this->demoTeacherId = $this->person('Penda', 'Fofana', 'teacher', 'teacher@demo.kubo.global')->id;

        for ($i = 0; $i < 16; $i++) {
            [$first, $last] = $this->randomName();
            $email = strtolower("{$first}.{$last}.{$i}@demo.kubo.global");
            $this->teachers[] = $this->person($first, $last, 'teacher', $email)->id;
        }

        // Fill employment records + demographics so the demo staff list prints with data.
        foreach (User::role(['admin', 'headmaster', 'caregiver', 'teacher', 'assistant_coordinator'])->get()->values() as $k => $u) {
            StaffProfile::firstOrCreate(['user_id' => $u->id], [
                'prn' => '19'.str_pad((string) (1000 + $u->id), 5, '0', STR_PAD_LEFT),
                'tin' => '0610'.str_pad((string) (300000 + $u->id), 6, '0', STR_PAD_LEFT),
                'staff_status_id' => $statuses[$k % $statuses->count()]->id,
                'appointed_on' => sprintf('20%02d-09-01', 5 + ($k % 15)),
                'confirmed_on' => sprintf('20%02d-01-26', 8 + ($k % 12)),
            ]);
            $u->profile()->updateOrCreate([], [
                'gender' => ($k % 2) ? 'F' : 'M',
                'primary_phone' => '7'.str_pad((string) (700000 + $u->id), 6, '0', STR_PAD_LEFT),
            ]);
        }
    }

    /** @return Term[] */
    private function makeTerms(Schoolyear $year, int $startY, int $endY): array
    {
        $spans = [
            ['Term 1', "{$startY}-09-01", "{$startY}-12-22"],
            ['Term 2', "{$startY}-12-23", ($startY + 1).'-03-29'],
            ['Term 3', ($startY + 1).'-03-30', "{$endY}-07-31"],
        ];
        $terms = [];
        foreach ($spans as [$name, $start, $end]) {
            $terms[] = Term::firstOrCreate(
                ['schoolyear_id' => $year->id, 'name' => $name],
                ['start' => $start, 'end' => $end]
            );
        }
        return $terms;
    }

    private function makeNatConfig(Schoolyear $year, array $gradeModels): void
    {
        $config = NatConfig::firstOrCreate(
            ['school_id' => $this->school->id, 'schoolyear_id' => $year->id],
            ['enabled' => true, 'label' => 'National Assessment Test']
        );
        foreach ($this->natSubjects as $gradeName => $subjects) {
            foreach (array_values($subjects) as $order => $subjectName) {
                DB::table('nat_config_subjects')->updateOrInsert(
                    [
                        'nat_config_id' => $config->id,
                        'grade_id'      => $gradeModels[$gradeName]->id,
                        'subject_id'    => $this->subjectIds[$subjectName],
                    ],
                    ['max_score' => 100, 'display_order' => $order]
                );
            }
        }
    }

    /**
     * The role picker's Teacher leads this class: demote the randomly drawn
     * principal and put the persona in charge, so a visitor stepping into
     * "Teacher" gets real grade classes (Grade 1 + Grade 4) to enter marks for —
     * the same flow a school's class teacher runs at report time.
     */
    private function makeDemoTeacherPrincipal(Offering $offering): void
    {
        DB::table('teacher_offering')->where('offering_id', $offering->id)->update(['principal' => false]);
        $offering->teachers()->syncWithoutDetaching([$this->demoTeacherId => ['principal' => true]]);
    }

    private function attachTeachers(Offering $offering, array $subjects): void
    {
        // One class principal + two subject teachers, drawn from the pool.
        $pool = $this->teachers;
        shuffle($pool);
        $principal = $pool[0];
        $offering->teachers()->syncWithoutDetaching([
            $principal => ['principal' => true],
            $pool[1]   => ['principal' => false],
            $pool[2]   => ['principal' => false],
        ]);
    }

    private function linkSubjects(Offering $offering, array $subjects, array $terms): void
    {
        // The curriculum-per-class-per-term link the app reads is
        // subject_term_offering (Offering::subjects() → scorebook, term reports,
        // rollover, settings) — the single source of truth for a class's subjects.
        foreach ($terms as $term) {
            foreach ($subjects as $name) {
                DB::table('subject_term_offering')->updateOrInsert(
                    ['offering_id' => $offering->id, 'subject_id' => $this->subjectIds[$name], 'term_id' => $term->id],
                    []
                );
            }
        }
    }

    /**
     * Age a pupil is expected to be in each grade. Birth dates used to be drawn
     * at random (6-13), which put ten-year-olds in Nursery 1.
     */
    private const GRADE_AGE = [
        'Nursery 1' => 4, 'Nursery 2' => 5, 'Nursery 3' => 6,
        'Grade 1' => 7, 'Grade 2' => 8, 'Grade 3' => 9,
        'Grade 4' => 10, 'Grade 5' => 11, 'Grade 6' => 12,
    ];

    /** @return int[] enrolled student user ids */
    private function makeStudents(Offering $offering, int $count): array
    {
        // The age belongs to the class, in the school year that class ran. A year
        // of slack covers late starters and repeaters, which are common enough.
        $expected = self::GRADE_AGE[$offering->grade->name] ?? 8;
        $startYear = Carbon::parse($offering->schoolyear->start)->year;

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            [$first, $last, $gender] = $this->randomName(true);
            $u = User::create([
                'first_name' => $first,
                'last_name'  => $last,
                'password'   => $this->pw,
                'school_id'  => $this->school->id,
            ]);
            $u->assignRole('student');
            Profile::create([
                'user_id'    => $u->id,
                'gender'     => $gender, // Profile normalises to canonical 'M'/'F'
                'birth_date' => Carbon::create($startYear - $expected - mt_rand(0, 1), mt_rand(1, 12), mt_rand(1, 28)),
                'tribe'      => ['mandinka', 'fula', 'wolof', 'jola', 'serahule'][mt_rand(0, 4)],
            ]);
            Enrollment::create(['user_id' => $u->id, 'offering_id' => $offering->id]);
            $ids[] = $u->id;
        }
        return $ids;
    }

    private function makeScores(Offering $offering, array $subjects, array $terms, array $students, bool $isCurrent, int $endY): void
    {
        // Each student has a latent ability so their scores correlate across subjects.
        $ability = [];
        foreach ($students as $sid) {
            $ability[$sid] = mt_rand(45, 92);
        }
        $testsPerTerm = $isCurrent ? 2 : 1;

        foreach ($terms as $ti => $term) {
            // Dates land on the tests-mode buckets (Test 1 = the term's first month,
            // Test 2 = the next, Exam = the last), so the by-test scorebook shows
            // the marks under the periods a teacher expects.
            $termStart = \Illuminate\Support\Carbon::parse($term->start)->startOfMonth();
            $examMonth = \Illuminate\Support\Carbon::parse($term->end)->startOfMonth();
            foreach ($subjects as $subjectName) {
                $subjectId = $this->subjectIds[$subjectName];
                for ($t = 1; $t <= $testsPerTerm; $t++) {
                    $testDate = $termStart->copy()->addMonths($t - 1)->day(15);
                    if ($testDate->gt($this->today)) {
                        continue; // not sat yet: the current year is seeded up to today
                    }
                    $a = $this->makeAssessment('Test', $subjectId, $offering->id, $term->id, 25, "Test {$t}", $testDate->toDateString());
                    $this->bufferScores($a, $students, $ability, 25);
                }
                $examDate = $examMonth->copy()->day(25);
                if ($examDate->gt($this->today)) {
                    continue;
                }
                $exam = $this->makeAssessment('Exam', $subjectId, $offering->id, $term->id, 75, 'Exam', $examDate->toDateString());
                $this->bufferScores($exam, $students, $ability, 75);
            }
        }
    }

    private function makeNatScores(Offering $offering, string $gradeName, array $students, int $endY): void
    {
        if ($this->today->lt(Carbon::parse("{$endY}-06-10"))) {
            return; // the NAT of the current year has not been sat yet
        }
        foreach ($this->natSubjects[$gradeName] as $subjectName) {
            $a = $this->makeAssessment(
                'National Assessment Test', $this->subjectIds[$subjectName], $offering->id, null, 100,
                'National Assessment Test', "{$endY}-06-10"
            );
            foreach ($students as $sid) {
                $this->scoreRows[] = $this->scoreRow($sid, $a->id, mt_rand(35, 96));
            }
        }
    }

    private function makeAssessment(string $type, int $subjectId, int $offeringId, ?int $termId, int $max, string $name, string $date): Assessment
    {
        return Assessment::create([
            'assessment_type_id' => $this->assessmentTypes[$type]->id,
            'subject_id'         => $subjectId,
            'offering_id'        => $offeringId,
            'term_id'            => $termId,
            'name'               => $name,
            'date'               => $date,
            'max_score'          => $max,
            'confirmed'          => 1,
        ]);
    }

    private function bufferScores(Assessment $a, array $students, array $ability, int $max): void
    {
        foreach ($students as $sid) {
            // Score = ability% of max, with a little noise, clamped to [0, max].
            $pct = max(8, min(100, $ability[$sid] + mt_rand(-12, 12)));
            $score = (int) round($pct / 100 * $max);
            $this->scoreRows[] = $this->scoreRow($sid, $a->id, min($max, $score));
            if (count($this->scoreRows) >= 2000) {
                $this->flushScores();
            }
        }
    }

    private function scoreRow(int $userId, int $assessmentId, int $score): array
    {
        $now = Carbon::now()->toDateTimeString();
        return [
            'user_id' => $userId, 'assessment_id' => $assessmentId,
            'score' => $score, 'absent' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function flushScores(): void
    {
        if ($this->scoreRows) {
            DB::table('assessment_scores')->insert($this->scoreRows);
            $this->scoreRows = [];
        }
    }

    private function person(string $first, string $last, string $role, ?string $email): User
    {
        $u = User::firstOrCreate(
            ['email' => $email],
            ['first_name' => $first, 'last_name' => $last, 'password' => $this->pw, 'school_id' => $this->school->id]
        );
        $u->assignRole($role);
        Profile::firstOrCreate(['user_id' => $u->id]);
        return $u;
    }

    /** @return array{0:string,1:string,2?:string} */
    private function randomName(bool $withGender = false): array
    {
        $gender = mt_rand(0, 1) ? 'm' : 'f';
        $pool = $gender === 'm' ? $this->names['male'] : $this->names['female'];
        $first = $pool[array_rand($pool)];
        $last = $this->names['last'][array_rand($this->names['last'])];
        return $withGender ? [$first, $last, $gender] : [$first, $last];
    }
}
