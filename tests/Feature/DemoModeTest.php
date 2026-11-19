<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    private function demo(): void
    {
        // The base test school has no caregiver; the demo picker only offers roles
        // that somebody actually holds.
        $caregiver = \App\Models\User::create([
            'first_name' => 'Care', 'last_name' => 'Giver', 'password' => bcrypt('x'),
        ]);
        $caregiver->assignRole('caregiver');

        // Spatie caches roles; forget it so User::role(...) resolves the seeded users.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['app.demo' => true]);
    }

    #[Test]
    public function a_visitor_picks_a_role_rather_than_landing_as_one_fixed_person(): void
    {
        $this->demo();

        // A guest on an auth-gated page is sent to the picker, not signed in behind their back.
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get(route('login'))->assertRedirect(route('demo.picker'));

        $this->get(route('demo.picker'))
            ->assertOk()
            ->assertSee('Open as headmaster')
            ->assertSee('teacher')
            ->assertSee('caregiver');
    }

    #[Test]
    public function picking_a_role_signs_you_in_as_that_person(): void
    {
        $this->demo();

        $this->post(route('demo.login', 'teacher'))->assertRedirect(route('home'));
        $this->assertTrue(auth()->user()->hasRole('teacher'));

        // And switching role mid-visit is one click, not a logout.
        // A caregiver's dashboard is empty, so they land on the health desk.
        $this->post(route('demo.login', 'caregiver'))->assertRedirect(route('health.index'));
        $this->assertTrue(auth()->user()->hasRole('caregiver'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('resets nightly')
            ->assertSee('Switch role');
    }

    #[Test]
    public function opening_the_picker_lets_go_of_the_role_you_have(): void
    {
        $this->demo();

        $this->post(route('demo.login', 'headmaster'));
        $this->assertTrue(auth()->user()->hasRole('headmaster'));

        // Otherwise the picker renders inside the sidebar and header of the person
        // you are about to stop being.
        $this->get(route('demo.picker'))->assertOk();
        $this->assertGuest();
    }

    #[Test]
    public function the_account_menu_carries_the_demo_actions_only_in_demo_mode(): void
    {
        $this->demo();
        $this->post(route('demo.login', 'headmaster'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Switch role')
            ->assertSee('Reset the demo data');

        // A real school's account menu says nothing about demos.
        config(['app.demo' => false]);

        $this->actingAs($this->headmaster)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Switch role')
            ->assertDontSee('Reset the demo data');
    }

    #[Test]
    public function the_demo_shows_the_real_sign_in_screens_rather_than_hiding_them(): void
    {
        $this->demo();

        // The staff screen is reachable on purpose, password and all.
        $this->get(route('login', ['real' => 1]))
            ->assertOk()
            ->assertSee('Password', false);

        // ...while a bare /login still goes to the picker.
        $this->get(route('login'))->assertRedirect(route('demo.picker'));

        // And the picker sends a pupil into the real child sign-in flow instead of
        // handing them a session behind their back.
        $this->get(route('demo.picker'))
            ->assertOk()
            ->assertSee(route('student-login.select-grade'), false);
    }

    #[Test]
    public function a_visitor_never_lands_in_the_installer_while_the_demo_is_resetting(): void
    {
        $this->demo();

        // Mid-reset (migrate:fresh) the school and every account are briefly gone.
        // Without this, the app would funnel the visitor into the setup wizard of a
        // public demo.
        \App\Models\School::query()->delete();
        \Illuminate\Support\Facades\DB::table('model_has_roles')->delete();

        // Wherever a visitor knocks (a guest is bounced to the login first, hence the
        // redirects), they land on "the demo is resetting", never on the wizard.
        $this->followingRedirects()
            ->get('/dashboard')
            ->assertStatus(503)
            ->assertSee('The demo is resetting');

        $this->get('/install')->assertStatus(503);
        $this->get(route('demo.picker'))->assertStatus(503);
    }

    #[Test]
    public function a_sign_in_screen_never_renders_inside_someone_elses_session(): void
    {
        $this->demo();
        $this->post(route('demo.login', 'headmaster'));
        $this->assertTrue(auth()->check());

        // The pupil sign-in is where a child's session begins; it must not open inside
        // the headmaster's sidebar and header.
        $this->get(route('student-login.select-grade'))->assertOk();
        $this->assertGuest();

        $this->post(route('demo.login', 'headmaster'));
        $this->get(route('login', ['real' => 1]))->assertOk();
        $this->assertGuest();
    }

    #[Test]
    public function signing_in_as_a_role_says_nothing_the_school_would_say(): void
    {
        $this->demo();

        // The demo bar already names who you are; an app-level "you are now signed in"
        // reads like a notification a real school would get.
        $this->post(route('demo.login', 'teacher'))
            ->assertRedirect(route('home'))
            ->assertSessionMissing('success');
    }

    #[Test]
    public function a_made_up_role_is_not_a_way_in(): void
    {
        $this->demo();

        $this->post(route('demo.login', 'superuser'))->assertNotFound();
        $this->assertGuest();
    }

    #[Test]
    public function none_of_the_demo_doors_exist_outside_demo_mode(): void
    {
        config(['app.demo' => false]);

        // A guest is sent to the real login form, not signed in for free.
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->get(route('demo.picker'))->assertNotFound();
        $this->post(route('demo.login', 'headmaster'))->assertNotFound();
        $this->post(route('demo.reset'))->assertNotFound();
        $this->assertGuest();
    }

    #[Test]
    public function the_reset_command_refuses_to_run_outside_demo_mode(): void
    {
        config(['app.demo' => false]);

        $this->artisan('demo:reset')->assertExitCode(1);
    }

    #[Test]
    public function stepping_in_lands_on_the_screen_the_picker_promised(): void
    {
        $this->demo();
        $coordinator = \App\Models\User::create([
            'first_name' => 'Co', 'last_name' => 'Ordinator', 'password' => bcrypt('x'),
        ]);
        $coordinator->assignRole('assistant_coordinator');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // A teacher without a class has nowhere better to go than the dashboard...
        $this->post(route('demo.login', 'teacher'))->assertRedirect(route('home'));

        // ...and one with a class goes straight to its scorebook.
        $year = \App\Models\Schoolyear::orderByDesc('id')->first() ?? \App\Models\Schoolyear::factory()->create();
        $class = \App\Models\Offering::factory()->create([
            'schoolyear_id' => $year->id,
            'grade_id' => \App\Models\Grade::factory()->create(['name' => 'Grade 1'])->id,
        ]);
        $teacher = \App\Models\User::role('teacher')->orderBy('id')->first();
        $class->teachers()->attach($teacher->id, ['principal' => true]);
        $this->post(route('demo.login', 'teacher'))->assertRedirect(route('scorebook.class', $class));
        $this->get(route('scorebook.class', $class))->assertOk();

        // The coordinator's dashboard is empty too: they land on the lesson plans.
        $this->post(route('demo.login', 'assistant_coordinator'))->assertRedirect(route('lesson-plans.index'));
        $this->get(route('lesson-plans.index'))->assertOk();

        $this->post(route('demo.login', 'caregiver'))->assertRedirect(route('health.index'));
        $this->get(route('health.index'))->assertOk();

        $this->post(route('demo.login', 'headmaster'))->assertRedirect(route('home'));
    }

    #[Test]
    public function the_picker_offers_a_staff_door_and_a_pupil_door(): void
    {
        $this->demo();

        // A school without classes or marks has no screens to show yet; the two
        // doors stand all the same, the headmaster first.
        $this->get(route('demo.picker'))
            ->assertOk()
            ->assertSeeInOrder(['Run the school', 'Open as headmaster', 'or as', 'teacher', 'Learn in it', 'Sign in as a pupil'])
            ->assertDontSee('Results')
            ->assertDontSee('resets nightly');
    }

    #[Test]
    public function the_doors_show_the_screens_behind_them_from_the_schools_own_data(): void
    {
        $this->seed(\Database\Seeders\AlbredaSeeder::class);
        $this->demo();

        // The class shown is the one the demo's Teacher steps into.
        $class = \App\Models\Offering::where('name', 'A')
            ->whereHas('grade', fn ($q) => $q->where('name', 'Grade 6'))->firstOrFail();
        $teacher = \App\Models\User::role('teacher')->orderBy('id')->first();
        \Illuminate\Support\Facades\DB::table('teacher_offering')->where('user_id', $teacher->id)->delete();
        $class->teachers()->attach($teacher->id, ['principal' => true]);

        // The seeded class has marks. Its results sheet as the Results page
        // itself ranks it is what the staff door has to show.
        $latest = \App\Models\Assessment::where('offering_id', $class->id)->orderByDesc('date')->firstOrFail();
        $head = \App\Models\User::where('first_name', 'Mr')->where('last_name', 'Badjie')->firstOrFail();
        $rows = $this->actingAs($head)->get(route('term-grid.report', [
            'offering' => $class, 'term' => $latest->term_id, 'month' => $latest->date->format('Y-m'),
        ]))->assertOk()->viewData('rows');
        $top = $rows[0]['student'];

        $firstByName = \App\Models\Enrollment::where('offering_id', $class->id)->with('student')->get()
            ->map->student->sortBy('first_name')->first();

        $this->get(route('demo.picker'))
            ->assertOk()
            ->assertSeeInOrder([
                'Results', $class->displayName(),
                $top->first_name.' '.$top->last_name,
                '<td class="strong">'.(int) round($rows[0]['total']).'</td>', '1st',
                'Open as headmaster',
                // And the pupil door shows the same class's sign-in list.
                'Find your name', $firstByName->first_name.' '.$firstByName->last_name,
                'Sign in as a pupil',
            ], false);
    }
}
