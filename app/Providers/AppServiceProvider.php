<?php

namespace App\Providers;

use App\Domain\Platform\OperationsHealth;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Uploads\ClamdFileScanner;
use App\Domain\Uploads\FileScanner;
use App\Domain\Uploads\NullFileScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
        // NullFileScanner (`skipped`) until an engine is configured; see config/uploads.php.
        $this->app->bind(FileScanner::class, fn () => config('uploads.scanner') === 'clamd'
            ? new ClamdFileScanner((string) config('uploads.clamd_address'), (int) config('uploads.clamd_timeout'))
            : new NullFileScanner);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // `/up` reports background work too: a stalled scheduler or an
        // unworked queue turns it into a 500 for the uptime monitor.
        Event::listen(DiagnosingHealth::class, function () {
            $problems = app(OperationsHealth::class)->problems();

            if ($problems !== []) {
                throw new RuntimeException('Operations unhealthy: '.implode('; ', $problems));
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
