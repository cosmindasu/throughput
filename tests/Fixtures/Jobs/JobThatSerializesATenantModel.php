<?php

namespace Tests\Fixtures\Jobs;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Jobul GREȘIT, păstrat intenționat ca fixture: primește un model Eloquent tenant-scoped
 * în constructor, adică exact ce respinge code review-ul (§6.3, ADR-003 addendum pct. 2).
 *
 * Există ca să demonstreze CU O EXECUȚIE, nu cu un comentariu, de ce e regula: la
 * deserializare, `SerializesModels` re-aduce modelul din bază ÎNAINTE ca middleware-ul de
 * context să apuce să ruleze — deci fetch-ul rulează fără tenant.
 */
class JobThatSerializesATenantModel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Account $account) {}

    public function handle(): void
    {
        // Nu contează: jobul nu ajunge până aici.
    }
}
