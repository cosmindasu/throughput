# ADR-010: Doi furnizori de curierat, selectabili per tenant, plus un furnizor de demonstrație

- **Status**: Accepted — **supapa de scop a fost trasă la 2026-09-12** (vezi nota de mai jos)
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Related**: [[ADR-001]] (componente proprii, nu configurare)
- **Tags**: curierat, integrari, multi-tenancy, adaptoare, sprint-5

> **Supapa trasă — EasyPost iese, 2026-09-12.** Acest ADR prevedea explicit că adaptorul EasyPost se poate amâna „fără a atinge arhitectura". Declanșatorul nu a fost lipsa de timp, ci o constatare la înscriere: **EasyPost condiționează accesul la cheile de API — inclusiv cele de test — de un abonament lunar.** Un furnizor cu plată recurentă pentru al treilea tenant al unui demo de portofoliu nu se justifică.
>
> **Ce rămâne:** interfața `ShippingCarrier`, suita de teste de contract rulată identic pe fiecare implementare, ecranul de setări per tenant cu credențiale criptate, și **două** implementări — `demo` și `shippo`. Enumul `provider` are acum doar valorile implementate (`shippo`, `demo`): o valoare fără adaptor ar fi cod mort pe care un reviewer o vede imediat.
>
> **Ce se pierde, explicit:** demonstrația „două integrări externe diferite, aceeași interfață". Ce rămâne demonstrat e „furnizorul se configurează **per tenant**, cu credențiale proprii" — Cascade și Northgate pornesc amândouă pe `shippo`, cu rânduri separate în `tenant_carrier_settings`. Argumentul central al deciziei (configurabilitate per tenant peste o interfață stabilă) nu depinde de numărul de furnizori; costul real al unui al treilea e acum documentat ca **o valoare în enum + un adaptor + aceeași suită de contract**, ceea ce e chiar dovada că abstracția ține.
>
> **Câștig secundar, nu neglijabil:** Faza 5 era marcată supraîncărcată de audit (P2-002). Pierde ~o zi de muncă, exact pe faza cea mai strânsă. Supapa a funcționat cum a fost gândită.

## Context și problema

Specificația (§11.5) definea o interfață `ShippingCarrier` (`createLabel`, `void`, `trackingUrl`) și lăsa deschisă alegerea furnizorului: EasyPost **sau** Shippo. Recomandarea inițială era Shippo, pe motiv practic — există deja o integrare Shippo funcțională în proiectul mai vechi `demo.dbg.ro`, deci ar fi fost mai rapid.

Proprietarul a respins alegerea unuia singur, cu un argument mai bun: **e un demo, iar clienții potențiali vin cu conturi existente.** Unii au Shippo, alții EasyPost. A arăta ambele integrări e o afirmație comercială mai puternică decât a arăta una.

## Drivers de decizie

- **Argument comercial** — „pot lucra cu contul de curierat pe care îl ai deja" e o propoziție care închide obiecții. „Am integrat Shippo" nu e.
- **Argument de inginerie** — o interfață cu o singură implementare e o presupunere, nu o abstracție. Nu știi dacă `ShippingCarrier` e bine proiectată până nu o implementezi de două ori. A doua implementare **validează** designul primei.
- **Demonstrarea configurării per tenant** — dacă fiecare workspace își alege furnizorul și își pune propriile credențiale, demo-ul arată o capabilitate SaaS în plus, gratis: configurare per organizație cu secrete stocate criptat.

## Decizia luată

**Ambele implementări, selectabile per tenant, plus o a treia de demonstrație.**

### Configurare per tenant

Tabel `tenant_carrier_settings`: `tenant_id`, `provider` (enum: `shippo` | `easypost` | `demo`), credențiale **criptate la repaus** cu encrypterul Laravel, `is_active`. Ecran de setări vizibil rolului Owner.

Stocarea criptată a credențialelor nu e un detaliu birocratic — e încă un lucru pe care un recenzent tehnic îl caută și rar îl găsește într-un demo.

### A treia implementare: `DemoShippingCarrier`

**Adăugare față de cererea inițială, propusă pentru rezistență.** Returnează o etichetă PDF plauzibilă și un număr de urmărire fals, fără apel extern.

Motivul: cu doi furnizori reali, demo-ul public depinde de două medii sandbox externe. Dacă unul pică într-o sâmbătă, un cumpărător care apasă „Ship" vede o eroare — exact momentul în care nu-ți permiți una. Furnizorul de demonstrație e implicit pentru tenanții publici; cei doi reali rămân selectabili și configurați pe sandbox.

### Configurarea celor trei tenanți semănați

Fiecare tenant din seed pornește cu alt furnizor, astfel încât un vizitator vede toate cele trei stări **fără să configureze nimic**:

| Tenant | Furnizor | Ce demonstrează |
|---|---|---|
| 1 (implicit la intrare) | `demo` | Fluxul complet, fără dependență externă |
| 2 | `shippo` (sandbox) | Integrare reală |
| 3 | `shippo` (sandbox, credențiale proprii) | Configurare **per tenant**: același furnizor, rând separat, credențiale separate |

*(Rândul 3 cerea `easypost` până la 2026-09-12 — vezi nota de supapă din capul documentului. „Toate cele trei stări" devine „ambele stări": fără dependență externă și cu integrare reală.)*

## Consecințe

### Pozitive

- Interfața `ShippingCarrier` e validată de două implementări reale, nu presupusă.
- Demo-ul nu se poate strica din cauza unui sandbox extern căzut.
- Se demonstrează în plus: configurare per tenant și stocare criptată de credențiale.
- Argument de vânzare direct pentru clienții care au deja un cont de curierat.

### Negative / trade-offs

- **Aproximativ o zi în plus în Faza 5** — a doua implementare, ecranul de setări, criptarea credențialelor. Faza 5 e deja cea mai încărcată; dacă auditul o confirmă supraîncărcată, `easypost` se poate amâna fără a atinge arhitectura, pentru că interfața rămâne aceeași.
- Două conturi sandbox de întreținut, cu chei care pot expira.
- Trei căi de cod de testat în loc de una. Mitigat: testele de contract rulează aceeași suită împotriva tuturor celor trei implementări — ceea ce e, din nou, ceva ce merită arătat.
