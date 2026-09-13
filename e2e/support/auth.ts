import path from 'node:path';
import { REPO_ROOT } from './env';

/**
 * Cele 4 conturi demo (specs.md §4.2, FR-PUB-02) — sursă unică pentru rolurile
 * suitei E2E. Ordinea nu contează (fiecare rulează ca test independent).
 */
export const DEMO_ROLES = ['owner', 'manager', 'agent', 'viewer'] as const;

export type DemoRole = (typeof DEMO_ROLES)[number];

/**
 * Eticheta exact așa cum apare pe buton — `LoginController::DEMO_ACCOUNTS` —
 * folosită pentru `getByRole('button', { name: 'Log in as ' + label })` în
 * proiectul de setup. Text literal, nu derivat: dacă eticheta din backend se
 * schimbă, testul de setup trebuie să pice, nu să tacă.
 */
export const DEMO_ROLE_LABELS: Record<DemoRole, string> = {
    owner: 'Owner',
    manager: 'Manager',
    agent: 'Agent',
    viewer: 'Viewer',
};

/**
 * Fișierul de `storageState` per rol, scris o singură dată de proiectul `setup`
 * (`e2e/setup/auth.setup.ts`) și citit de fiecare test din `e2e/specs/` prin
 * `test.use({ storageState: authFile(role) })`. Directorul e ignorat de git
 * (`.gitignore`: `/e2e/.auth/`) — conține cookie-uri de sesiune reale, chiar
 * dacă sunt ale unui mediu demo.
 */
export function authFile(role: DemoRole): string {
    return path.join(REPO_ROOT, 'e2e', '.auth', `${role}.json`);
}
