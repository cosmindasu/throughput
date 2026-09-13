<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * `AuthorizesRequests` lipsea din scaffold-ul Laravel 13 (trait-ul nu mai vine implicit
 * de la `php artisan install`, spre deosebire de versiunile anterioare) — fără el,
 * `$this->authorize(...)` din orice controller aruncă „Call to undefined method",
 * indiferent de Policy. Adăugat aici, o singură dată, pentru toate pachetele Fazei 2 care
 * au nevoie de el (Accounts, la fel Contacts/Deals/Settings), nu repetat per controller.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
