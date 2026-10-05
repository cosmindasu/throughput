import { Link } from '@inertiajs/react';
import AnimatedNumber from '@/Components/AnimatedNumber';
import Icon, { type IconName } from '@/Components/Icon';
import Sparkline from '@/Components/Charts/Sparkline';
import { type BadgeTone } from '@/Components/StatusBadge';
import ToneIcon from '@/Components/ToneIcon';
import { TONE } from '@/lib/tone';

export interface KpiDelta {
    /** Variația relativă față de perioada anterioară: `0.12` = +12%. */
    ratio: number;
    /** Formatarea procentului, cu semn — vine din pagină (`Intl.NumberFormat`, `signDisplay`). */
    format: (ratio: number) => string;
    /**
     * ÎNCOTRO e bine. O creștere de comenzi e o veste bună, o creștere de facturi restante nu —
     * tenta chip-ului nu se poate deduce din semn, deci se declară.
     */
    goodWhen: 'up' | 'down';
    /** „față de luna trecută" — citit de cititorul de ecran, ca procentul singur să nu fie orfan. */
    versus: string;
}

interface KpiTileProps {
    label: string;
    /**
     * Cifra BRUTĂ, nu textul ei. Placa o numără de la zero la montare, deci are nevoie de
     * valorile intermediare; formatarea (monedă, separatori, limbă) rămâne a paginii, care
     * singură știe moneda workspace-ului și locala.
     */
    value: number;
    /** Memoizat în pagină (`useMemo`) — altfel numărătoarea repornește la fiecare randare. */
    format: (value: number) => string;
    hint?: string;
    icon: IconName;
    /**
     * Tenta chip-ului de icon. NU e decor: pe plăcile care raportează o abatere
     * (facturi restante, alerte de stoc) pagina o alege din VALOARE — `success` la zero,
     * `danger`/`warning` peste — deci culoarea spune aceeași stare ca cifra. O tentă de
     * alarmă fixă ar striga la fel și când nu e nimic de rezolvat, adică n-ar mai însemna
     * nimic.
     */
    tone: BadgeTone;
    /**
     * Unde duce placa. Un KPI spune CÂT; linkul e singura cale de la cifră la înregistrările
     * din spatele ei, și e motivul pentru care un dashboard e un punct de plecare, nu un afiș.
     */
    href?: string;
    /**
     * Seria care a dus la cifră. Se dă DOAR unde există una reală: trei din patru plăci
     * raportează un instantaneu (valoare de pipeline, facturi restante, stoc), nu o evoluție,
     * iar o linie inventată din altă serie ar fi minciună cu aparență de date.
     */
    trend?: { values: number[]; label: string };
    delta?: KpiDelta;
}

/**
 * O singură placă KPI a dashboard-ului (FR-DEMO-01, §21.3). Valoarea vine
 * deja formatată din pagină (monedă/număr) — componenta nu presupune nimic
 * despre formatul sursă.
 *
 * `min-h-10` pe eticheta (`text-sm`/`line-height: 20px`) rezervă spațiul a
 * DOUĂ rânduri (2 × 20px = 40px), indiferent dacă textul efectiv se rupe sau
 * nu — găsit la măsurătoarea bilingvă a Lotului I18N (Val 5): în franceză,
 * `kpis.openPipelineValue` („Valeur du pipeline ouvert", 25 caractere) trece
 * pe două rânduri la ~410-425px lățime de viewport (grid pe 2 coloane, ex.
 * Pixel 5/7 — 412px), în timp ce vecina ei de pe același rând
 * (`kpis.ordersThisMonth`, „Commandes ce mois-ci") rămâne pe un singur rând.
 * Fără rezervare, eticheta mai lungă își împinge valoarea cu un rând mai jos
 * DOAR pe placa ei — cele patru valori numerice, care ar trebui să stea
 * aliniate pe același rând vizual, ajung la `top` diferit. Etichetele NU se
 * trunchiază (decizie de produs, nu defect) — rezervarea de înălțime rezolvă
 * alinierea fără să ascundă text.
 *
 * **Costul, spus explicit:** rezervarea e NECONDIȚIONATĂ, deci și pe engleză,
 * unde toate cele patru etichete încap pe un rând, placa devine cu 20px mai
 * înaltă decât înainte. E singura schimbare vizuală pe care lotul I18N o aduce
 * unui utilizator care n-a atins niciodată comutatorul de limbă — acceptată
 * deliberat, fiindcă alternativele sunt mai rele: o rezervare condiționată de
 * limbă ar cupla layoutul de locale (se rupe la a treia limbă sau la o
 * retraducere), iar scurtarea etichetei franceze ar face alinierea să depindă
 * de lungimea unei traduceri — adică s-ar rupe tăcut la următoarea revizie de
 * text, exact clasa de defect pe care valul ăsta o închide în altă parte.
 *
 * **Numărătoarea de la zero** e singurul motiv pentru care placa primește cifra brută în loc
 * de textul gata formatat. E oprită de `prefers-reduced-motion` și nu e o animație „de
 * decor" care să ascundă informația: valoarea finală e în markup de la prima randare (vezi
 * `AnimatedNumber`), deci cititorul de ecran și testele care caută textul văd cifra reală,
 * nu un cadru intermediar.
 *
 * **Rădăcina rămâne un `div`, chiar când placa e clicabilă.** Linkul e „întins"
 * peste placă cu `after:absolute after:inset-0` din interiorul etichetei, nu prin
 * transformarea rădăcinii în `<a>`. Două motive, ambele reale: `i18n-layout.spec.ts`
 * numără plăcile cu `main .grid.grid-cols-2 > div`, și — mai important — o ancoră
 * care învelește toată placa ar da cititorului de ecran un singur nume compus din
 * etichetă + cifră + hint + procent, în loc de o etichetă scurtă și acționabilă.
 * Rândurile de sub valoare (procent, linie) rămân în afara zonei de text a linkului.
 */
export default function KpiTile({ label, value, format, hint, icon, tone, href, trend, delta }: KpiTileProps) {
    // Tonul și săgeata se iau din cifra AFIȘATĂ, nu din raportul brut: `-0,003` se
    // formatează „0%" (zero zecimale), dar `ratio < 0` ar fi dat o săgeată roșie în jos lângă
    // un „0%" — 997 față de 1000 ajunge. `Number()` scoate din nou un număr din textul deja
    // rotunjit, cu semn.
    const shown = delta ? Number.parseFloat(delta.format(delta.ratio).replace(/[^\d.,+-]/g, '').replace(',', '.')) : 0;
    const direction = delta ? (Number.isFinite(shown) && shown < 0 ? 'down' : 'up') : null;
    // Zero nu e nici bine, nici rău: o lună identică cu precedenta nu merită nici verde, nici roșu.
    const deltaTone: BadgeTone = !delta || !Number.isFinite(shown) || shown === 0 ? 'neutral' : direction === delta.goodWhen ? 'success' : 'danger';

    return (
        <div
            className={`relative flex flex-col rounded-lg border border-l-4 border-border bg-surface p-4 transition-[border-color,box-shadow] hover:border-control ${href ? 'hover:shadow-md' : ''} ${TONE[tone].edge}`}
        >
            {/*
                Iconul stă PE RÂNDUL etichetei, nu deasupra ei: chip-ul (32px) încape în cei
                40px deja rezervați de `min-h-10`, deci rândul etichetei nu crește cu niciun
                pixel. `items-start` îl ține lipit de sus când eticheta trece pe două rânduri
                în franceză.
            */}
            <div className="flex items-start justify-between gap-3">
                <p className="min-h-10 text-sm text-text-2">
                    {href ? (
                        <Link href={href} prefetch className="rounded-sm after:absolute after:inset-0 after:rounded-lg">
                            {label}
                        </Link>
                    ) : (
                        label
                    )}
                </p>
                <ToneIcon tone={tone} name={icon} size="md" shape="square" />
            </div>
            {/*
                Cifra poartă tonul, eticheta rămâne neutră. Măsurat pe `--surface` înainte de
                a fi ales, ambele teme: cel mai slab raport e 5,82:1 (`--success` pe deschis),
                deci trece AA pentru text NORMAL, nu doar pentru text mare — placa nu depinde
                de dimensiunea fontului ca să fie conformă.

                Tot din măsurătoare a rezultat ce NU se face: un fundal tintat pe toată placa
                ar fi coborât `--text-3` (hint-ul de dedesubt) la 4,34:1 pe tema închisă. Arăta
                bine și pica AA.
            */}
            <p className={`numeric mt-1 text-2xl font-semibold ${tone === 'neutral' ? 'text-text' : TONE[tone].text}`}>
                <AnimatedNumber value={value} format={format} />
            </p>
            {hint && <p className="numeric mt-1 text-xs text-text-3">{hint}</p>}

            {(delta || trend) && (
                // `mt-auto` împinge rândul la baza plăcii: plăcile unui rând de grid au aceeași
                // înălțime, deci procentul și linia stau aliniate între ele chiar dacă una din
                // etichete s-a rupt pe două rânduri.
                <div className="mt-auto flex items-end justify-between gap-3 pt-3">
                    {delta && direction && (
                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${TONE[deltaTone].chip}`}>
                            {/* Săgeata dublează semnul procentului — direcția nu depinde de culoare (SC 1.4.1). */}
                            <Icon name={direction === 'up' ? 'arrowUpRight' : 'arrowDownRight'} size={12} />
                            <span className="numeric">{delta.format(delta.ratio)}</span>
                            <span className="sr-only"> {delta.versus}</span>
                        </span>
                    )}
                    {/*
                        Sub 640px grila e pe DOUĂ coloane, deci o placă are ~184px: insigna de
                        variație plus 96px de linie nu încap, iar linia ieșea din card (măsurat
                        în franceză la 416px — 412px față de marginea de 400px a plăcii).
                        Procentul rămâne, fiindcă el e informația; linia doar o ilustrează.
                    */}
                    {/*
                        FĂRĂ `label`: `Sparkline` devine atunci decorativ (`aria-hidden`), cum
                        îi spune propriul docblock. Cu etichetă, cititorul de ecran primea
                        `img "Orders per month over the last 12 months"` și NICIO cifră —
                        seria de 12 luni nu apare în niciun tabel de pe pagină. Procentul de
                        lângă ea e informația; linia doar o ilustrează.
                    */}
                    {trend && (
                        <span className="hidden sm:block" title={trend.label}>
                            <Sparkline values={trend.values} className={TONE[tone].text} />
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}
