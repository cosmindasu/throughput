import type { Page } from '@playwright/test';

/**
 * Citește props-urile Inertia ale unei pagini direct din payload-ul server-side, fără să
 * depindă de randarea DOM: `@inertia` (`vendor/inertiajs/inertia-laravel/src/Directive.php`)
 * scrie `<script data-page="app" type="application/json">{...}</script>` la FIECARE
 * încărcare completă — sursa de adevăr a lui `usePage().props`, disponibilă înainte ca
 * React să hidrateze.
 *
 * Folosit doar pentru date pe care pagina nu le arată direct în text (id-uri ULID, praguri
 * numerice din props necitite de nimic vizibil) — pentru orice se poate citi din ecran,
 * `getByRole`/`getByText` rămân prioritare (tiparul restului suitei).
 *
 * Notă: NU funcționează pentru props `Inertia::defer()` (`orders`, `total`, `draftTotal` pe
 * listele cu FR-PERF-01) — acelea sosesc printr-o cerere Inertia SEPARATĂ, după randarea
 * inițială, deci nu sunt încă în acest payload. Pentru ele, citește din DOM după ce
 * `Deferred`-ul s-a rezolvat (`expect(...).toHaveText(...)`, nu acest helper.
 */
export async function inertiaPageProps<T = Record<string, unknown>>(page: Page, url: string): Promise<T> {
    await page.goto(url);

    const raw = await page.locator('script[data-page="app"]').first().textContent();

    if (!raw) {
        throw new Error(`Nicio pagină Inertia (script[data-page="app"]) găsită la ${url}.`);
    }

    const parsed = JSON.parse(raw) as { props: T };

    return parsed.props;
}
