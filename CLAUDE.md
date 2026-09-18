# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

KUBO is a Laravel 13 school management platform for managing student data, test/exam scores, term reports, and health records. It's designed to work offline on a local hotspot server.

**Tech stack**: Laravel 13 / PHP 8.3+, Vue 3, Tailwind CSS v4, Alpine.js, Livewire 3, MySQL (SQLite for testing), DomPDF for PDF generation.

## Common Commands

```bash
# Run all tests
php ./vendor/bin/phpunit

# Run a single test file
php ./vendor/bin/phpunit tests/Feature/TermResultTest.php

# Run a single test method
php ./vendor/bin/phpunit --filter test_method_name

# Build frontend assets (Vite)
npm run dev      # development server with HMR
npm run build    # production build

# Laravel Artisan
php artisan migrate
php artisan db:seed
php artisan tinker
php artisan results:analysis --term="Term 3"   # full-term analysis + histogram PDFs for every class
```

## Architecture

### Domain Layer
The `app/Domain/` directory contains domain-specific logic separated from Laravel's MVC structure:
- `Domain/Reporting/Repositories/` - `NewTermReportRepository` (one pupil/term), `ClassTermReportRepository` (a class), and `AssessmentRepository` (per-assessment-type weighting)
- `Domain/Reporting/Services/ReportGeneratorService` - PDF term-report generation (DomPDF)
- `Domain/Reporting/Services/PositionService` - ranks a class by term total (single source of truth for the positions view and the report book's Position column)
- `Domain/Nat/NatAnalysis` - NAT statistics for the NAT scores/analysis PDFs

### Key Models and Relationships
- **Student** extends User - uses STI pattern (`$table = 'users'`)
- **Offering** - a class/grade for a specific school year; `name` is an optional section so a grade can run multiple classes (e.g. "Grade 1 — A"/"— B")
- **Enrollment** - links Students to Offerings
- **Term** - academic term within a school year; `Term::isLocked()` gates past-term edits
- **Assessment** - a test/exam/NAT instance (`assessment_type_id`, `subject_id`, `offering_id`, nullable `term_id`); **AssessmentType** carries `weight` (Test 0.25 / Exam 0.75 / NAT 0) and a default max score
- **AssessmentScore** - a pupil's score for an assessment, with an `excused` flag for absentees
- **Subject** - `counts_toward_total` excludes a subject from the term total used to rank pupils
- **GradingScale** - per-school grade key (`purpose` null = term grades, `'nat'` = NAT bands), editable in Settings
- **Period / Lesson** - school-wide timetable periods and per-class weekly lessons (day × period → subject + teacher)
- **NatConfig / NatConfigSubject** - which grades sit the NAT, in which subjects, per school year

### Controllers Structure
All active controllers live in `Http/Controllers/NewInterfaceControllers/` (students, scorebook, report, timetable, NAT report, health/incident/wound, settings, scoresheet, contacts, auth). The old `WebControllers`/`legacy-admin` interface was retired in 1.0.0.

### Route Organization
Routes are in `routes/web.php` with role/permission middleware groups using Spatie:
- `role:headmaster|admin|teacher` - scorebook, term reports, report book, NAT, timetable, positions
- `permission:manage settings` - the Settings screens (grades, subjects, periods, grade key, report type)
- `permission:view medical records` - the Health routes (role-granted, or a per-user grant from `/users`)
- API routes (`routes/api.php`) are minimal, primarily for grade/offering CRUD with inline handlers

### Livewire Components
Located in `app/Livewire/`:
- `Students.php`, `Users.php`, `Healthreports.php` - Paginated list/search components (use `WithPagination`)
- `Contact.php`, `LivewireUser.php` - Form editors (`LivewireUser` binds to scalar properties, not nested model paths)
- `Termreports.php` - Term report filter/selector
- `ExerciseCreator.php` - Multi-step wizard for authoring Kolibri exercises

The legacy multi-step `Test.php` / `Exam.php` score-entry wizards (which juggled `$formstep` state through `Session`) were removed with the legacy tests/exams stack; score entry now lives in the scorebook.

### Roles and Permissions
Uses `spatie/laravel-permission`. Roles: headmaster, teacher, caregiver, admin, student. The `RolesAndPermissionsSeeder` defines all permissions. Authorization is entirely handled through Spatie middleware — no custom policies.

### Testing
- Only Feature tests exist (no Unit test suite) — all tests in `tests/Feature/`
- Tests use in-memory SQLite (`DB_CONNECTION=testing`) with foreign keys disabled
- `TestCase` base class seeds `RolesAndPermissionsSeeder` and creates test users (teacher, admin, headmaster, student)
- `BasicSchoolSeeder` sets up complete school structure for feature tests
- CI: GitHub Actions (`.github/workflows/tests.yml`) runs PHPUnit on PHP 8.5 on every push and pull request

### Database Seeding
`php artisan db:seed` runs `DatabaseSeeder` = `RolesAndPermissionsSeeder` (once — not idempotent) then `DemoSeeder`. `DemoSeeder` builds the whole demo: 3 school years, multi-class grades, prod-like Gambian names (from `database/seeders/data/gambian_names.php`), Test/Exam scores, NAT scores, timetables, and health records. Feature tests don't use it — `TestCase` seeds `RolesAndPermissionsSeeder` + `BasicSchoolSeeder` and uses factories.

### Report Generation
`NewTermReportRepository` aggregates each subject's weighted Test/Exam scores (via `AssessmentRepository`, weights from `AssessmentType`) into a per-subject total, then a term total/average. Subjects flagged `counts_toward_total = false` still show but are excluded from the total (and thus position). Outputs: the one-page **term report** PDF and the booklet-style **Report Book** (`ReportController::reportBook` / `classReportBook` → `print.reportBook`). The class **principal** (lead teacher) comes from the `teacher_offering.principal` pivot flag.

### Frontend Build
Vite entry points: `resources/css/app.css` and `resources/js/app.js`. Vue 3 is aliased to `vue/dist/vue.esm-bundler.js` (ESM bundler build for in-DOM templates). Tailwind CSS v4 Vite plugin handles CSS optimization.

### Notable Configuration
- `AppServiceProvider` sets default string length to 191 (MySQL compatibility) and Faker locale to `en_NG` (Nigerian names)
- Livewire file uploads max 12MB
- Queue driver is synchronous (no background jobs)

### Accessibility
The UI targets WCAG 2.1 AA (see the README's Accessibility section for the rules — contrast, labels, semantic elements). Two things that look like deletable cruft but are intentional:
- `resources/views/vendor/pagination/*` and `resources/views/vendor/livewire/tailwind.blade.php` are **patched copies** of the framework default paginators — the disabled prev/next `<span>`s carry `role="link"` so their `aria-disabled`/`aria-label` are valid (a bare span is `generic`, which axe flags as `aria-prohibited-attr`). Don't delete them; re-sync on major Laravel/Livewire upgrades.
- The per-grade colour palette (`Grade::COLORS`) keeps every `[bg, text]` pair at ≥4.5:1 — keep new/edited hues above that.

### Kolibri Integration
Provided by the `kubo-global/kubo-kolibri` Composer path-package (sibling repo `../kubo-kolibri`). Bridge talks to Kolibri over HTTP on the same machine. `start.sh` boots Kolibri, then runs `php artisan kolibri:reconcile` (idempotent — verifies HTTP login and only repairs when needed) before launching Laravel and Vite. Don't re-derive the `allow_other_browsers_to_connect` / superuser-creation dance ad-hoc; reconcile is the entry point. See `kubo-kolibri/README.md` for the `KOLIBRI_HOME` caveat on systemd-managed installs.
