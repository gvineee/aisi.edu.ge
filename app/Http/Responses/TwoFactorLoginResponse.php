<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/** The same landing rule after the MFA step. */
class TwoFactorLoginResponse extends LoginResponse implements TwoFactorLoginResponseContract
{
    protected function json(): Response
    {
        return new JsonResponse('', 204);
    }
}
