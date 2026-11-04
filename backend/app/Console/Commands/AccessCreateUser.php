<?php

namespace App\Console\Commands;

use App\Access\Permissions;
use App\Models\User;
use Illuminate\Console\Command;

class AccessCreateUser extends Command
{
    protected $signature = 'access:create-user {username} {role} {--name=}';
    protected $description = 'Create a user (analyst, supervisor or rule_admin). The password is prompted, never stored in source.';

    public function handle(): int
    {
        $username = strtolower(trim((string) $this->argument('username')));
        $role = (string) $this->argument('role');

        if (! array_key_exists($role, Permissions::ROLES)) {
            $this->error('Role must be one of: ' . implode(', ', array_keys(Permissions::ROLES)));
            return self::FAILURE;
        }
        if (! preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
            $this->error('Username must be 3 to 64 characters: lowercase letters, digits, dot, underscore or hyphen.');
            return self::FAILURE;
        }
        if (User::where('username', $username)->exists()) {
            $this->error("Username '$username' already exists.");
            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters)');
        if (strlen($password) < 12) {
            $this->error('That password is too short.');
            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Confirm password')) {
            $this->error('The passwords did not match.');
            return self::FAILURE;
        }

        $user = new User();
        $user->name = $this->option('name') ?: ucfirst(explode('.', $username)[0]);
        $user->email = "$username@fintech-sim.test"; // synthetic: the app never emails anyone
        $user->username = $username;
        $user->role = $role;
        $user->active = true;
        $user->password = $password; // hashed by the model cast
        $user->save();

        $this->info("Created $username ($role).");

        return self::SUCCESS;
    }
}