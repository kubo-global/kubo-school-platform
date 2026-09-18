# KUBO School Platform

[![CircleCI](https://dl.circleci.com/status-badge/img/gh/kubo-global/kubo/tree/main.svg?style=svg)](https://dl.circleci.com/status-badge/redirect/gh/kubo-global/kubo/tree/main)

KUBO is a digital school suite developed by Afrodidact: student records, scores, attendance,
timetables, health records — and every official document a school normally compiles by hand,
generated in one click.

It is built for schools with no reliable internet or budget for one: everything runs on a
**local server inside the school** (e.g. a Raspberry Pi acting as a Wi-Fi hotspot), and the
whole interface works from a phone on that hotspot. It is deployed in lower basic schools in
The Gambia, whose national structures (WAEC subject sets, the National Assessment Test,
ministry reporting forms) are supported first-class — but nothing ties it to one country: a
setup wizard lets any school start from a blank academic structure.

**Try it:** a public demo school runs at [demo.kubo.global](https://demo.kubo.global) — pick a
role (headmaster, teacher, caregiver, pupil, …) and look around. It resets every night.

## The paperwork, generated

Staff in public schools lose days per term filling standard forms by hand: tallying
attendance registers into monthly boys/girls totals, copying scores into result sheets,
computing per-sex NAT percentages, drawing bar charts with a ruler. KUBO already holds the
underlying data, so it generates the documents:

| Document | What it is | Generated from |
|---|---|---|
| **Term report** | Per-pupil PDF: weighted test/exam totals per subject, grade + remark from the rubric, position, average, class size, and the typed conduct/general remarks. Per-school card layouts (`term_card_layout`) | Reports / Prepare reports |
| **Report Book** | Booklet-style annual grid (months × subjects), printable filled **or blank**, per pupil or whole class | Reports / Positions |
| **Internal Assessment Result Sheet** | The class master list: pupils × subjects with Total, Average % and Position for a term | Positions |
| **Result analysis** | Per-sex fail/pass/mastery counts and percentages per subject for a period or the whole term | Results / By term, or `php artisan results:analysis` for every class at once |
| **Result histogram** | The analysis as bar charts, printable filled or blank to colour in | Results / By term, or `php artisan results:analysis` |
| **Students Daily Attendance** | Monthly summary: boys/girls present/absent per day, weekly + monthly totals | Attendance register |
| **NAT scores listing** | Official candidate sheet with mastery/fail shading (absentees optionally hidden) | NAT screen |
| **NAT analysis** | Per-sex fail/pass/mastery counts, percentages and averages per subject, with bar charts | NAT screen |
| **Instructional hours — data sheet** | Weekly Expected / Actual / Lost hours log (Expected computed from the timetable) | Timetable → Instructional hours |
| **Instructional hours — analysed chart** | Achieved-vs-lost hours bar chart per week | Timetable → Instructional hours |
| **Timetable** | The class's weekly grid as a printable PDF | Timetable |

All PDFs carry the school's name and logo (configurable in Settings) and are rendered
on-device with DomPDF — no internet involved.

## Features

- **Student data** — profiles, guardian contacts, per-year enrollments. Admins can reset any
  password; the user must then choose a new one at next login.
- **Two login flows** — staff log in with a type-to-search account picker (scales to large
  staff lists) + password; students use a passwordless **grade → class → name** picker (the
  class step only appears when a grade runs multiple classes).
- **Scorebook** — score entry per class and term, in the school's own rhythm: **months
  mode** (public schools: each calendar month is a Test or Exam out of 100) or **tests
  mode** (Test 1, Test 2 and the Exam per term, on fixed scales — tests out of 25, the
  exam out of 75). One grid per period with a pinned header and autosave moments after
  each change; every pupil gets a score *or* an explicit absent mark, and untouched
  subjects are visibly "not started". See [docs/scorebook-redesign.md](docs/scorebook-redesign.md).
- **Class positions** — pupils ranked by term total (ties share a place); the same total
  drives the report's Position column. Subjects can be flagged **"does not count toward
  total"** so Arts or P.E. stay on the report without affecting the ranking. See
  [docs/report-book-design.md](docs/report-book-design.md).
- **Prepare reports** — the report-time screen: pupils listed in rank order, and per pupil
  staff type the conduct and general remark (autosaved), which then print on the card
  instead of a hand-written blank. Warns when a subject has marks in one column but not
  the other, and offers to convert empty cells in started assessments into proper
  absences in one click — an empty cell is skipped in the average while absent counts
  as 0, so forgotten gaps would otherwise quietly flatter the report.
- **Attendance** — a daily per-class register (present / absent / late / excused). Year
  totals roll up into the Report Book's *Time present / Time absent* fields; months roll up
  into the ministry summary above.
- **Timetables** — a weekly per-class grid (subject + optional teacher per slot) on a
  school-wide period structure, with multi-period blocks, a read-only view with an *Edit*
  toggle, and a stacked day view on phones. See [docs/timetables-design.md](docs/timetables-design.md).
- **Instructional hours** — per class and month, teachers log Actual and Lost hours per day;
  Expected hours are computed from the timetable, so only the deviation needs typing.
- **National Assessment Test** — first-class support for The Gambia's WAEC census (Grades 3,
  5, 8). Term-less and kept separate from the everyday scorebook; which grades sit it, in
  which subjects, out of what maximum is configurable per school year and carried forward on
  rollover. Mastery/fail thresholds come from the school's grading scale; absentees are
  excluded from statistics. See [docs/nat-analysis-design.md](docs/nat-analysis-design.md).
- **Health records** — per-visit observations (height, weight, condition, worm treatment)
  plus once-and-done milestones (vaccines, first menstruation) that the visit form only asks
  once. Includes wound-care cases, incident logging with follow-ups (medication given,
  parents contacted, open/closed), and WHO-reference growth charts.
- **Lesson plans** — a digital version of the school's paper template, with sign-off slots
  for the assistant coordinator and the coordinator (headmaster).
- **Skills / Progress** — per-student progress analytics and skill dashboards.
- **Content mapping & Student Learn** *(experimental)* — attach Kolibri exercises and
  videos to each skill in the curriculum (exercises become practice, videos become teaching
  resources; rejected content can be set aside with a remark for the next mapper), and
  surface it to students as an exercise dashboard. Ships with the real Gambia mathematics
  curriculum as an installable fixture. Delivered through the separate `kubo-kolibri`
  package; requires a running Kolibri server.
- **School settings** — academic structure (grades, subjects, terms), classes & teachers,
  the grade key, timetable periods, report type, school logo, module toggles, and a
  role/permission matrix.
- **School-year rollover** — create the next year, copy the class/teacher/subject setup
  forward, promote or repeat pupils, carry the NAT configuration over.
- **Backups** — download a database backup from the admin UI; restore with
  `php artisan kubo:restore` (see [Backups & restore](#backups--restore)).

## Modules

A school's headmaster toggles modules on or off from **Settings → Modules**. This governs
menu visibility only (the role checks below remain the real access floor). Two core modules
are always on because disabling them would orphan data.

| Module | Always on | What it covers |
|---|---|---|
| **Students** | yes | Student profiles, contacts, enrollments. |
| **Grades & Assessments** | yes | Tests, exams, the scorebook, term reports, positions, attendance, timetables, instructional hours, and the NAT. |
| **Progress** | no | Per-student progress analytics and dashboards. |
| **Lesson Plans** | no | Daily lesson planning with coordinator sign-off. |
| **Kolibri Library** | no | *Experimental.* The content mapper: attach Kolibri exercises and videos to the curriculum. |
| **Student Learn** | no | *Experimental.* Student-facing exercise dashboard backed by mapped Kolibri content. |
| **Health** | no | Student health reports, milestones, wound cases and incidents. |

Definitions live in `config/modules.php`; the per-school enabled set is stored in
`school_configs.enabled_modules` and read through `App\Modules\Registry`.

## Roles

Authorization is handled by [`spatie/laravel-permission`](https://spatie.be/docs/laravel-permission);
a user can hold multiple roles. The full model is documented in
[docs/access-model.md](docs/access-model.md).

| Role | Who holds it | What they can do |
|---|---|---|
| `student` | Every enrolled child | Log in via the name picker, do exercises, see their own progress. No admin access. |
| `teacher` | Class teachers and subject teachers | Manage students in their assigned classes, enter scores, take attendance, log hours, write lesson plans, run reports. |
| `caregiver` | Health staff | Read and write health records for any student. No access to academic data. |
| `headmaster` | The school principal | Read everything academic, full edit rights, signs off lesson plans as coordinator. |
| `assistant_coordinator` | Sub-coordinator | Read all lesson plans and sign off their remarks field only. |
| `admin` | Whoever runs deployments / IT | User management, roles, rollover, backups, settings. |

Worth knowing:

- **Health access is per person, not just per role** — an admin can grant any staff member
  health access from the Users page without changing their role.
- **Terms lock when they end** — teachers can no longer change a past term's scores;
  headmasters and admins still can (with a warning).
- **The staff login list stays tidy** — teachers without a class in the current school year
  are hidden from the account picker.
- **"Principal" means class lead**, not a job title: the lead teacher of a class, who may be
  a subject teacher elsewhere.

## Accessibility

The UI targets **WCAG 2.1 AA**:

- Text meets the AA contrast minimum (4.5:1) — muted text uses `text-gray-500` or darker,
  and the per-grade class colour palette keeps every background/text pair ≥ 4.5:1.
- Form controls have an accessible name (a `<label>`, or `aria-label` for per-row controls).
- Interactive controls use the right element: `<button>` for actions, `<a>` for navigation;
  tab strips use `role="tab"`/`aria-selected`; icon-only buttons carry an `aria-label`.
- The base layout provides a skip-to-content link, sets `lang`, and marks decorative icons
  `aria-hidden`.

The pagination views under `resources/views/vendor/{pagination,livewire}` are patched copies
of the framework defaults (disabled prev/next spans get `role="link"` so their ARIA is
valid); re-sync them on major Laravel/Livewire upgrades. When changing UI, sanity-check with
[axe DevTools](https://www.deque.com/axe/devtools/) and keep new violations at zero.

## Tech stack

Laravel 13 (PHP 8.3+) · Livewire 3 · Alpine.js · Vue 3 · Tailwind CSS v4 · MySQL (SQLite
in-memory for tests) · DomPDF.

## Development

```bash
composer install && npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed        # full demo school: 3 years of classes, scores, NAT, health data
npm run dev                # or: npm run build
php artisan serve
```

`db:seed` builds a realistic demo school for local development. Tests:

```bash
php ./vendor/bin/phpunit                                      # all
php ./vendor/bin/phpunit tests/Feature/TermResultTest.php     # one file
php ./vendor/bin/phpunit --filter test_method_name            # one method
```

### New (production) installs — the setup wizard

A fresh instance with **no school** shows a first-run setup wizard at `/install` (any other
URL redirects there until setup is done). It walks the school through their details, academic
structure and the first headmaster account, then signs them in. The Gambia option pre-loads
grades, the WAEC subject set and the NAT; other countries start blank.

Provision a real install **without** the demo seeders:

```bash
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder   # roles only, no school/users
# open the app → the wizard at /install takes over
```

For deploying to a device, scheduling backups and routine maintenance, see the
**[operations guide](docs/operations.md)**.

### Deploying an update

Front-end assets are **built in CI and committed** to `public/build` (see
`.github/workflows/build-assets.yml`), so servers ship prebuilt assets and never need Node.
A push to `main` that touches the front end triggers the build, which commits the compiled
assets back to `main`.

On the server, `deploy.sh` pulls the latest code and assets, installs PHP dependencies, runs
migrations, warms the caches and reloads php-fpm. It builds nothing, so it is fast and cannot
fail on an asset build.

First time only, make it executable (either works, the second also records the bit in git):

```bash
chmod +x deploy.sh
# or, to commit the executable bit:  git update-index --chmod=+x deploy.sh
```

Then, as the app user (e.g. `kubo`) from the app directory:

```bash
./deploy.sh              # deploy origin/main
./deploy.sh some-branch  # deploy a specific branch
```

It puts the site in maintenance mode for the switch and always lifts it again, even on error.
`git reset --hard` means the server tracks `origin` exactly, so local drift (e.g. stale built
assets) never blocks a pull. The app user needs passwordless `sudo systemctl reload …` for
php-fpm and nginx.

### Backups & restore

A headmaster downloads a full database backup (`kubo-YYYY-MM-DD-His.sql.gz`) from
**Backups** in the admin UI. Schedule regular off-device copies (e.g. to a USB drive), and
point the backup cron at `POST /api/backup/report` so the dashboard can show backup health
(that endpoint is guarded: loopback, or a request bearing `BACKUP_REPORT_TOKEN`).

To **restore** a backup onto the device — destructive, it replaces the current data:

```bash
php artisan kubo:restore /path/to/kubo-2026-06-25-120000.sql.gz
```

It writes a **safety snapshot** of the current database first, asks for confirmation, then
imports. Flags: `--dry-run`, `--force`, `--no-snapshot`, `--connection=`.

## Documentation

| Doc | What it covers |
|---|---|
| [docs/operations.md](docs/operations.md) | Deploying to a device, backups, restore, routine maintenance |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |
| [SPEC.md](SPEC.md) | Full platform specification extracted from the codebase |
| [docs/access-model.md](docs/access-model.md) | Modules, roles & permissions in detail |
| [docs/scorebook-redesign.md](docs/scorebook-redesign.md) | Scorebook flow and score-entry rules |
| [docs/report-book-design.md](docs/report-book-design.md) | Report book, positions & the term total |
| [docs/timetables-design.md](docs/timetables-design.md) | Periods, lessons and the timetable grid |
| [docs/nat-analysis-design.md](docs/nat-analysis-design.md) | NAT scores & analysis deliverables |
| [docs/kubo-school-levels.md](docs/kubo-school-levels.md) | Groundwork for school levels beyond lower basic *(not built)* |

## License

KUBO is free software under the **GNU Affero General Public License v3.0 or later** —
see [LICENCE.md](LICENCE.md) for a summary and [agpl-3.0.txt](agpl-3.0.txt) for the full text.
