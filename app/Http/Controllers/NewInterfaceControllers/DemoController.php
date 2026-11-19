<?php

namespace App\Http\Controllers\NewInterfaceControllers;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Offering;
use App\Models\Schoolyear;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The public demo signs visitors in without a password, but not as one fixed
 * person: a demo that only shows the headmaster's view hides the point, which is
 * that a teacher, a caregiver and the headmaster each see a different school.
 * Visitors pick a role and can switch at any time from the demo banner.
 */
class DemoController extends Controller
{
    /**
     * The roles worth showing, in the order the picker and the demo bar offer
     * them. Stepping in lands on the screen where that role's work happens (see
     * landingFor()).
     */
    public const ROLES = [
        'headmaster' => ['label' => 'Headmaster'],
        'teacher' => ['label' => 'Teacher'],
        'caregiver' => ['label' => 'Caregiver'],
        'assistant_coordinator' => ['label' => 'Assistant coordinator'],
        'admin' => ['label' => 'Administrator'],
        'student' => ['label' => 'Pupil'],
    ];

    /**
     * The roles the demo school actually has somebody for, so the bar can offer a
     * one-click switch without querying per card.
     *
     * @return array<string, User>
     */
    public static function availableRoles(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $people = [];
        foreach (array_keys(self::ROLES) as $role) {
            if ($user = (new self)->demoUserFor($role)) {
                $people[$role] = $user;
            }
        }

        return $cached = $people;
    }

    public function picker(Request $request)
    {
        abort_unless(config('app.demo'), 404);

        // Picking a role means letting go of the one you have: sign out first, or the
        // picker renders inside the app's chrome (sidebar and header of the person
        // you are about to stop being).
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // A first-time visitor has never seen the product, so the two ways in
        // (staff, pupil) are shown as the screens behind them, drawn from the
        // data they are about to walk into: one class's results sheet and the
        // same class's name list on the pupil sign-in.
        $year = Schoolyear::orderByDesc('id')->first();
        $classes = ! $year ? collect() : Offering::with('grade')
            ->withCount('enrollments')
            ->where('schoolyear_id', $year->id)
            ->get();

        $people = self::availableRoles();
        $teacher = $people['teacher'] ?? null;
        $shown = ($teacher ? $this->classOf($teacher) : null)
            ?? $classes->firstWhere('enrollments_count', '>', 0);

        return view('pages.demo.login', [
            'people' => $people,
            'classCount' => $classes->count(),
            'pupilCount' => $classes->sum('enrollments_count'),
            'shown' => $shown,
            'sheet' => $shown ? $this->resultsOf($shown) : null,
            'pupils' => ! $shown ? collect() : $shown->enrollments()->with('student')->get()
                ->map(fn ($e) => $e->student)->filter()->sortBy('first_name')->take(7)->values(),
        ]);
    }

    /**
     * The class's most recent results sheet, exactly as the Results page shows
     * it: the data comes from that page's own action, so the picture on the
     * picker cannot drift from the screen behind it. Null when the class has no
     * marks yet.
     */
    private function resultsOf(Offering $class): ?array
    {
        $latest = Assessment::where('offering_id', $class->id)->whereNotNull('date')->orderByDesc('date')->first();
        if (! $latest) {
            return null;
        }

        try {
            $data = app(TermResultController::class)->report(new Request([
                'term' => $latest->term_id,
                'month' => Carbon::parse($latest->date)->format('Y-m'),
            ]), $class)->getData();
        } catch (HttpException) {
            return null;
        }

        return $data['hasMarks'] ? [
            'period' => $data['period']['term']->name.' · '.$data['period']['label'],
            'subjects' => $data['displaySubjects']->take(5),
            'rows' => array_slice($data['rows'], 0, 8),
        ] : null;
    }

    /** Sign in as this role's demo account. Also used to switch role mid-visit. */
    /**
     * Shareable deep link (GET /demo/{role}): "look at the demo as a teacher"
     * becomes one URL. Pupils go through their own picker (a pupil is a class +
     * name, not a single persona). Unknown roles fall back to the picker rather
     * than a 404, so a mistyped shared link still lands somewhere useful.
     */
    public function loginLink(string $role)
    {
        abort_unless(config('app.demo'), 404);

        $role = ['coordinator' => 'assistant_coordinator'][$role] ?? $role;
        if ($role === 'pupil' || $role === 'student') {
            return redirect()->route('student-login.select-grade');
        }
        if (! array_key_exists($role, self::ROLES)) {
            return redirect()->route('demo.picker');
        }

        return $this->login($role);
    }

    public function login(string $role)
    {
        abort_unless(config('app.demo'), 404);
        abort_unless(array_key_exists($role, self::ROLES), 404);

        $user = $this->demoUserFor($role);
        abort_if(! $user, 404, 'No demo account for this role.');

        Auth::login($user);

        // No flash: the demo bar already says who you are, and an app-level
        // "you are now signed in" reads like a notification the school would get.
        return redirect()->to($this->landingFor($role, $user));
    }

    /**
     * Where a role lands: the screen where its work happens. The
     * dashboard is that screen for the headmaster and the administrator; for a
     * caregiver or a coordinator it is an empty page, so they go to their own
     * desk, and the teacher goes straight to the class they enter marks for.
     */
    private function landingFor(string $role, User $user): string
    {
        if ($role === 'teacher') {
            $class = $this->classOf($user);

            return $class ? route('scorebook.class', $class) : route('home');
        }

        return match ($role) {
            'caregiver' => route('health.index'),
            'assistant_coordinator' => route('lesson-plans.index'),
            default => route('home'),
        };
    }

    /** The teacher's class in the current school year, if they have one. */
    private function classOf(User $teacher): ?Offering
    {
        $year = Schoolyear::orderByDesc('id')->first();

        return $year
            ? Offering::where('schoolyear_id', $year->id)
                ->whereIn('id', DB::table('teacher_offering')->where('user_id', $teacher->id)->select('offering_id'))
                ->orderBy('id')
                ->first()
            : null;
    }

    /**
     * The demo's stand-in for a role: the first account that has it. `archived`
     * may be NULL, which SQL's `archived != 1` would drop, so match that too.
     */
    private function demoUserFor(string $role): ?User
    {
        return User::role($role)
            ->where(fn ($q) => $q->whereNull('archived')->orWhere('archived', false))
            ->orderBy('id')
            ->first();
    }
}
