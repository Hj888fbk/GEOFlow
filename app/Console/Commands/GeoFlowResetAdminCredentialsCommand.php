<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recover an existing administrator without exposing the new password in the
 * process list or command output.
 */
final class GeoFlowResetAdminCredentialsCommand extends Command
{
    /** @var string */
    protected $signature = 'geoflow:admin-reset-credentials
        {username : Existing administrator username}
        {--new-username= : Replacement username; defaults to the existing username}
        {--password-stdin : Read the new password from standard input}';

    /** @var string */
    protected $description = 'Reset an existing administrator username and password, then revoke existing credentials';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $newUsername = trim((string) ($this->option('new-username') ?: $username));
        $password = $this->readPassword();

        if ($username === '' || ! preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $newUsername)) {
            $this->error('The administrator username must be 3-50 characters using letters, numbers, dot, underscore, or hyphen.');

            return self::INVALID;
        }

        if (mb_strlen($password) < 8) {
            $this->error('The new password must contain at least 8 characters.');

            return self::INVALID;
        }

        $result = DB::transaction(function () use ($username, $newUsername, $password): string {
            /** @var Admin|null $admin */
            $admin = Admin::query()->where('username', $username)->lockForUpdate()->first();
            if (! $admin) {
                return 'missing';
            }

            $conflict = Admin::query()
                ->where('username', $newUsername)
                ->whereKeyNot($admin->getKey())
                ->exists();
            if ($conflict) {
                return 'conflict';
            }

            $admin->forceFill([
                'username' => $newUsername,
                'password' => $password,
                'status' => 'active',
            ])->save();
            $admin->revokeAuthenticationCredentials();

            return 'updated';
        });

        if ($result === 'missing') {
            $this->error('The requested administrator does not exist.');

            return self::FAILURE;
        }

        if ($result === 'conflict') {
            $this->error('The replacement username already belongs to another administrator.');

            return self::FAILURE;
        }

        $this->info('Administrator credentials were reset and all existing sessions and tokens were revoked: '.$newUsername);

        return self::SUCCESS;
    }

    private function readPassword(): string
    {
        if (! $this->option('password-stdin')) {
            return (string) $this->secret('New password');
        }

        $value = stream_get_contents(STDIN);

        return rtrim(is_string($value) ? $value : '', "\r\n");
    }
}
