<?php

/**
 * BR-PREF-03 (specs.md §15.6) — „umplerea butonului primar și eticheta lui sunt identice
 * în ambele teme — o proprietate a paletei alese, păstrată deliberat: acțiunea principală
 * arată la fel oriunde." Azi regula trăia DOAR ca un comentariu deasupra `variants.primary`
 * din `resources/js/Components/Button.tsx`; nimic n-o măsura.
 *
 * De ce Pest pe tokeni, NU Playwright: bugetul de minute Actions e comun cu alte 11
 * proiecte (`.ai/rules/project.md`, „Minutele de GitHub Actions sunt cotă comună"), E2E-ul
 * rulează deja o suită întreagă, iar un test pe tokeni prinde regresia la sursă (CSS), nu
 * la randare — nu depinde de build Vite, de un browser sau de markup-ul componentei.
 *
 * Sursa de adevăr sunt tokenii CSS din `resources/css/app.css`: `:root` (tema deschisă) și
 * `.dark` (tema închisă) DEFINESC integral fiecare temă — `@theme inline` doar îi EXPUNE ca
 * utilitare Tailwind (`bg-accent-fill`, `text-accent-on`, …), nu redefinește nimic (vezi
 * comentariul din `app.css` deasupra blocului `@theme inline`, și `.ai/rules/frontend.md`,
 * „Două teme, ambele definite complet"). `Button.tsx` construiește butonul primar cu
 * `bg-accent-fill text-accent-on hover:bg-accent-fill-hover` — exact tripleta verificată
 * mai jos, citită direct din CSS, nu presupusă.
 *
 * Pragul asertat (4.5:1) e AA pentru text normal — proiectul are cerință WCAG 2.2 AA
 * explicită. Formula de luminanță relativă e cea standard WCAG 2.x („Relative Luminance").
 */

/**
 * Extrage valoarea unei variabile CSS (`--nume: valoare;`) dintr-un bloc de selector
 * (`:root` sau `.dark`) din `resources/css/app.css`. Blocurile de tokeni nu au reguli
 * imbricate, deci prima `}` întâlnită după selector închide exact blocul lui.
 */
function brPref03ReadToken(string $selector, string $variable): string
{
    static $css = null;

    if ($css === null) {
        $css = file_get_contents(base_path('resources/css/app.css'));

        if ($css === false) {
            throw new RuntimeException('Nu pot citi resources/css/app.css.');
        }
    }

    if (! preg_match('/'.preg_quote($selector, '/').'\s*\{(.*?)\}/s', $css, $blockMatch)) {
        throw new RuntimeException("Blocul CSS [{$selector}] nu a fost găsit în app.css.");
    }

    if (! preg_match('/--'.preg_quote($variable, '/').'\s*:\s*([^;]+);/', $blockMatch[1], $varMatch)) {
        throw new RuntimeException("Variabila [--{$variable}] nu a fost găsită în blocul [{$selector}].");
    }

    return trim($varMatch[1]);
}

/**
 * @return array{light: string, dark: string}
 */
function brPref03TokenPerTheme(string $variable): array
{
    return [
        'light' => brPref03ReadToken(':root', $variable),
        'dark' => brPref03ReadToken('.dark', $variable),
    ];
}

/**
 * @return array{r: int, g: int, b: int}
 */
function brPref03HexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }

    return [
        'r' => hexdec(substr($hex, 0, 2)),
        'g' => hexdec(substr($hex, 2, 2)),
        'b' => hexdec(substr($hex, 4, 2)),
    ];
}

/** Luminanța relativă WCAG 2.x a unei culori hex (`#rrggbb` sau `#rgb`). */
function brPref03RelativeLuminance(string $hex): float
{
    $rgb = brPref03HexToRgb($hex);

    $channel = function (int $value): float {
        $srgb = $value / 255;

        return $srgb <= 0.03928 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel($rgb['r']) + 0.7152 * $channel($rgb['g']) + 0.0722 * $channel($rgb['b']);
}

/** Raportul de contrast WCAG 2.x dintre două culori hex, independent de ordine. */
function brPref03ContrastRatio(string $hexA, string $hexB): float
{
    $luminanceA = brPref03RelativeLuminance($hexA);
    $luminanceB = brPref03RelativeLuminance($hexB);

    $lighter = max($luminanceA, $luminanceB);
    $darker = min($luminanceA, $luminanceB);

    return ($lighter + 0.05) / ($darker + 0.05);
}

it('keeps the primary button fill identical between the light and dark theme', function () {
    $fill = brPref03TokenPerTheme('accent-fill');

    expect($fill['light'])->toBe($fill['dark']);
});

it('keeps the primary button label color identical between the light and dark theme', function () {
    $label = brPref03TokenPerTheme('accent-on');

    expect($label['light'])->toBe($label['dark']);
});

it('meets the WCAG AA contrast threshold (4.5:1) for the primary button fill/label pair in the light theme', function () {
    $fill = brPref03TokenPerTheme('accent-fill')['light'];
    $label = brPref03TokenPerTheme('accent-on')['light'];

    expect(brPref03ContrastRatio($fill, $label))->toBeGreaterThanOrEqual(4.5);
});

it('meets the WCAG AA contrast threshold (4.5:1) for the primary button fill/label pair in the dark theme', function () {
    $fill = brPref03TokenPerTheme('accent-fill')['dark'];
    $label = brPref03TokenPerTheme('accent-on')['dark'];

    expect(brPref03ContrastRatio($fill, $label))->toBeGreaterThanOrEqual(4.5);
});

it('makes the primary button hover fill darker than the resting fill, in both themes', function () {
    $fill = brPref03TokenPerTheme('accent-fill');
    $hover = brPref03TokenPerTheme('accent-fill-hover');

    expect(brPref03RelativeLuminance($hover['light']))->toBeLessThan(brPref03RelativeLuminance($fill['light']));
    expect(brPref03RelativeLuminance($hover['dark']))->toBeLessThan(brPref03RelativeLuminance($fill['dark']));
});

it('only ever increases contrast on hover relative to the resting state, in both themes', function () {
    $fill = brPref03TokenPerTheme('accent-fill');
    $hover = brPref03TokenPerTheme('accent-fill-hover');
    $label = brPref03TokenPerTheme('accent-on');

    $restingLight = brPref03ContrastRatio($fill['light'], $label['light']);
    $hoverLight = brPref03ContrastRatio($hover['light'], $label['light']);
    expect($hoverLight)->toBeGreaterThanOrEqual($restingLight);

    $restingDark = brPref03ContrastRatio($fill['dark'], $label['dark']);
    $hoverDark = brPref03ContrastRatio($hover['dark'], $label['dark']);
    expect($hoverDark)->toBeGreaterThanOrEqual($restingDark);
});
