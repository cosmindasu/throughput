<?php

// Settings (shell + Preferences) — plan §7.4, FR-PREF-01. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Settings\CarrierSettingController;
use App\Http\Controllers\Web\Settings\InvitationController;
use App\Http\Controllers\Web\Settings\MembersController;
use App\Http\Controllers\Web\Settings\SentEmailController;
use App\Http\Controllers\Web\Settings\SettingsController;
use App\Support\Bulk\EnsureBulkConcurrencyLimit;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::get('/settings/preferences', [SettingsController::class, 'preferences'])->name('settings.preferences');

// FR-ORD-06, §7.4 — Owner-only (App\Policies\TenantCarrierSettingPolicy, carrier_settings.*
// absent din Permissions::forRoles() pentru Manager/Agent/Viewer).
Route::get('/settings/shipping', [CarrierSettingController::class, 'index'])->name('settings.shipping.index');
Route::post('/settings/shipping', [CarrierSettingController::class, 'update'])->name('settings.shipping.update');

// BR-DEMO-02, specs.md §22.3 — jurnalul „Sent Emails", populat de
// App\Mail\Transport\DemoInterceptingTransport (app/Providers/AppServiceProvider.php).
// Gardă pe `sent_emails.view` — vezi App\Policies\SentEmailPolicy pentru motivare.
Route::get('/settings/sent-emails', [SentEmailController::class, 'index'])->name('settings.sent-emails.index');

// §6.4/§6.4.1 — US-TEN-02/03. `EnsureDemoModeGuardrails` (global, `bootstrap/app.php`)
// oprește `settings.members.deactivate` cât timp DEMO_MODE=true — vezi `App\Support\DemoMode`.
Route::get('/settings/members', [MembersController::class, 'index'])->name('settings.members.index');
// §22.5 — „Reassign and deactivate" (BR-TEN-06) creează TREI rânduri `bulk_operations`
// dintr-un singur clic, legate prin `group_id`. Limita de 3 concurente per utilizator se
// verifică pe CERERE, nu pe rând (altfel al doilea și al treilea s-ar refuza singure), deci
// middleware-ul stă aici, ca pe `unassigned.reassign`.
Route::post('/settings/members/{membership}/deactivate', [MembersController::class, 'deactivate'])
    ->middleware(EnsureBulkConcurrencyLimit::class)
    ->name('settings.members.deactivate');

// US-TEN-02 — schimbarea rolului unui membru. BR-TEN-01 (ultimul Owner) și BR-TEN-02 (doar
// un Owner atinge un Owner) sunt în `App\Policies\MembershipPolicy::updateRole()`, rulate a
// doua oară SUB blocare în `App\Actions\Members\UpdateMemberRoleAction`.
Route::patch('/settings/members/{membership}/role', [MembersController::class, 'updateRole'])->name('settings.members.role.update');

// US-TEN-01 — invitații. ACESTA e fluxul care deschide vectorul din specs.md §22.3 (o
// adresă arbitrară, tastată de vizitator, devine destinatar de email): trece prin
// `App\Mail\Transport\DemoInterceptingTransport`, construit în Faza 4 exact pentru el.
// Acceptarea e o rută PUBLICĂ — `routes/web/invitations.php`, în afara acestui grup.
Route::post('/settings/members/invite', [InvitationController::class, 'store'])->name('settings.members.invite');
Route::post('/settings/members/{membership}/resend', [InvitationController::class, 'resend'])->name('settings.members.invitations.resend');
Route::delete('/settings/members/{membership}', [InvitationController::class, 'destroy'])->name('settings.members.invitations.destroy');
