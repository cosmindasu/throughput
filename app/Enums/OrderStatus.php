<?php

namespace App\Enums;

/**
 * Mașina de stări a comenzii — specs.md §11.3, plan §9. UN SINGUR loc care știe ce
 * tranziții sunt legale, ca `partially_fulfilled` și `fulfilled` (valul 2, după ce
 * lotul Stoc livrează `RecordStockMovementAction`) să se activeze fără să rescrie
 * regula, doar adăugând acțiunile care le folosesc.
 *
 * Faza asta (§9 — „Comenzi și mașină de stări") implementează efectiv doar
 * `Draft -> Confirmed` (`ConfirmOrderAction`) și `Draft|Confirmed -> Cancelled`
 * (`CancelOrderAction`). Restul tranzițiilor sunt declarate aici, corecte față de
 * tabelul din specs.md §11.3, dar nu au încă o acțiune care le producă.
 *
 * `ValidationException`, nu `AuthorizationException`, e mereu cea aruncată de un
 * apelant care găsește `canTransitionTo() === false`: e o regulă de STARE („comanda
 * asta poate trece în starea X ACUM"), nu de DREPT — același principiu aplicat deja de
 * `MoveDealStageAction` pentru deals (§7.5, DealPolicy — docblock).
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::PartiallyFulfilled, self::Fulfilled, self::Cancelled],
            self::PartiallyFulfilled => [self::Fulfilled],
            self::Fulfilled, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::PartiallyFulfilled => 'Partially fulfilled',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }
}
