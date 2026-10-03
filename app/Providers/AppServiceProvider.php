<?php

namespace App\Providers;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Uploads\ClamdFileScanner;
use App\Domain\Uploads\FileScanner;
use App\Domain\Uploads\NullFileScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
