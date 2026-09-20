/**
 * Declarație ambientală pentru bundle-ul ESM al Swagger UI, care e JavaScript pur, fără
 * tipuri proprii.
 *
 * Scrisă aici, nu instalată ca `@types/swagger-ui-dist`: pachetul acela descrie întreaga
 * suprafață a librăriei, din care `resources/js/swagger.ts` folosește exact o funcție și
 * patru opțiuni. Un pachet în plus, doar ca `tsc` să nu se plângă de un `any` pe care îl
 * putem descrie în opt rânduri, nu se justifică — mai ales pe un proiect unde regula e că
 * nicio dependență nouă nu intră fără motiv măsurat.
 *
 * Restrâns deliberat la ce folosim: dacă cineva are nevoie de mai mult din Swagger UI,
 * adaugă aici, vizibil, în loc să obțină tăcut `any`.
 */
declare module 'swagger-ui-dist/swagger-ui-es-bundle.js' {
    interface SwaggerUIOptions {
        url: string;
        domNode: HTMLElement;
        deepLinking?: boolean;
        tryItOutEnabled?: boolean;
    }

    export default function SwaggerUIBundle(options: SwaggerUIOptions): unknown;
}

declare module 'swagger-ui-dist/swagger-ui.css';
