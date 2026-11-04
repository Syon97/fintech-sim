<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    /** Sanctum only starts a session for requests that come from a known frontend. */
    private function frontend(): array
    {
        return ['Referer' => 'http://localhost:5173/'];
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['username' => 'maya.a', 'password' => self::PASSWORD]);
    }

    private function login(string $username, string $password)
    {
        return $this->postJson('/api/login', ['username' => $username, 'password' => $password], $this->frontend());
    }

    public function test_login_succeeds_and_is_audited(): void
    {
        $user = $this->user(['role' => 'supervisor']);

        $this->login('maya.a', self::PASSWORD)
            ->assertOk()
            ->assertJsonPath('username', 'maya.a')
            ->assertJsonPath('role', 'supervisor')
            ->assertJsonFragment(['permissions' => ['alerts.view', 'alerts.work', 'alerts.reassign', 'cases.view', 'cases.manage', 'rules.view']]);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, DB::table('auth_events')->where('event', 'login')->where('username', 'maya.a')->count());
    }

    public function test_username_is_normalised_at_login(): void
    {
        $this->user();

        $this->login('  Maya.A ', self::PASSWORD)->assertOk();
    }

    public function test_wrong_password_unknown_user_and_inactive_user_get_the_same_answer(): void
    {
        $this->user();
        $this->user(['username' => 'old.timer', 'active' => false]);

        $wrong = $this->login('maya.a', 'not the password');
        $unknown = $this->login('nobody.here', self::PASSWORD);
        $inactive = $this->login('old.timer', self::PASSWORD);

        foreach ([$wrong, $unknown, $inactive] as $r) {
            $r->assertStatus(422);
        }
        $this->assertSame($wrong->json('errors'), $unknown->json('errors'));
        $this->assertSame($wrong->json('errors'), $inactive->json('errors'));
        $this->assertGuest();
        $this->assertSame(3, DB::table('auth_events')->where('event', 'login_failed')->count());
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->login('maya.a', 'wrong password')->assertStatus(422);
        }

        $this->login('maya.a', self::PASSWORD)->assertStatus(429); // even the right password is refused now
    }

    public function test_unauthenticated_requests_get_a_json_401(): void
    {
        $this->get('/api/me')->assertStatus(401)->assertJsonStructure(['message']); // no Accept header on purpose
    }

    public function test_me_lists_the_roles_permissions(): void
    {
        $this->actingAs($this->user(['role' => 'rule_admin']))
            ->getJson('/api/me', $this->frontend())
            ->assertOk()
            ->assertJsonPath('role', 'rule_admin')
            ->assertJsonFragment(['permissions' => ['rules.view', 'rules.propose', 'rules.decide', 'rules.backtest']]);
    }

    public function test_deactivated_users_lose_access_immediately(): void
    {
        $user = $this->user();
        $this->actingAs($user)->getJson('/api/me', $this->frontend())->assertOk();

        $user->update(['active' => false]);

        $user->active = false;
        $user->save();

        $this->actingAs($user->fresh())->getJson('/api/me', $this->frontend())->assertStatus(401);
        $this->assertFalse(Gate::forUser($user->fresh())->allows('alerts.view'));
    }

    public function test_logout_ends_the_session_and_is_audited(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/logout', [], $this->frontend())->assertNoContent();

        $this->assertGuest('web');
        $this->assertSame(1, DB::table('auth_events')->where('event', 'logout')->where('username', 'maya.a')->count());
    }

    public function test_permissions_keep_duties_separate(): void
    {
        $analyst = $this->user(['username' => 'ana.l', 'role' => 'analyst']);
        $supervisor = $this->user(['username' => 'sue.p', 'role' => 'supervisor']);
        $admin = $this->user(['username' => 'ruth.a', 'role' => 'rule_admin']);
        $gate = fn (User $u, string $p) => Gate::forUser($u)->allows($p);

        $this->assertTrue($gate($analyst, 'alerts.work'));
        $this->assertFalse($gate($analyst, 'alerts.reassign'));
        $this->assertFalse($gate($analyst, 'rules.decide'));

        $this->assertTrue($gate($supervisor, 'alerts.reassign'));
        $this->assertTrue($gate($supervisor, 'cases.manage'));
        $this->assertFalse($gate($supervisor, 'rules.decide'));

        $this->assertTrue($gate($admin, 'rules.decide'));
        $this->assertFalse($gate($admin, 'alerts.work'));   // whoever changes the rules does not work the alerts
        $this->assertFalse($gate($admin, 'cases.manage'));
    }

    public function test_the_database_rejects_an_unknown_role(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_role_chk');
        $this->user(['role' => 'wizard']);
    }

    public function test_the_database_rejects_a_badly_formed_username(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('users_username_chk');
        $this->user(['username' => 'Maya A']);
    }

    public function test_users_cannot_be_deleted(): void
    {
        $user = $this->user();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('deactivate');
        DB::table('users')->where('id', $user->id)->delete();
    }

    public function test_a_username_cannot_change(): void
    {
        $user = $this->user();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cannot change');
        DB::table('users')->where('id', $user->id)->update(['username' => 'someone.else']);
    }

    public function test_the_auth_audit_trail_is_append_only(): void
    {
        $this->user();
        $this->login('maya.a', 'wrong password');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('auth_events')->update(['username' => 'edited']);
    }

    public function test_create_user_command_creates_a_user_and_refuses_bad_input(): void
    {
        $this->artisan('access:create-user', ['username' => 'Maya.A', 'role' => 'analyst'])
            ->expectsQuestion('Password (at least 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertExitCode(0);

        $user = User::where('username', 'maya.a')->first();
        $this->assertSame('analyst', $user->role);
        $this->assertSame('Maya', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        $this->artisan('access:create-user', ['username' => 'x.y', 'role' => 'wizard'])->assertExitCode(1);
        $this->artisan('access:create-user', ['username' => 'maya.a', 'role' => 'analyst'])->assertExitCode(1); // already exists
    }
}