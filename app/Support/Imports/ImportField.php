<?php

namespace App\Support\Imports;

/**
 * Un câmp țintă declarat de o resursă importabilă (§14.2, US-IMP-02): nume, etichetă umană,
 * obligatoriu/opțional, reguli Laravel de validare, și aliasurile de antet folosite de
 * `ColumnMappingSuggester` la auto-mapare. O clasă simplă, imutabilă — sursă unică pentru
 * mapare, validare ȘI template-ul CSV descărcabil (`ImportTemplateBuilder`).
 */
final readonly class ImportField
{
    /**
     * @param  list<string>  $rules
     * @param  list<string>  $aliases  Variante de antet care se potrivesc pe acest câmp,
     *                                 NORMALIZATE de apelant (`ColumnMappingSuggester::normalize()`
     *                                 le normalizează din nou, deci pot fi scrise natural aici).
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public array $rules,
        public array $aliases = [],
    ) {}
}
