<?php

namespace App\Support;

use App\Models\User;

/**
 * Catalogul de permisiuni și maparea rol → permisiuni, transcrisă din matricea §7.4 din
 * specs.md. Sursă unică: seeder-ul, testele de RBAC și propul `can` din Inertia citesc de
 * aici, nu fiecare din memorie.
 *
 * Catalogul e COMPLET din Faza 1, inclusiv pentru module care încă n-au interfață
 * (`invoices.create`, `imports.create`) — plan §7.3. Datele există dinainte; clasele
 * `Policy` care le verifică se scriu progresiv, per modul, în fazele 2-5.
 *
 * Steluța din matrice („doar înregistrări proprii/asignate", pentru Agent) NU e o
 * permisiune separată: permisiunea dă dreptul, Policy-ul îl îngustează la înregistrările
 * proprii (§7.5). Altfel catalogul s-ar dubla, iar regula de ownership ar ajunge în două
 * locuri care se pot contrazice.
 */
final class Permissions
{
    public const OWNER = 'Owner';

    public const MANAGER = 'Manager';

    public const AGENT = 'Agent';

    public const VIEWER = 'Viewer';

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::OWNER, self::MANAGER, self::AGENT, self::VIEWER];
    }

    /**
     * Steluța din matricea §7.4: „doar înregistrări proprii/asignate".
     *
     * Nu e o permisiune (vezi docblock-ul clasei), ci îngustarea aplicată de Policies peste
     * permisiunea care dă dreptul. Un singur loc știe că e vorba de Agent, ca Policies-urile
     * și listele să nu întrebe fiecare de rol pe cont propriu.
     */
    public static function restrictedToOwnRecords(User $user): bool
    {
        return $user->hasRole(self::AGENT);
    }

    /**
     * §7.4, rândul „Produse & variante": `variants.cost` (marja) e ascunsă pentru Agent și
     * Viewer, la nivel de `VariantResource`, nu doar în UI. Verificată direct pe rol —
     * catalogul nu are o permisiune separată `products.view_cost`, marja fiind o
     * proprietate a ROLULUI, nu a resursei (spre deosebire de ownership, care e per rând).
     */
    public static function canViewCost(User $user): bool
    {
        return $user->hasAnyRole([self::OWNER, self::MANAGER]);
    }

    /**
     * Toate permisiunile din aplicație, grupate pe resursa din matricea §7.4.
     *
     * @return array<string, list<string>>
     */
    public static function catalog(): array
    {
        return [
            'settings' => ['settings.view', 'settings.update'],
            'members' => ['members.view', 'members.invite', 'members.update_role', 'members.deactivate'],
            'data_exports' => ['data_exports.view', 'data_exports.create'],
            'billing' => ['billing.view', 'billing.manage'],
            'api_tokens' => ['api_tokens.view', 'api_tokens.create', 'api_tokens.revoke'],
            'carrier_settings' => ['carrier_settings.view', 'carrier_settings.manage'],
            'accounts' => ['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete'],
            'contacts' => ['contacts.view', 'contacts.create', 'contacts.edit', 'contacts.delete'],
            'pipelines' => ['pipelines.view', 'pipelines.manage'],
            'deals' => ['deals.view', 'deals.create', 'deals.edit', 'deals.delete', 'deals.move_stage', 'deals.change_owner'],
            'products' => ['products.view', 'products.create', 'products.edit', 'products.delete'],
            'locations' => ['locations.view', 'locations.manage'],
            'stock' => ['stock.view', 'stock.adjust'],
            'orders' => ['orders.view', 'orders.create', 'orders.edit', 'orders.delete', 'orders.cancel'],
            'shipments' => ['shipments.view', 'shipments.create', 'shipments.edit'],
            'invoices' => ['invoices.view', 'invoices.create', 'invoices.edit', 'invoices.void'],
            'payments' => ['payments.view', 'payments.create'],
            'bulk' => ['bulk.write', 'bulk.export'],
            'imports' => ['imports.view', 'imports.create'],
            'saved_views' => ['saved_views.manage_own', 'saved_views.view_team', 'saved_views.manage_team'],
            'reports' => ['reports.view', 'reports.manage'],
            'activity_log' => ['activity_log.view', 'activity_log.view_own'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_merge(...array_values(self::catalog())));
    }

    /**
     * Rol → permisiuni. Citește-o alături de matricea §7.4: fiecare linie de aici e un
     * rând din tabel.
     *
     * @return array<string, list<string>>
     */
    public static function forRoles(): array
    {
        return [
            // Acces complet, inclusiv billing și gestiunea membrilor.
            self::OWNER => self::all(),

            // Operațional complet, FĂRĂ billing deloc, și fără setări de curierat
            // (CRUD doar pentru Owner — matricea §7.4).
            //
            // `billing.view` e exclus deliberat, nu din neatenție. Matricea §7.4 acorda
            // Managerului `R` pe „Abonament & billing", dar §7.1 îl definește ca „acces
            // operațional complet, FĂRĂ billing", iar criteriul de acceptanță din §7.3 cere
            // explicit ca Managerul să NU vadă opțiunea „Billing & Subscription" în Settings.
            // Două secțiuni contra una: rândul din matrice era eroarea, corectat în specs.md
            // v1.13. Un meniu ascuns peste o pagină lizibilă ar fi fost oricum incoerent.
            self::MANAGER => array_values(array_diff(self::all(), [
                'billing.view',
                'billing.manage',
                'carrier_settings.manage',
                'carrier_settings.view',
                // Manager poate invita și schimba roluri Agent/Viewer, dar nu poate
                // promova la Owner și nu poate elimina un Owner — BR-TEN-02, aplicat în
                // MembershipPolicy: permisiunea rămâne, îngustarea e ABAC.
                'data_exports.create',
            ])),

            // Operațional restrâns la „propriile" înregistrări (îngustare în Policies)
            // + citire pe restul tenantului.
            self::AGENT => [
                'settings.view',
                'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete',
                'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.delete',
                // Fără `pipelines.view`: matricea §7.4 dă Agentului „—" pe configurarea de
                // pipeline/etape (și „R" Viewer-ului — o asimetrie ciudată a specificației,
                // semnalată, nu corectată în tăcere aici). Etapele de care are nevoie
                // kanban-ul vin cu `deals.view`, nu cu ecranul de configurare.
                'deals.view', 'deals.create', 'deals.edit', 'deals.delete', 'deals.move_stage',
                'products.view',
                'locations.view',
                'stock.view',
                'orders.view', 'orders.create', 'orders.edit', 'orders.delete', 'orders.cancel',
                'shipments.view', 'shipments.create', 'shipments.edit',
                'invoices.view',
                'bulk.write', 'bulk.export',
                'saved_views.manage_own', 'saved_views.view_team',
                'reports.view',
                'activity_log.view_own',
            ],

            // Doar citire, plus export: exportul E o citire a rândurilor deja vizibile pe
            // ecran, livrată ca fișier (BR-BULK-03, nota ³ de la §7.4). Un refuz n-ar
            // proteja nimic și ar contrazice US-CRM-03 — persona „contabil extern".
            self::VIEWER => [
                'settings.view',
                'accounts.view',
                'contacts.view',
                'pipelines.view',
                'deals.view',
                'products.view',
                'locations.view',
                'stock.view',
                'orders.view',
                'shipments.view',
                'invoices.view',
                'payments.view',
                'bulk.export',
                'saved_views.manage_own', 'saved_views.view_team',
                // Fără `billing.*`, fără `reports.*`, fără `activity_log.*`: matricea §7.4
                // dă Viewer-ului „—" pe toate trei.
            ],
        ];
    }
}
