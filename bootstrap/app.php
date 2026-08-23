<?php

use App\Console\DevCommands\IdeHelperModelsCommand;
use App\Http\Middleware\EnsureMfaIsVerified;
use Barryvdh\LaravelIdeHelper\Console\ModelsCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'mfa.verified' => EnsureMfaIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    });

if (class_exists(ModelsCommand::class)) {
    $application->withCommands([IdeHelperModelsCommand::class]);
}

return $application->create();
