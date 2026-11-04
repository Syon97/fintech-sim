<?php

namespace App\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuthAudit
{
    public static function record(string $event, ?string $username, Request $request): void
    {
        DB::table('auth_events')->insert([
            'event' => $event,
            'username' => $username !== null ? substr($username, 0, 64) : null,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}