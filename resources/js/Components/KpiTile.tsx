interface KpiTileProps {
    label: string;
    value: string;
    hint?: string;
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
export default function KpiTile({ label, value, hint }: KpiTileProps) {
    return (
        <div className="rounded-lg border border-border bg-surface p-4">
            <p className="min-h-10 text-sm text-text-2">{label}</p>
            <p className="numeric mt-1 text-2xl font-semibold text-text">{value}</p>
            {hint && <p className="numeric mt-1 text-xs text-text-3">{hint}</p>}
        </div>
    );
}
