<?php

namespace App\Support\Imports;

use App\Support\LocalePreference;

/**
 * Un câmp țintă declarat de o resursă importabilă (§14.2, US-IMP-02): nume, CHEIE de
 * traducere pentru eticheta umană, obligatoriu/opțional, reguli Laravel de validare, și
 * aliasurile de antet folosite de `ColumnMappingSuggester` la auto-mapare. O clasă simplă,
 * imutabilă — sursă unică pentru mapare, validare ȘI template-ul CSV descărcabil
 * (`ImportTemplateBuilder`).
 *
 * `labelKey`, nu `label` (FR-I18N-04, ADR-022): proprietatea veche ținea literalul englez
 * direct — redenumirea e deliberată, ca orice citire rămasă pe fostul `->label` să devină
 * eroare PHP (proprietate inexistentă), nu o valoare tăcută netradusă. Eticheta efectivă se
 * obține prin `label()`/`labelIn()`/`allLabels()` de mai jos, niciodată direct pe proprietate.
 */
final readonly class ImportField
{
    /**
     * @param  list<string>  $rules
     * @param  string  $labelKey  Cheie în `lang/{locale}/imports.php` (`imports.fields.<resursă>.<câmp>`).
     * @param  list<string>  $aliases  Variante de antet care se potrivesc pe acest câmp,
     *                                 NORMALIZATE de apelant (`ColumnMappingSuggester::normalize()`
     *                                 le normalizează din nou, deci pot fi scrise natural aici).
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public bool $required,
        public array $rules,
        public array $aliases = [],
    ) {}

    /** Eticheta în locale-ul CURENT al cererii — folosită de UI și de template-ul CSV descărcabil. */
    public function label(): string
    {
        return __($this->labelKey);
    }

    /** Eticheta într-un locale ANUME, indiferent de cel curent — vezi `allLabels()`. */
    public function labelIn(string $locale): string
    {
        return __($this->labelKey, [], $locale);
    }

    /**
     * Eticheta în TOATE locale-urile suportate (`LocalePreference::CHOICES`) — candidați de
     * potrivire pentru `ColumnMappingSuggester::bestMatch()`. BR-I18N-01 cere explicit ca un
     * fișier exportat dintr-un mediu francez să se remapeze identic sub o interfață engleză
     * (și invers): `allLabels()`, nu `label()`, ca potrivirea să nu depindă de limba celui
     * care importă, azi.
     *
     * @return list<string>
     */
    public function allLabels(): array
    {
        return array_map(fn (string $locale) => $this->labelIn($locale), LocalePreference::CHOICES);
    }
}
