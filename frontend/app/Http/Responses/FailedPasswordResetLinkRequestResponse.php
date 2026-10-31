<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse as FortifyResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il recupero password non deve dire se un'email è registrata. Per un'email
 * sconosciuta, o già servita da poco (il limite del broker vale solo per gli utenti
 * che esistono), la risposta è la stessa di un invio riuscito; il messaggio
 * (passwords.sent) è scritto per andare bene in tutti i casi.
 */
class FailedPasswordResetLinkRequestResponse extends FortifyResponse
{
    private const UNDISCLOSED = [Password::INVALID_USER, Password::RESET_THROTTLED];

    public function toResponse($request): Response
    {
        if (in_array($this->status, self::UNDISCLOSED, true)) {
            return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT])
                ->toResponse($request);
        }

        return parent::toResponse($request);
    }
}
