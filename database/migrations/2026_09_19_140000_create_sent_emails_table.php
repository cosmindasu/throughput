<?php

use Database\Migrations\Concerns\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-DEMO-02, specs.md §22.3 — jurnalul „Sent Emails": un rând per încercare de trimitere
 * văzută de `App\Mail\Transport\DemoInterceptingTransport`, indiferent de rezultat
 * (livrată real, interceptată, sau parțial — un mesaj cu un destinatar permis și unul nu).
 *
 * ATENȚIE — a doua tabelă din tot proiectul care NU folosește politica uniformă
 * `$this->enableRls()` (prima e `memberships`, vezi migrația ei). Motivul e funcțional, nu
 * tehnic (la fel ca la `memberships`): `tenant_id` e NULLABIL, pentru că nu orice email
 * tranzacțional are un tenant la momentul trimiterii.
 *
 *   - Un raport programat (§16) sau o invitație de membru (§6.4, Faza 5) AU tenant: pornesc
 *     dintr-un job/cerere deja în contextul unui workspace.
 *   - Recuperarea parolei (FR-PUB-05) NU are: `PasswordResetLinkController` stă pe rute
 *     `guest`, în afara grupului `session.context`/`workspace` (routes/web.php) — nu există
 *     niciun tenant de atribuit la momentul trimiterii.
 *
 * Trei opțiuni cântărite pentru cazul fără tenant (raportul pachetului detaliază alegerea):
 *   1. `tenant_id` NOT NULL, fără jurnalizare pentru emailul fără tenant — RESPINSĂ: BR-DEMO-02
 *      cere explicit „orice email tranzacțional (…) resetare parolă" în jurnal.
 *   2. `tenant_id` nullabil, dar cu politica UNIFORMĂ (`$this->enableRls()`) — RESPINSĂ:
 *      politica uniformă e `tenant_id = current_setting(...)::bpchar`; o comparație cu NULL
 *      dă NULL, deci rândul ar rămâne invizibil la CITIRE (corect), dar RLS aplică ACEEAȘI
 *      expresie și la INSERT (`WITH CHECK` implicit — tenancy.md, „Politicile RLS se aplică
 *      și la INSERT"): un INSERT cu `tenant_id = NULL` ar da NULL la verificare → „new row
 *      violates row-level security policy" — exact INSERT-ul tăcut eșuat pe care nu trebuie
 *      să-l lăsăm să scape.
 *   3. (ALEASĂ) `tenant_id` nullabil, cu o a doua ramură EXPLICITĂ pentru „fără context
 *      deloc": vezi politica de mai jos.
 *
 * Politica: `(tenant_id = current_setting('app.tenant_id', true)::bpchar) OR (tenant_id IS
 * NULL AND coalesce(current_setting('app.tenant_id', true), '') = '')`.
 *
 *   - Cu un tenant în context (`app.tenant_id` = un ULID): prima ramură, cast pe setare nu
 *     pe coloană (ADR-016) — vede/scrie DOAR rândurile lui, niciodată rândurile fără tenant
 *     (a doua ramură cere explicit „gol", deci un tenant activ n-o satisface niciodată).
 *     ZERO scurgere cross-tenant — `SentEmailIsolationTest`.
 *   - FĂRĂ context (a doua ramură): vede/scrie DOAR rândurile fără tenant. „Fără context" e
 *     verificat cu `coalesce(…, '') = ''`, NU cu `IS NULL` simplu — `current_setting(...,
 *     true)` întoarce NULL doar pe o conexiune care n-a apelat NICIODATĂ `set_config` pentru
 *     `app.tenant_id`, dar '' după ce o tranzacție anterioară l-a golit (EnablesRowLevelSecurity,
 *     verificat ADR-016) — și un worker de coadă cu viață lungă (Horizon) reutilizează
 *     aceeași conexiune între joburi ale unor tenanți diferiți, deci ajunge la '', nu la
 *     starea virgină, după PRIMUL job de tenant. Cu un `IS NULL` strict, a doua ramură ar fi
 *     practic nefuncțională pe orice conexiune care a mai văzut vreodată un tenant — exact
 *     genul de bug tăcut pe care `.ai/rules/tenancy.md` îl documentează la „Memoizarea per
 *     cerere". `coalesce(…, '') = ''` tratează NULL și '' identic, ca peste tot în restul
 *     acestei tabele de politici (`matchesSetting`: „setare lipsă → NULL → zero rânduri;
 *     setare goală → nicio potrivire → zero rânduri. Cade tot închis.").
 *
 * Fără a doua ramură, rândurile fără tenant ar fi scrie-o-dată-citește-niciodată: INSERT-ul
 * ar trece (nicio ramură nu l-ar bloca dacă ar fi doar prima), dar NICIO interogare, din
 * NICIUN context, n-ar putea să le și citească/șteargă la retenție (`PruneSentEmailsJob`) —
 * ar rămâne definitiv în tabelă, cu conținut de email complet.
 *
 * A doua utilizare a `enableRlsWithPolicy()` din tot proiectul (prima: `memberships`) — al
 * doilea apelant, per docblock-ul trait-ului, „are nevoie de un ADR, nu de un commit":
 * ADR-020, care conține și verificarea cu două sesiuni `psql` a celor trei cazuri de mai sus.
 * Un AL TREILEA apelant are nevoie de un ADR nou, nu de o trimitere la ADR-020.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('sent_emails', function (Blueprint $table) {   // RLS custom — append-only
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('mailer', 50);
            // `failed` — eșec al transportului REAL (Resend jos, timeout), distinct de
            // `intercepted`/`partial` (decizii ale interceptării, nu eșecuri de livrare).
            // Adăugat în review (raportul pachetului): niciun rând nu se scrie „delivered"
            // pentru un email care n-a plecat cu adevărat.
            $table->enum('status', ['delivered', 'intercepted', 'partial', 'failed']);
            $table->string('subject', 500);
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->jsonb('recipients');
            $table->text('html_body')->nullable();
            $table->text('text_body')->nullable();
            $table->boolean('redacted')->default(false);
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'created_at']);
        });

        $this->enableRlsWithPolicy(
            'sent_emails',
            self::matchesSetting('tenant_id', 'app.tenant_id')
                ." OR (tenant_id IS NULL AND coalesce(current_setting('app.tenant_id', true), '') = '')",
            'sent_email_visibility'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};
