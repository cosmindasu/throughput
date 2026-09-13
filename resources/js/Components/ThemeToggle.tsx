import { router, usePage } from '@inertiajs/react';
import { useId } from 'react';
import { applyResolvedTheme, persistResolvedThemeCookie, resolveSystemTheme, useThemeSync } from '@/hooks/useThemeSync';

type ThemeChoice = 'system' | 'light' | 'dark';

const OPTIONS: Array<{ value: ThemeChoice; label: string }> = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
];

/**
 * FR-PREF-01…03, BR-PREF-01…03 — comutator de temă cu trei stări, montat o singură
 * dată și folosit din DOUĂ locuri: bara de sus (AppLayout.tsx) și Settings →
 * Preferences (Settings/Preferences.tsx). Disponibil tuturor rolurilor, inclusiv
 * Viewer, și neafectat de `DEMO_MODE` — nu verifică niciun `can`, comutarea temei nu e
 * o acțiune de scriere de business (BR-PREF-02).
 *
 * `<fieldset>` + `<input type="radio">` REALE, nu un `role="radiogroup"` simulat:
 * navigarea cu săgețile, Tab, Space și inelul de focus vin gratis de la browser —
 * exact „operabil integral de la tastatură" (plan §8), fără cod ARIA de mână care
 * poate rămâne desincronizat de comportamentul nativ.
 */
export default function ThemeToggle() {
    const { auth } = usePage().props;
    const groupName = useId();
    const choice: ThemeChoice = auth.user?.theme ?? 'system';

    // Cât timp alegerea e „System" și fila rămâne deschisă, corectează un prim-paint
    // greșit și urmărește schimbările reale ale sistemului de operare.
    useThemeSync(choice === 'system');

    const select = (next: ThemeChoice) => {
        if (next === choice) {
            return;
        }

        const resolved = next === 'system' ? resolveSystemTheme() : next;

        // Feedback instant, fără să aștepte răspunsul serverului — round-trip-ul de
        // mai jos persistă ALEGEREA pe `users.theme` (FR-PREF-02) și rescrie cookie-ul
        // server-side cu aceeași valoare REZOLVATĂ, ca următoarea randare completă să
        // nu licărească (FR-PREF-03).
        applyResolvedTheme(resolved);
        persistResolvedThemeCookie(resolved);

        router.patch(
            '/preferences/theme',
            { theme: next, resolvedTheme: next === 'system' ? resolved : undefined },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <fieldset className="flex items-center gap-0.5 rounded-md border border-control p-0.5">
            <legend className="sr-only">Theme</legend>
            {OPTIONS.map((option) => {
                const active = choice === option.value;

                return (
                    <label
                        key={option.value}
                        className={`cursor-pointer rounded px-2 py-1 text-xs font-medium transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-focus ${
                            active ? 'bg-accent-fill text-accent-on' : 'text-text-2 hover:text-text'
                        }`}
                    >
                        <input
                            type="radio"
                            name={`theme-${groupName}`}
                            value={option.value}
                            checked={active}
                            onChange={() => select(option.value)}
                            className="sr-only"
                        />
                        {option.label}
                    </label>
                );
            })}
        </fieldset>
    );
}
