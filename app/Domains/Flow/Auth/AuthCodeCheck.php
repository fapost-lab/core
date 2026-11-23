<?php

declare(strict_types=1);

namespace App\Domains\Flow\Auth;

/**
 * What {@see AuthCodeStore::check()} found for the code a contact typed.
 */
enum AuthCodeCheck
{
    /** The code is the one issued last and has not expired. */
    case Match;

    /** A code is pending and this is not it. */
    case Mismatch;

    /** No code is pending: it expired, was used up, or the store lost it. */
    case Missing;

    /** The sender reported that the last code never left. */
    case DeliveryFailed;
}
