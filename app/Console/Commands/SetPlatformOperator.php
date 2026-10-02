<?php

namespace App\Console\Commands;

use App\Domain\Platform\Models\PlatformOperator;
use App\Domain\Platform\PlatformAudit;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or revokes platform operator status. Server-side only (there is
 * deliberately no web path to become an operator); always audited.
 */
class SetPlatformOperator extends Command
{
    protected $signature = 'platform:operator {email} {--revoke} {--reason=}';

    protected $description = 'Grant or revoke platform operator access for an existing user (audited)';

    public function handle(PlatformAudit $audit): int
    {
        $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $operator = PlatformOperator::query()->firstOrNew(['user_id' => $user->id]);
        $operator->is_active = ! $this->option('revoke');
        $operator->save();

        $audit->record(null, $operator->is_active ? 'operator.granted' : 'operator.revoked', null, [
            'user_id' => $user->id,
            'reason' => $this->option('reason'),
            'via' => 'console',
        ]);

        $this->info($user->email.': '.($operator->is_active ? 'operator' : 'revoked'));

        if ($operator->is_active && $user->two_factor_confirmed_at === null) {
            $this->warn('This user has no confirmed 2FA yet — platform administration stays closed until it is enabled.');
        }

        return self::SUCCESS;
    }
}
