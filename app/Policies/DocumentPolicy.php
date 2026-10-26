<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    /**
     * Viewing, reprocessing and deleting are all limited to the owner. Other
     * people's documents answer 404, so their ids don't reveal they exist.
     */
    public function manage(User $user, Document $document): Response
    {
        return $document->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
