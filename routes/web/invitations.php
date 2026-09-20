<?php

// Acceptarea unei invitații de membru (§6.4, US-TEN-01) — PUBLIC, în afara grupului cu
// `{workspace}`.
//
// De ce nu în `routes/web/settings.php`, alături de restul ecranului Members: acela e
// inclus din grupul `auth` + `session.context` + `workspace` (routes/web.php), adică cere
// exact ce invitatul încă NU are — un membership ACTIV în tenantul respectiv
// (`ResolveWorkspace` → `Membership::forCurrentUserAcrossTenants()` filtrează pe
// `status = active`). Un invitat cu membership `pending` ar fi primit 404 pe propriul lui
// link de acceptare.
//
// Și fără `guest`, deliberat: invitatul poate fi deja autentificat (membru în altă
// organizație, sau chiar vizitatorul demo-ului logat ca Owner care tocmai și-a trimis
// invitația). Cu `guest`, ar fi fost redirecționat spre dashboard fără nicio explicație.
//
// Slug-ul din cale NU e o autorizare — vezi `App\Actions\Members\PendingInvitation` pentru
// de ce e acolo (politica RLS a lui `memberships` are nevoie de un tenant ca să întoarcă
// vreun rând, iar alternativa ar fi fost o a treia politică scrisă de mână, adică un ADR).

use App\Http\Controllers\Web\Invitations\AcceptInvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/invitations/{workspace}/{token}', [AcceptInvitationController::class, 'show'])
    ->name('invitations.show');

Route::post('/invitations/{workspace}/{token}', [AcceptInvitationController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('invitations.accept');
