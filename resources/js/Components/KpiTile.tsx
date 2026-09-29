import Icon, { type IconName } from '@/Components/Icon';
import { type BadgeTone, toneClasses } from '@/Components/StatusBadge';

/**
 * Bara de accent de pe muchia din stânga. Separată de `toneClasses` (care dă tenta de
 * FUNDAL a chip-ului) fiindcă aici culoarea se folosește la intensitate plină, pe o
 * suprafață de 4px — nu e text, deci nu intră în auditul de contrast, dar e singurul
 * element din placă vizibil de la distanța de la care citești un dashboard.
 */
const toneValue: Record<BadgeTone, string> = {
    neutral: 'text-text',
    // `--accent-text`, NU `--accent-fill`: accentul are două trepte, iar umplerea
    // (`#0e7c8c`) pică drept text pe fundal deschis — regula din `.ai/rules/frontend.md`.
    accent: 'text-accent-text',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
    info: 'text-info',
};

const toneEdge: Record<BadgeTone, string> = {
    neutral: 'border-l-control',
    accent: 'border-l-accent-fill',
    success: 'border-l-success',
    warning: 'border-l-warning',
    danger: 'border-l-danger',
    info: 'border-l-info',
};

interface KpiTileProps {
    label: string;
    value: string;
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
 */
export default function KpiTile({ label, value, hint, icon, tone }: KpiTileProps) {
    return (
        <div
            className={`rounded-lg border border-l-4 border-border bg-surface p-4 transition-colors hover:border-control ${toneEdge[tone]}`}
        >
            {/*
                Iconul stă PE RÂNDUL etichetei, nu deasupra ei: chip-ul (~30px) încape în cei
                40px deja rezervați de `min-h-10`, deci placa nu crește cu niciun pixel și
                dashboard-ul continuă să încapă fără scroll pe 1280×800 (cerința din
                docblock-ul paginii). `items-start` îl ține lipit de sus când eticheta trece
                pe două rânduri în franceză.
            */}
            <div className="flex items-start justify-between gap-3">
                <p className="min-h-10 text-sm text-text-2">{label}</p>
                <span className={`rounded-md p-1.5 ${toneClasses[tone]}`}>
                    <Icon name={icon} size={18} />
                </span>
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
            <p className={`numeric mt-1 text-2xl font-semibold ${toneValue[tone]}`}>{value}</p>
            {hint && <p className="numeric mt-1 text-xs text-text-3">{hint}</p>}
        </div>
    );
}
