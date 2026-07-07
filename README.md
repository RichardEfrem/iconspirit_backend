# IconSpirit — Backend (Laravel API)

The backend is a **stateless JSON API** for a furniture / woodworking factory's
production floor. It owns all business logic: order intake, inventory, the
**scheduling engine**, the live-execution runtime, and reporting. It renders no HTML —
the Next.js frontend is a separate app that talks to it over REST.

- **Stack:** Laravel (PHP) · PostgreSQL · Laravel Sanctum · Pest (tests)
- **API prefix:** everything lives under `/api/*`
- **Domain language is Indonesian:** *SPK* = Surat Perintah Kerja (work order),
  *kayu* = wood, *cat* = paint, *acc* = accessories.

---

## 1. Architecture — Layered by Responsibility

Each request flows through clearly separated layers so HTTP concerns, business rules,
and persistence never bleed into one another:

```
routes/api.php          URL + verb, attaches auth / role / active middleware
      │
Controller  (app/Http/Controllers/Api/**)
      │   validates input, delegates, shapes the JSON response — stays THIN
      ▼
Service     (app/Services/**)
      │   the real business logic; reusable and unit-testable in isolation
      ▼
Eloquent Model (app/Models/**)
      │   ORM mapping + relationships
      ▼
PostgreSQL
```

**The important rule:** controllers hold no logic worth testing — they parse and
format. All decisions live in **services**. This is what makes the scheduling logic
testable without spinning up HTTP.

### Directory map (the parts that matter)
```
app/
├── Http/
│   ├── Controllers/Api/        REST controllers (Auth, Dashboard, User,
│   │                           Inventory/*, Production/*)
│   └── Middleware/
│       ├── EnsureUserHasRole   → alias "role"   (RBAC gate)
│       └── EnsureUserIsActive  → alias "active" (blocks deactivated accounts)
├── Services/
│   ├── Production/
│   │   ├── SchedulingService.php          ← the PLANNER (Pillar #1)
│   │   ├── SchedulingSequenceTrait.php    ← the NEH + EDD sequencing logic
│   │   ├── ProductionScheduleService.php  ← the RUNTIME executor
│   │   ├── ProductionOrderService.php     ← order lifecycle
│   │   ├── SpkService.php, *ItemService, *MaterialService, Location/Product/…
│   ├── Inventory/MaterialService.php      ← stock
│   ├── CustomerService.php, UserService.php
├── Console/Commands/AutoStartReadyQueues.php   ← runs every minute
├── Models/                     Eloquent entities (see §4)
└── Observers/                  model event hooks
config/production.php           ← ALL scheduling constants live here
bootstrap/app.php               ← middleware, schedule, exception wiring
routes/api.php                  ← the full API surface
```

---

## 2. Authentication & Authorization

### Sanctum SPA mode (cookie session, NOT bearer tokens)
Auth is **Laravel Sanctum in SPA mode**, wired in `bootstrap/app.php` via
`$middleware->statefulApi()`:

1. The frontend requests `/sanctum/csrf-cookie`; Laravel sets an `XSRF-TOKEN` cookie.
2. `POST /api/auth/login` starts a **session** and sets an `HttpOnly` session cookie.
   The controller uses the `web` guard and regenerates the session.
3. Every later request carries the session cookie automatically; non-`GET` requests
   must echo the CSRF token in the `X-XSRF-TOKEN` header. Sanctum's stateful
   middleware only activates when the request's `Origin`/`Referer` matches a
   configured stateful domain — which is why the frontend forwards those headers.
4. `GET /api/auth/me` returns the current user; `POST /api/auth/logout` invalidates
   the session.

Credentials never reach JavaScript (the session cookie is `HttpOnly`), which is the
main security win over bearer tokens.

Auth failures on `api/*` are rendered as a clean `401 { success:false, message }` via
a custom exception handler in `bootstrap/app.php` (no HTML redirect).

### RBAC — four roles, enforced server-side
Protected routes stack `auth:sanctum` + `active` + a `role:` gate. The UI only *hides*
what a role can't use; the backend is the real enforcer.

| Role | Capabilities |
| --- | --- |
| `admin` | Everything, incl. user management |
| `operator` | Production + reporting (write); inventory read-only |
| `inventory` | Inventory / material CRUD only |
| `owner` | Read-only across the whole system |

> **Note:** `routes/api.php` currently throttles the API at a huge benchmark limit
> (`throttleApi('1000000,1')`) with a comment to restore it to `300,1`. Restore the
> real limit before production.

---

## 3. The API Surface (grouped)

All under `/api`. Verbs carry intent; **algorithms/workflow steps are exposed as
resource actions** rather than inventing new verbs.

- **Auth** — `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`
- **Dashboard** — `GET /dashboard` (aggregated KPIs; inventory role excluded)
- **Users** — CRUD + `PATCH /users/{id}/status` (admin write, owner read)
- **Inventory** — `GET /inventory/material`, `.../stock`, `.../{id}`;
  `POST /inventory/material`, `PATCH /inventory/material/{id}/stock`
- **Production master data** — `factory-location`, `product`, `customer`, `station`,
  `team` (all list/show/add/update/delete)
- **Production orders** — `production/order` CRUD **plus the key actions:**
  - `POST /production/order/run-scheduling` — **triggers the planner**
  - `PATCH /production/order/{id}/await-material`
  - `PATCH /production/order/{id}/confirm-material-arrival` (+ `batch-confirm-material`)
  - `PATCH /production/order/{id}/extend-material-eta`
- **Order items / sections / materials / images** — nested CRUD;
  materials add `PATCH …/deduct` and `PATCH …/restore` (stock movement)
- **SPK** — list/show + `POST /production/spk/add` (generates the work order)
- **Schedule (runtime & reporting):**
  - `GET …/schedule/order/{id}` and `.../progress`
  - `GET …/schedule/ongoing-progress`, `.../team-schedules`,
    `.../await-material-schedules`
  - `GET …/schedule/finished-summary` — cost/timing for the report
  - `PATCH …/schedule/{id}/status` — **operators mark a station `completed`**
    (see §7 — this route only accepts `completed`)

---

## 4. Domain Model

```
Customer / Product                       master data
ProductionOrder                          the top-level order
 ├─ ProductionOrderItem                  a line item (product, panjang × tinggi, qty)
 │   ├─ ProductionOrderItemSection       logical grouping of parts
 │   ├─ ProductionOrderItemMaterial      materials consumed  (is_deducted flag!)
 │   └─ ProductionOrderItemImage         reference photos
 └─ Spk                                  printable work order → assigned factory
FactoryLocation                          a factory (has priority)
 ├─ Station                              kayu → cat → acc stages inside a factory
 └─ Team                                 the crew = the capacity unit slots book
ProductionSchedule                       ONE station slot: item @ station by team,
                                         start→end, own status  (scheduler output)
Material                                 inventory item (code, qty, price, category)
ProductionStatus                         status lookup (new … finished)
```

**The material guard:** an item is only truly "ready to build" once its materials are
deducted from stock (`is_deducted = true`). This prevents scheduling work the factory
can't physically start.

---

## 5. Production Lifecycle (the state machine)

```
 new ─▶ await_material ─▶ await_scheduling ─▶ on_going ─▶ finished
  └───────────────┴──────────────▶ cancelled
```

| Status | What it means | Driven by |
| --- | --- | --- |
| `new` | Order + items created, materials attached | order/item create |
| `await_material` | Parked until raw materials arrive; a **material ETA** is recorded | `await-material` action |
| `await_scheduling` | Ready to plan | material confirmed |
| `on_going` | Planner produced a concrete timeline; production is live | `run-scheduling` |
| `finished` | Every station complete; rolls into the report | runtime auto-finish |

Material arrival is confirmed with `confirm-material-arrival` (single or batch), and a
slipping ETA can be pushed with `extend-material-eta`.

---

## 6. The Scheduling Engine — Pillar #1 (Planning)

**The problem:** every item passes through the same ordered stations
(kayu → cat → acc). Choosing the job order that finishes fastest **without missing
deadlines** is a **Permutation Flow Shop Scheduling Problem (PFSP)** — NP-hard. So the
engine (`SchedulingService` + `SchedulingSequenceTrait`) uses a **hybrid heuristic**,
triggered by `POST /production/order/run-scheduling`.

### Step 1 — Estimate each job's duration (from real dimensions)
```
duration ≈ base_production_minutes / item_count
         + (panjang × tinggi in m²) × qty × minutes_per_m²
```
That total is split across the three stations by fixed ratios
(**kayu 0.4 / cat 0.4 / acc 0.2**). Every tunable — `minutes_per_m2`,
`work_minutes_per_day`, `station_split`, `critical_window_days`, `buffer_days`,
`tardiness_weight`, `calendar_penalty`, `neh_seed` — lives in **`config/production.php`**,
so behavior is tuned without touching code.

### Step 2 — Decide the job order: a 5-way partition of NEH + EDD
Items are grouped **per factory** (from the SPK's assigned factory, fallback factory 1),
then sequenced with a five-band partition that mixes two classic rules:

| Band | Rule | Reason |
| --- | --- | --- |
| 1. Urgent orders (`is_urgent`) | **EDD** | Flagged work always goes first, deadline-ordered |
| 2. On-going, deadline-critical | **EDD** | Protect tight deadlines |
| 3. On-going, normal | **NEH** | Compress makespan for roomy jobs |
| 4. Await-material, critical | **EDD** | Same deadline protection, not-yet-started |
| 5. Await-material, normal | **NEH** | Compress makespan |

- **EDD** (Earliest Due Date) → best at **hitting deadlines**.
- **NEH** (Nawaz–Enscore–Ham, O(n³m)) → greedily builds the sequence to **minimize
  makespan** (total finish time).

Design intent: **critical work is routed through EDD, slack work through NEH.** A job
is classified the *same way* whether it's already on-going or still awaiting material,
so confirming material does **not** silently re-order an already-planned job. NEH runs
**separately** on the on-going-normal vs await-normal groups, and the invariant that
on-going work dispatches ahead of await-material work is preserved.
`critical_window_days` (currently 14) is the real deadline dial: widening it pulls more
tight orders into the deadline-safe EDD bands.

> Empirically (see the Pest comparison tests): NEH buys ~1 working day of makespan but
> can trade away deadline adherence under contention, while EDD misses zero. The
> critical window — not the NEH seed — is the effective knob for deadline safety.

### Step 3 — Map the sequence onto the real calendar
The sequence becomes concrete `ProductionSchedule` slots:
- Each of the 3 stations is assigned a **team** via greedy earliest-available.
- **Working hours** are respected (Mon–Fri 08:00–17:00) — never nights/weekends.
- **Material ETA** is respected — an await-material item is *planned* but its slots
  can't begin before its materials land.

### Reacting to material events
- **Early arrival** → shift slots earlier to reclaim freed time.
- **Late arrival** → reschedule the affected work **globally** so the whole plan stays
  feasible and consistent.

Concurrency: scheduling runs under a global cache lock (`scheduling:global`) so two
runs — or a run and a runtime tick — never interleave.

---

## 7. The Runtime Executor — Pillar #1 (Execution)

A plan on paper isn't enough; the floor needs it to advance on its own.
`ProductionScheduleService` plus a **scheduled command** do this. Stations move
`pending → in_progress → completed`.

- **Auto-start:** `AutoStartReadyQueues` (`production:auto-start-queues`) is registered
  in `bootstrap/app.php` to run **`everyMinute()->withoutOverlapping()`**. It promotes
  any station whose slot time has arrived and whose team is free from `pending` to
  `in_progress`. This is why work keeps flowing even when a slot only becomes eligible
  at a future time or a shared cat/acc team frees up — nothing waits on a human click.
- **Start gates:** a station starts only when (0) `now >= start_time` and (1) its team
  isn't already busy (FIFO per team) — so a crew is never double-booked.
- **On completion:** when `PATCH …/schedule/{id}/status` marks a station `completed`,
  the service promotes the next station in the item's chain and **reflows** any
  knock-on slots; the last station's completion auto-finishes the order.
- **Concurrency:** both the auto-start tick and the completion handler take the same
  `scheduling:global` lock, so a background tick can't race a live operator action.

> **Important behavior:** the manual status route validates **`in:completed` only**.
> The `pending → in_progress` transition is owned entirely by the auto-start
> machinery — operators only ever mark stations **completed**. (A "start" button that
> POSTs `in_progress` would be rejected by design.)

**Deployment requirement:** the OS cron must run `php artisan schedule:run` every
minute (or `php artisan schedule:work` in dev) or nothing auto-starts.

---

## 8. Running Locally

```bash
composer install
cp .env.example .env            # DB_CONNECTION=pgsql + Sanctum/session config
php artisan key:generate
php artisan migrate             # add --seed if seeders exist

php artisan serve               # http://localhost:8000  (API at /api)
php artisan schedule:work       # separate process → enables auto-start
```

**Sanctum config that must be right for cookie auth to work:**
- `SANCTUM_STATEFUL_DOMAINS` must include the frontend origin (`localhost:3000`).
- `SESSION_DOMAIN` and CORS must permit that origin with credentials.
- The frontend must send `credentials: include` and forward `Origin`/`Referer`
  (its `serverFetch` already does this for SSR requests).

**Testing:** `php artisan test` (Pest). The scheduling engine has dedicated comparison
tests (e.g. NEH-seed and critical-window studies) and an end-to-end simulation using
real SPK data.

---

## 9. Mental Model in One Paragraph

The API receives an authenticated, cookie-secured request, a thin controller validates
it and hands off to a service, and the service applies the real rules against Eloquent
models in PostgreSQL. When an operator triggers scheduling, `SchedulingService` turns
the pending items into a concrete, working-hours-aware timeline of station slots using
a hybrid **NEH + EDD** flow-shop heuristic tuned entirely from `config/production.php`.
A once-a-minute command then walks that timeline forward automatically — starting each
station when its time and team are ready — while operators simply mark work complete,
and finished orders roll up into the cost-and-timing report.
