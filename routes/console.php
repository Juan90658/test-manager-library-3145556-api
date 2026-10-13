<?php

use App\Application\Services\BootstrapAdminService;
use Illuminate\Support\Facades\Artisan;

/*
 * Startup (plan D-10, T-17): creates the initial admin from ADMIN_USERNAME / ADMIN_PASSWORD.
 * Idempotent: does nothing if it already exists. The password is never printed.
 */
Artisan::command('library:bootstrap-admin', function (BootstrapAdminService $svc) {
    $user = (string) config('library.admin_username');
    $pass = (string) config('library.admin_password');
    if ($user === '' || $pass === '') {
        $this->error('ADMIN_USERNAME and/or ADMIN_PASSWORD are missing from the environment.');

        return 1;
    }
    $this->info($svc->ensure($user, $pass) ? "Admin '{$user}' created." : "Admin '{$user}' already exists.");

    return 0;
})->purpose('Creates the initial admin from environment variables');
