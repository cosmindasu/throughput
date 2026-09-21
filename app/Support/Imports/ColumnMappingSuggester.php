<?php

namespace App\Support\Imports;

/**
 * Auto-mapare euristică a antetelor CSV pe câmpurile țintă ale unei resurse (US-IMP-02).
 * Nu e ML — o potrivire EXACTĂ pe alias normalizat (încredere „high"), altfel o măsură de
 * similaritate (`similar_text()`) între antetul normalizat și fiecare alias/etichetă/cheie a
 * câmpurilor RĂMASE nefolosite, cu praguri fixe pentru „medium"/„low"/fără sugestie.
 *
 * Normalizare = lowercase + fără orice caracter în afara `a-z0-9` (spații, underscore,
 * punctuație dispar deopotrivă) — „Unit of Measure", "unit_of_measure" și "UNIT-OF-MEASURE"
 * ajung la același șir, `unitofmeasure`.
 *
 * Fiecare câmp se poate mapa cel mult O DATĂ (primul antet care-l revendică îl scoate din
 * cursă pentru antetele următoare) — altfel două coloane ambigue ("Phone", "Phone Number")
 * ar putea ateriza amândouă pe același câmp, iar remaparea manuală (cerută oricum de US-IMP-02)
 * ar trebui să repare o coliziune pe care euristica o putea evita.
 *
 * Coloana `error` e REZERVATĂ, exclusă explicit din potrivirea prin similaritate — nu doar
 * un prag numeric mai strict. E adăugată de PROPRIUL nostru raport reimportabil
 * (`ImportErrorReportBuilder`), deci apare mereu ca ultima coloană la un reimport al
 * fișierului corectat (US-IMP-01). Măsurat: „error" ajunge la 46% similaritate cu
 * „category" (litere comune întâmplătoare: „e", „r", „a", „o"), peste orice prag „low"
 * rezonabil — un antet de 5 litere are prea puține caractere ca un prag universal să
 * separe corect potrivirile întâmplătoare de cele reale. Cunoaștem EXACT ce înseamnă acest
 * antet (fiindcă noi l-am adăugat), deci excluderea explicită e mai sigură decât urcarea
 * pragului „low" pentru toate resursele.
 */
final class ColumnMappingSuggester
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_NONE = 'none';

    private const HIGH_THRESHOLD = 85.0;

    private const MEDIUM_THRESHOLD = 65.0;

    private const LOW_THRESHOLD = 40.0;

    private const RESERVED_ERROR_COLUMN = 'error';

    /**
     * @param  list<string>  $headers  Antetele CSV, ÎN ORDINEA din fișier.
     * @param  list<ImportField>  $fields
     * @return list<array{header: string, field: string|null, confidence: string, score: float}>
     */
    public static function suggest(array $headers, array $fields): array
    {
        $claimed = [];
        $suggestions = [];

        foreach ($headers as $header) {
            if (self::normalize($header) === self::RESERVED_ERROR_COLUMN) {
                $suggestions[] = ['header' => $header, 'field' => null, 'confidence' => self::CONFIDENCE_NONE, 'score' => 0.0];

                continue;
            }

            $suggestion = self::bestMatch($header, $fields, $claimed);

            if ($suggestion['field'] !== null) {
                $claimed[] = $suggestion['field'];
            }

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * @param  list<ImportField>  $fields
     * @param  list<string>  $claimed
     * @return array{header: string, field: string|null, confidence: string, score: float}
     */
    private static function bestMatch(string $header, array $fields, array $claimed): array
    {
        $normalizedHeader = self::normalize($header);
        $bestField = null;
        $bestScore = 0.0;

        foreach ($fields as $field) {
            if (in_array($field->key, $claimed, true) || $normalizedHeader === '') {
                continue;
            }

            // `allLabels()`, nu `label()` (BR-I18N-01): eticheta în AMBELE limbi intră în
            // candidați, indiferent de locale-ul celui care importă acum — altfel un fișier
            // exportat sub `fr` și reimportat sub `en` (sau invers) ar depinde de limba
            // curentă a interfeței, exact scenariul pe care regula îl interzice.
            foreach ([$field->key, ...$field->allLabels(), ...$field->aliases] as $candidate) {
                $normalizedCandidate = self::normalize($candidate);

                if ($normalizedCandidate === '') {
                    continue;
                }

                if ($normalizedCandidate === $normalizedHeader) {
                    return ['header' => $header, 'field' => $field->key, 'confidence' => self::CONFIDENCE_HIGH, 'score' => 100.0];
                }

                similar_text($normalizedHeader, $normalizedCandidate, $percent);

                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $bestField = $field;
                }
            }
        }

        if ($bestField === null || $bestScore < self::LOW_THRESHOLD) {
            return ['header' => $header, 'field' => null, 'confidence' => self::CONFIDENCE_NONE, 'score' => round($bestScore, 1)];
        }

        $confidence = match (true) {
            $bestScore >= self::HIGH_THRESHOLD => self::CONFIDENCE_HIGH,
            $bestScore >= self::MEDIUM_THRESHOLD => self::CONFIDENCE_MEDIUM,
            default => self::CONFIDENCE_LOW,
        };

        return ['header' => $header, 'field' => $bestField->key, 'confidence' => $confidence, 'score' => round($bestScore, 1)];
    }

    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value)));
    }
}
