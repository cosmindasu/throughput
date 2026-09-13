<?php

namespace App\Policies;

use App\Models\SavedView;
use App\Models\User;

/**
 * Matricea §7.4, rândurile „Vizualizări salvate — private/echipă": toate cele 4 roluri au
 * CRUD pe private, dar doar Owner/Manager pe echipă (Agent/Viewer rămân la `R`).
 *
 * Diferă de `AccountPolicy`/`DealPolicy`: acolo ABAC-ul îngustează la „proprii", aici
 * `visibility` a vederii decide ce PERMISIUNE se cere, nu cine a creat-o — un Manager
 * editează o vedere „Team" creată de alt Manager, dar nu poate atinge vederea PRIVATĂ a
 * unui Agent (nicio permisiune nu acoperă „private ale altcuiva", nici la Owner).
 */
class SavedViewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('saved_views.manage_own') || $user->can('saved_views.view_team');
    }

    public function view(User $user, SavedView $savedView): bool
    {
        if ($savedView->visibility === SavedView::VISIBILITY_TEAM) {
            return $user->can('saved_views.view_team');
        }

        return $user->can('saved_views.manage_own') && $savedView->user_id === $user->getKey();
    }

    /**
     * Orice rol din matrice poate crea o vedere PRIVATĂ. Vizibilitatea „Team" cerută la
     * creare se verifică separat — `createTeam()` — pentru că `create()` nu primește
     * încă niciun model din care să citească `visibility`.
     */
    public function create(User $user): bool
    {
        return $user->can('saved_views.manage_own');
    }

    /**
     * §7.4: „un Agent/Viewer nu poate crea sau transforma o vedere în «team»" — verificată
     * explicit de `SavedViewController` la creare (când `visibility=team`) și la
     * actualizare (când o vedere privată devine „team").
     */
    public function createTeam(User $user): bool
    {
        return $user->can('saved_views.manage_team');
    }

    public function update(User $user, SavedView $savedView): bool
    {
        return $this->canManage($user, $savedView);
    }

    public function delete(User $user, SavedView $savedView): bool
    {
        return $this->canManage($user, $savedView);
    }

    private function canManage(User $user, SavedView $savedView): bool
    {
        if ($savedView->visibility === SavedView::VISIBILITY_TEAM) {
            return $user->can('saved_views.manage_team');
        }

        return $user->can('saved_views.manage_own') && $savedView->user_id === $user->getKey();
    }
}
