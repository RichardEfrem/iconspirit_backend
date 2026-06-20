<?php

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Flush the array cache + the rate limiter so throttle counters from
    // prior tests don't leak into this one.
    Cache::flush();
    app(RateLimiter::class)->clear('login');

    // Minimal users for the RBAC matrix — one per role.
    foreach (User::ROLES as $role) {
        User::factory()->create([
            'username' => $role,
            'email'    => "{$role}@iconspirit.test",
            'password' => Hash::make('password'),
            'role'     => $role,
            'status'   => User::STATUS_ACTIVE,
        ]);
    }
});

test('unauthenticated requests to protected api return 401', function () {
    $this->getJson('/api/production/order')
        ->assertStatus(401)
        ->assertJson(['success' => false]);
});

test('login with valid credentials succeeds and returns user payload', function () {
    $this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.user.username', 'admin')
        ->assertJsonPath('data.user.role', User::ROLE_ADMIN);
});

test('login with bad password returns 401', function () {
    $this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'wrong-password',
    ])->assertStatus(401);
});

test('deactivated users cannot authenticate', function () {
    User::where('username', 'operator')->update(['status' => User::STATUS_INACTIVE]);

    $this->postJson('/api/auth/login', [
        'username' => 'operator',
        'password' => 'password',
    ])->assertStatus(403);
});

test('login is throttled after repeated failed attempts within a minute', function () {
    // The throttle middleware keys on a hash of method+host+path+ip, which isn't
    // cleanly resettable between Pest tests. So instead of asserting the exact
    // attempt at which 429 fires, just verify that within the configured window
    // the limiter eventually blocks — the contract we care about.
    $sawThrottle = false;
    for ($i = 0; $i < 10; $i++) {
        $status = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'nope',
        ])->getStatusCode();

        if ($status === 429) {
            $sawThrottle = true;
            break;
        }
        expect($status)->toBe(401);
    }

    expect($sawThrottle)->toBeTrue();
});

test('inventory role cannot reach production write endpoints', function () {
    $this->actingAs(User::where('username', 'inventory')->first());

    // Master data write requires admin/operator.
    $this->postJson('/api/production/customer/add', [
        'nama' => 'Test',
    ])->assertStatus(403);
});

test('owner role is read-only on production', function () {
    $this->actingAs(User::where('username', 'owner')->first());

    $this->getJson('/api/production/order')->assertOk();

    $this->deleteJson('/api/production/order/9999/delete')->assertStatus(403);
});

test('operator role can run scheduling but inventory role cannot', function () {
    $this->actingAs(User::where('username', 'inventory')->first())
        ->postJson('/api/production/order/run-scheduling')
        ->assertStatus(403);

    $this->actingAs(User::where('username', 'operator')->first())
        ->postJson('/api/production/order/run-scheduling')
        ->assertOk(); // No pending items, but the route+role are reachable.
});

test('only admin can manage users; owner is read-only', function () {
    $this->actingAs(User::where('username', 'owner')->first())
        ->postJson('/api/users', [
            'username' => 'newbie',
            'name'     => 'Newbie',
            'email'    => 'newbie@iconspirit.test',
            'password' => 'password',
            'role'     => User::ROLE_OPERATOR,
        ])->assertStatus(403);

    $this->actingAs(User::where('username', 'owner')->first())
        ->getJson('/api/users')
        ->assertOk();

    $this->actingAs(User::where('username', 'operator')->first())
        ->getJson('/api/users')
        ->assertStatus(403);
});
