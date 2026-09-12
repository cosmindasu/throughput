# Reguli de proiect — hartă

Regulile de mai jos sunt **decizii deja luate** și **capcane măsurate**, nu preferințe de stil.
Fiecare a costat deja timp în acest proiect. Citește fișierul al cărui glob acoperă calea la
care lucrezi, **înainte** de a scrie cod.

| Glob | Fișier de reguli | Ce acoperă |
|---|---|---|
| `**/*` | [`project.md`](project.md) | Constrângeri care se aplică oriunde: versiunea de PHP a runtime-ului, bugetul de memorie, unde stă sursa de adevăr |
| `app/**`, `database/**`, `routes/**`, `tests/**` | [`tenancy.md`](tenancy.md) | Contextul de tenant, RLS, cozi — stratul de care depinde tot restul |
| `resources/js/**`, `resources/css/**` | [`frontend.md`](frontend.md) | Tokens de culoare, cele două teme, cifre tabulare, props Inertia |

**Sursa de adevăr pentru cerințe nu e în repo-ul de cod**: e în `specs_si_design/specs.md`
(funcțional) și `specs_si_design/plan-implementare.md` (tehnic), un nivel mai sus. ADR-urile,
în schimb, **sunt** aici, în `docs/adr/` — sunt sursă unică, nu o copie.
