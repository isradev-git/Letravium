<?php

namespace App\Exceptions;

use RuntimeException;

class RevisionConflict extends RuntimeException
{
    public function __construct(public readonly int $currentRevision, public readonly ?int $sceneId = null)
    {
        parent::__construct('Conflicto de revisión.');
    }
}
