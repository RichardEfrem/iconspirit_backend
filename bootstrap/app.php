<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->throttleApi('1000000,1'); // BENCH SEMENTARA — KEMBALIKAN ke '300,1' setelah uji (asli: 300 req/menit per user/IP)
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Auto-start stations whose scheduled slot has arrived, so production
        // doesn't stall waiting on a manual click. withoutOverlapping guards
        // against a slow tick overlapping the next; the command also takes the
        // global scheduling lock internally.
        $schedule->command('production:auto-start-queues')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();
