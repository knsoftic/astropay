<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class MakeAdminCommand extends Command
{
    protected $signature = 'app:make-admin {email : Email of the user to promote (created if missing)} {--name= : Name for a new user}';

    protected $description = 'Grant admin access (approve withdrawals, resolve reviews, UTR tools)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Enter a valid email address.');

            return self::INVALID;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $name = (string) ($this->option('name') ?: $this->ask('Name'));
            $password = (string) $this->secret('Password (min. 8 characters)');
            $confirmation = (string) $this->secret('Confirm password');

            $validator = Validator::make(
                ['name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
                ['name' => ['required', 'string', 'max:100'], 'password' => ['required', 'confirmed', Password::defaults()]],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::INVALID;
            }

            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $this->info("User {$email} created.");
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->info("{$email} is now an admin.");

        return self::SUCCESS;
    }
}
