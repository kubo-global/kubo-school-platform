<?php

namespace App\Http\Controllers\NewInterfaceControllers;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\Offering;
use App\Models\School;
use App\Models\Schoolyear;
use App\Models\Teacher;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AssessmentController extends Controller
{
    /**
     * Block writes against a locked term unless the actor is headmaster/admin.
     * Past-term edits are an audit risk (changing scores after report cards
     * have gone out); only the principal can override.
     */
    private function assertTermWritable(?Term $term): void
    {
        // A term-less assessment (the National Assessment Test) has no term lock.
        if (!$term || !$term->isLocked()) {
            return;
        }
        if (Auth::user()?->hasAnyRole(['headmaster', 'admin'])) {
            return;
        }
        abort(403, "Term \"{$term->name}\" is closed. Ask the headmaster to enter or correct scores.");
    }

    /**
     * Step 1: Pick class, term, subject, name, max score.
     */
    public function create(Request $request, $typeId)
    {
        $type = AssessmentType::findOrFail($typeId);
        $user = Auth::user();
        $schoolyear = Schoolyear::latest();

        if (!$schoolyear) {
            return redirect()->route('reporting.grades')->with('error', 'No school year configured.');
        }

        if ($user->hasRole('headmaster')) {
            $offerings = Offering::where('schoolyear_id', $schoolyear->id)->with('grade')->get();
        } else {
            $offerings = Teacher::find($user->id)?->offerings()
                ->where('schoolyear_id', $schoolyear->id)->with('grade')->get() ?? collect();
        }

        $terms = $schoolyear->terms()->get();

        // A zero-weight type (NAT) is term-less: no term step, and subjects are loaded
        // for the offering across terms rather than for a chosen term.
        $isTermLess = $type->weight <= 0;

        // load subjects — filtered to ones the teacher is responsible for. headmaster/
        // admin/system_admin and class principals see all subjects.
        $subjects = collect();
        if ($request->query('offering') && ($isTermLess || $request->query('term'))) {
            $offering = Offering::find($request->query('offering'));
            $allSubjects = $isTermLess
                ? ($offering?->subjects($terms->first())->get() ?? collect())
                : ($offering?->subjects(Term::find($request->query('term')))->get() ?? collect());
            $subjects = $allSubjects->filter(fn ($s) => $user->canEnterGradesFor(
                (int) $request->query('offering'),
                $s->id
            ))->values();
        }

        // Tests-mode schools (The Swallow) work in fixed Test 1 / Test 2 / Exam
        // slots per subject per term. Free-typed names put marks in stray month
        // buckets, so the form offers the slots instead of a name field.
        $slots = (! $isTermLess && $this->testsMode())
            ? ($type->name === 'Exam' ? ['Exam'] : ['Test 1', 'Test 2'])
            : null;

        // The maximum the form starts from. A tests-mode school scores every test
        // out of 25 and every exam out of 75 (the scale the term grid uses), so a
        // new slot starts there instead of at 100.
        $defaultMax = $slots
            ? ($type->name === 'Exam' ? 75 : 25)
            : ($type->default_max_score ?? 100);

        return view('pages.assessment.create', [
            'type' => $type,
            'slots' => $slots,
            'defaultMax' => $defaultMax,
            'offerings' => $offerings,
            'terms' => $terms,
            'subjects' => $subjects,
            'isTermLess' => $isTermLess,
            'selectedOffering' => $request->query('offering'),
            'selectedTerm' => $request->query('term'),
            'selectedSubject' => $request->query('subject'),
        ]);
    }

    /**
     * Step 1 POST: Create draft assessment, redirect to scores.
     */
    public function store(Request $request)
    {
        // A zero-weight type (the National Assessment Test) is not part of a term,
        // so it carries no term_id and skips the term-lock check.
        $type = AssessmentType::find($request->input('assessment_type_id'));
        $isTermLess = $type && $type->weight <= 0;

        $validated = $request->validate([
            'assessment_type_id' => 'required|exists:assessment_types,id',
            'offering_id' => 'required|exists:offerings,id',
            'term_id' => ($isTermLess ? 'nullable' : 'required').'|exists:terms,id',
            'subject_id' => 'required|exists:subjects,id',
            'name' => 'required|string|max:200',
            'date' => 'nullable|date',
            'max_score' => 'required|integer|min:1',
        ]);

        if (! $isTermLess) {
            $this->assertTermWritable(Term::findOrFail($validated['term_id']));
        }

        if (!Auth::user()->canEnterGradesFor((int) $validated['offering_id'], (int) $validated['subject_id'])) {
            abort(403, "You're not assigned to teach this subject in this class.");
        }

        // Tests mode: the name must be one of the fixed slots, the date is the
        // slot's bucket month (so the scorebook dropdown resolves it), and a
        // subject can hold each slot only once per term.
        if (! $isTermLess && $this->testsMode()) {
            $allowed = $type->name === 'Exam' ? ['Exam'] : ['Test 1', 'Test 2'];
            if (! in_array($validated['name'], $allowed, true)) {
                return back()->withInput()->withErrors(['name' => 'Choose '.implode(' or ', $allowed).'.']);
            }

            $term = Term::findOrFail($validated['term_id']);
            $validated['date'] = $term->testsBucketMonths()[$validated['name']]->toDateString();

            $exists = Assessment::where('offering_id', $validated['offering_id'])
                ->where('term_id', $validated['term_id'])
                ->where('subject_id', $validated['subject_id'])
                ->where('name', $validated['name'])
                ->exists();
            if ($exists) {
                return back()->withInput()->withErrors([
                    'name' => $validated['name'].' already exists for this subject this term. Open it from the scorebook to change its marks.',
                ]);
            }
        }

        $assessment = Assessment::create([
            'assessment_type_id' => $validated['assessment_type_id'],
            'offering_id' => $validated['offering_id'],
            'term_id' => $isTermLess ? null : ($validated['term_id'] ?? null),
            'subject_id' => $validated['subject_id'],
            'name' => $validated['name'],
            'date' => $validated['date'] ?? null,
            'max_score' => $validated['max_score'],
            'confirmed' => false, // draft
        ]);

        return redirect()->route('reporting.assessment.scores', $assessment);
    }

    /** Whether this school splits terms into fixed Test 1 / Test 2 / Exam slots. */
    private function testsMode(): bool
    {
        return School::first()?->config(\App\Models\SchoolConfig::SCOREBOOK_PERIOD_MODE, 'months') === 'tests';
    }

    /**
     * Step 2: Enter scores for each student.
     */
    public function scores(Assessment $assessment)
    {
        $students = Offering::find($assessment->offering_id)
            ->students()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $existingScores = $assessment->scores->keyBy('user_id');

        return view('pages.assessment.scores', [
            'assessment' => $assessment,
            'students' => $students,
            'existingScores' => $existingScores,
        ]);
    }

    /**
     * Step 2 POST: Save scores, redirect to confirm.
     */
    public function saveScores(Request $request, Assessment $assessment)
    {
        $this->assertTermWritable($assessment->term);

        if (is_array($request->scores)) {
            foreach ($request->scores as $studentId => $score) {
                $isAbsent = is_array($request->absent) && array_key_exists($studentId, $request->absent);
                $hasScore = $score !== null && $score !== '';

                if (!$isAbsent && !$hasScore) {
                    return redirect()->back()
                        ->withInput()
                        ->withErrors(['validation' => 'Each student must have either a score or be marked as absent.']);
                }

                // Validate here so an over-max mark is a form error, not a 500 from
                // the model's own guard.
                if ($hasScore && ! $isAbsent && ((float) $score > $assessment->max_score || (float) $score < 0)) {
                    return redirect()->back()
                        ->withInput()
                        ->withErrors(['validation' => "Scores must be between 0 and {$assessment->max_score} (the maximum for this assessment)."]);
                }

                AssessmentScore::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'user_id' => $studentId],
                    [
                        'score' => $isAbsent ? null : $score,
                        'absent' => $isAbsent,
                    ]
                );
            }
        }

        return redirect()->route('reporting.assessment.confirm', $assessment);
    }

    /**
     * Step 3: Review scores before confirming.
     */
    public function confirm(Assessment $assessment)
    {
        $students = Offering::find($assessment->offering_id)
            ->students()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $scores = $assessment->scores->keyBy('user_id');

        return view('pages.assessment.confirm', [
            'assessment' => $assessment->load('assessmentType'),
            'students' => $students,
            'scores' => $scores,
        ]);
    }

    /**
     * Step 3 POST: Confirm — mark as final.
     */
    public function finalize(Assessment $assessment)
    {
        $this->assertTermWritable($assessment->term);
        $assessment->update(['confirmed' => true]);

        return redirect()->route('reporting.grades')
            ->with('success', $assessment->name . ' has been saved.');
    }

    /**
     * Edit an existing confirmed assessment's scores.
     */
    public function edit(Assessment $assessment)
    {
        return $this->scores($assessment);
    }

    /**
     * Delete an assessment.
     */
    public function destroy(Assessment $assessment)
    {
        $this->assertTermWritable($assessment->term);
        $assessment->delete();
        return redirect()->route('reporting.grades')
            ->with('success', 'Assessment deleted.');
    }
}
