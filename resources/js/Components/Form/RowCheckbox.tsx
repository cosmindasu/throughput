interface RowCheckboxProps {
    checked: boolean;
    onChange: () => void;
    /** Obligatoriu, nu opțional: un checkbox de rând fără text vizibil ARE NEVOIE de un
     * nume propriu ("Select Acme Corp"), nu doar de un nume generic repetat pe fiecare
     * rând (SC 2.4.4 / 4.1.2 — vezi `.ai/rules/frontend.md`). */
    'aria-label': string;
}

/**
 * Checkbox de selecție pe rând de tabel (bulk actions) — SC 2.5.8 Target Size
 * (Minimum), AA în WCAG 2.2. Rămâne vizual 16×16 (`size-4`), ca să nu strice
 * densitatea tabelelor (Accounts/Deals/Orders/Products), dar zona de ATINGERE reală
 * urcă la 24×24 printr-un `::before` absolut poziționat pe eticheta-înveliș
 * (`-inset-1` = 4px pe fiecare parte, 16+4+4=24). Absolut poziționat = scos din flux,
 * deci nu împinge nimic — se extinde DOAR în padding-ul deja existent al celulei
 * (minim 8px pe fiecare parte în toate tabelele curente), niciodată peste rândul
 * vecin sau coloana alăturată.
 *
 * `<label>` fără text vizibil: fiindcă `<input>` e copil direct, click oriunde pe
 * eticheta (inclusiv pe `::before`) comută nativ checkbox-ul. Numele accesibil tot
 * `aria-label`-ul explicit de pe `<input>` rămâne (are prioritate față de eticheta
 * goală în calculul numelui accesibil) — nicio dublare, nicio pierdere de nume.
 */
export default function RowCheckbox({ checked, onChange, 'aria-label': ariaLabel }: RowCheckboxProps) {
    return (
        <label className="relative inline-flex size-4 shrink-0 cursor-pointer items-center justify-center before:absolute before:-inset-1 before:content-['']">
            <input
                type="checkbox"
                checked={checked}
                onChange={onChange}
                aria-label={ariaLabel}
                className="size-4 rounded border-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            />
        </label>
    );
}
