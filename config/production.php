<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Production Calculation Constants
    |--------------------------------------------------------------------------
    |
    | Shared constants used by SchedulingService,
    | and ProductionOrderItem for consistent time/capacity calculations.
    |
    */

    // Processing rate: 5 hours (300 minutes) per square meter
    'minutes_per_m2' => 300,

    // Base production overhead per order: 3 working days (3 × 480 = 1440 minutes)
    'base_production_minutes' => 1440,

    // Available working minutes per team per day (9 hours: 08:00–17:00)
    'work_minutes_per_day' => 540,

    // Square centimeters per square meter (conversion factor)
    'cm2_per_m2' => 10000,

    // Order deadline: 2 months from tanggal_order (default; user-editable)
    'order_deadline_months' => 2,

    // Priority factory tolerance: max days slower than fastest before fallback
    'priority_tolerance_days' => 7,

    // Priority rank value (factory with this rank is the primary factory)
    'priority_rank' => 1,

    // Universal transit delay: 1 day between any two locations (factory ↔ factory, gudang → factory)
    'transit_delay_days' => 1,

    // Buffer days added to SPK deadline for quality checks, unforeseen delays, etc.
    'spk_buffer_days' => 3,

    // SPK deadline is set this many months before the order's production_deadline
    'spk_deadline_months_before' => 1,

    // Statuses that count as "occupying" factory capacity
    'capacity_status_ids' => ['await_material', 'on_going'],

    // Station work split ratios (must sum to 1.0)
    'station_split' => [
        'kayu' => 0.4,
        'cat'  => 0.4,
        'acc'  => 0.2,
    ],

    // Days subtracted from order deadline when back-scheduling a per-item deadline
    'buffer_days' => 2,

    // Items whose item-level production_deadline falls within this many days are
    // treated as critical and sorted by EDD only (not fed through NEH).
    // Widened 7 → 14: protects tighter-deadline orders with EDD (more slack,
    // ~0.5 working-day makespan cost) instead of risking demotion in NEH.
    // See CriticalWindowComparisonTest for the 7-vs-14 evidence.
    'critical_window_days' => 14,

    // Applied to makespan inside NEH simulation to approximate weekend / off-hours
    // overhead (7 calendar days / 5 working days ≈ 1.4). Relative comparison only —
    // does not affect actual dispatch times.
    'calendar_penalty' => 1.4,

    // Weight for tardiness penalty in NEH evaluation (R4 improvement).
    // Higher = NEH avoids deadline misses more aggressively.
    // 0 = pure makespan optimization (original behavior).
    'tardiness_weight' => 2.0,

    // Minutes of handoff/transit buffer between stations (kayu → cat → acc).
    // Set to 0 to disable inter-station delays.
    'handoff_buffer_minutes' => 0,

    // NEH pre-sort seed for the 'normal' partition:
    //   'lpt' — longest-processing-first (default): classic NEH makespan seed.
    //   'edd' — earliest-deadline-first.
    // NOTE: EDD-seeding was tested (NehSeedComparisonTest) and did NOT reduce
    // deadline misses — it slightly worsened them. Deadline protection comes from
    // the critical window (EDD partition), not from the NEH seed. Kept 'lpt'.
    'neh_seed' => 'lpt',

    // A station should not begin in the last working hours of a day. If the
    // previous station finishes at or after this time, the next station starts
    // the following working morning (08:00) instead of the same day.
    // Format: 'HH:MM:SS', must be <= 17:00:00 (end of working day).
    'handoff_cutoff_time' => '16:00:00',
];
