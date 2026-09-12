<?php

namespace App\Exceptions;

use RuntimeException;

class TenantContextMissingException extends RuntimeException
{
    public function __construct(?string $modelClass = null)
    {
        parent::__construct(sprintf(
            'Niciun tenant în context%s. Cererile HTTP îl primesc din ResolveWorkspace; '
            .'joburile și comenzile trebuie să ruleze prin TenantContext::run() (ADR-014).',
            $modelClass ? " pentru {$modelClass}" : ''
        ));
    }
}
