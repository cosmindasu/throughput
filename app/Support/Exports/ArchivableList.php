<?php

namespace App\Support\Exports;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract implementat de o `ResourceList` ale cărei rânduri au FIECARE un fișier deja
 * generat pe disc, care poate fi împachetat într-o arhivă (FR-BILL-03 — „export facturi în
 * masă: PDF zip sau CSV sumar", §13.5 rândul „Facturi").
 *
 * Separat de `ExportableList` din exact același motiv pentru care acela e separat de
 * `ResourceList`: „am o reprezentare tabulară" și „am câte un fișier per rând" sunt două
 * capacități diferite. Facturile le au pe amândouă (CSV sumar ȘI zip de PDF-uri), comenzile
 * doar pe prima — `OrderList` NU implementează interfața asta, deci `?format=zip` pe comenzi
 * e refuzat explicit de `ListExport`, nu produce tăcut o arhivă goală.
 *
 * ATENȚIE la ce NU face: `ZipExporter` nu GENEREAZĂ fișiere, doar le adună. PDF-ul unei
 * facturi se randează în `App\Jobs\Invoices\GenerateInvoicePdfJob`, în coadă, la crearea
 * facturii (ADR-013). O arhivă cerută înainte ca un PDF să fie gata nu-l așteaptă și nu-l
 * cere — raportează rândul ca lipsă, cu motivul lui.
 */
interface ArchivableList
{
    /**
     * Numele fișierului ÎN arhivă (ex: `INV-000123.pdf`). Unicitatea e asigurată de
     * `ZipExporter`, care adaugă un sufix la coliziune — nu de implementare.
     */
    public function archiveEntryName(Model $row): string;

    /**
     * Calea pe discul `local` a fișierului deja generat, sau `null` dacă rândul n-are
     * (încă) unul.
     */
    public function archiveEntryPath(Model $row): ?string;

    /**
     * Ce se scrie în `contents.txt` pentru un rând fără fișier. Text pentru un om care se
     * uită la arhivă peste o săptămână — nu un cod de eroare.
     */
    public function archiveEntryMissingReason(Model $row): string;
}
