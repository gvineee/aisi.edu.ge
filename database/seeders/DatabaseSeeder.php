<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Local/dev only — see TenantSeeder's own docblock. Model events are
     * deliberately NOT disabled here: BelongsToTenant relies on Eloquent's
     * `creating` event to stamp tenant_id, and the tenant relation calls
     * used below rely on the same event lifecycle.
     */
    public function run(): void
    {
        $this->call(TenantSeeder::class);
    }
}
