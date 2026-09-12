<?php

use App\Models\User;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * FR-PUB-05, BR-PUB-02, specs §4.5 — recuperare parolă.
 *
 * Fără `RefreshDatabase` (vezi raportul agentului): fiecare test folosește un email
 * aleator (unic per rulare), ca reluarea suitei să nu lovească `UNIQUE(users.email)`.
 */
it('returns the identical generic message for an email that does not exist', function () {
    $ip = '198.51.100.'.random_int(1, 254);
    RateLimiter::clear('forgot-password:'.$ip);

    $response = $this->post('/forgot-password', [
        'email' => 'nobody-'.Str::random(12).'@example.test',
    ], ['REMOTE_ADDR' => $ip]);

    $response->assertSessionHas('status', "If an account exists for this email, we've sent a reset link.");
});

it('returns the identical generic message for an email that does exist', function () {
    $ip = '198.51.100.'.random_int(1, 254);
    RateLimiter::clear('forgot-password:'.$ip);

    $user = User::factory()->create(['email' => 'exists-'.Str::random(12).'@example.test']);

    $response = $this->post('/forgot-password', [
        'email' => $user->email,
    ], ['REMOTE_ADDR' => $ip]);

    $response->assertSessionHas('status', "If an account exists for this email, we've sent a reset link.");
});

it('rate limits forgot-password requests per IP after 5 attempts within an hour', function () {
    $ip = '203.0.113.'.random_int(1, 254);
    $throttleKey = 'forgot-password:'.$ip;
    RateLimiter::clear($throttleKey);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/forgot-password', [
            'email' => 'someone-'.Str::random(8).'@example.test',
        ], ['REMOTE_ADDR' => $ip])->assertSessionHas('status');
    }

    // A 6-a cerere de pe aceeași adresă IP, în decurs de o oră — răspuns distinct de
    // mesajul generic de mai sus (criteriul de acceptanță din specs §4.5).
    $this->post('/forgot-password', [
        'email' => 'someone-'.Str::random(8).'@example.test',
    ], ['REMOTE_ADDR' => $ip])->assertStatus(429);

    RateLimiter::clear($throttleKey);
});

it('invalidates other devices after a successful password reset', function () {
    Event::fake([OtherDeviceLogout::class]);

    $user = User::factory()->create([
        'email' => 'reset-'.Str::random(12).'@example.test',
        'password' => Hash::make('OldPassword123!'),
    ]);

    $token = Password::broker()->createToken($user);

    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertRedirect(route('login'));

    // `Auth::logoutOtherDevices()` e apelat NECONDIȚIONAT (specs §4.5) — evenimentul
    // `OtherDeviceLogout` se declanșează doar pe calea de succes (rehash reușit),
    // deci prezența lui confirmă apelul, fără să depindă de middleware-ul
    // `AuthenticateSession` (activ abia după schimbarea din bootstrap/app.php — vezi
    // raportul agentului).
    Event::assertDispatched(OtherDeviceLogout::class);

    expect(Hash::check('NewPassword123!', $user->fresh()->password))->toBeTrue();
});

it('rejects an invalid or expired reset token', function () {
    $user = User::factory()->create(['email' => 'badtoken-'.Str::random(12).'@example.test']);

    $response = $this->post('/reset-password', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertSessionHasErrors('email');
});
