<?php

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    // OPS-03 — `HorizonBasicAuth` ține accesul pe credențiale din env, nu pe un utilizator din
    // baza de date (motivul e în docblock-ul clasei). Rulează după `web` și înaintea gate-ului
    // pachetului, ca să poată trimite provocarea 401 pe care browserul o transformă în prompt.
    'middleware' => ['web', HorizonBasicAuth::class],

    'basic_auth' => [
        'user' => env('HORIZON_BASIC_AUTH_USER'),
        'password' => env('HORIZON_BASIC_AUTH_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
     * Un singur supervisor, un singur proces — §3, decizia 2, aplicată din Sprint 0.
     *
     * Proiectul intră pe un VPS partajat cu alte 11, care avea 11 ucideri OOM măsurate.
     * Bugetul lui e 250-400 MB la vârf, iar containerul `horizon` are plafon de 384 MB
     * (job-urile de PDF pot lansa un Chromium efemer de 150-250 MB). Un proces per coadă
     * ar depăși bugetul înainte de a exista primul ecran.
     *
     * Compensarea pentru concurența pierdută e ORDINEA cozilor de mai jos: `imports` și
     * `reports` stau înaintea lui `bulk`, astfel încât o operație în masă lungă
     * (procesată în chunk-uri de 500-1.000 rânduri, fiecare un job separat) nu blochează
     * un import mic mai mult decât durata unui singur chunk — Horizon reevaluează
     * prioritatea la fiecare job terminat, nu o singură dată la pornire.
     *
     * `balance => false`: echilibrarea are sens între mai multe procese. Cu unul singur,
     * `auto` ar doar muta acel proces între cozi, ignorând ordinea de prioritate.
     */
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['imports', 'reports', 'bulk', 'default'],
            'balance' => false,
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            /*
             * 256, nu 128 (implicitul Laravel), după măsurătorile Fazei 3: un export PDF la
             * plafonul lui (500 de rânduri, [[ADR-019]]) are un vârf de ~227 MB, iar un chunk
             * de operație în masă pe zeci de mii de rânduri se apropie și el. Horizon verifică
             * memoria ÎNTRE joburi, deci un job nu e ucis la mijloc — valoarea decide cât de
             * des se reciclează procesul. Cu 128, workerul reporni după fiecare export PDF:
             * nu se pierdea lucru (supervisorul îl readuce), dar plafonul nu spunea adevărul
             * despre ce rulează acolo.
             *
             * Rămâne SUB plafonul containerului (384 MB): 256 pentru worker + 64 pentru
             * supervisorul master (`memory_limit` mai sus) lasă ~64 MB de rezervă, ca
             * reciclarea să vină de la Horizon, nu de la OOM killer-ul cgroup-ului.
             *
             * Corecție, Faza 4: până acum valoarea asta nu putea intra în vigoare. Imaginea
             * lăsa `memory_limit` PHP la 128M și în containerul `horizon` (e aceeași imagine
             * ca pentru `app`, care are alt buget), deci PHP omora procesul cu mult înainte ca
             * Horizon să apuce să verifice — iar măsurătoarea de ~227 MB de mai sus fusese
             * făcută pe Mac, nu pe container. Plafonul PHP e acum 256M, activat prin
             * `PHP_INI_SCAN_DIR` doar pe acest serviciu (`docker/app/Dockerfile`). Cele două
             * cifre sunt deliberat egale: PHP oprește un job care ar depăși 256M, Horizon
             * reciclează procesul după un job care a ajuns acolo.
             */
            'memory' => 256,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    // Aceeași topologie în ambele medii, deliberat: un singur mediu real (producția =
    // demo public), fără staging permanent. Dacă local ar rula 3 procese, presiunea de
    // memorie s-ar descoperi abia în producție.
    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
