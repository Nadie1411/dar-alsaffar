<?php

namespace App\Services\Store\Payments;

use RuntimeException;

/**
 * MyFatoorah answered, and the answer was no: a rejected request, a key that
 * is not allowed to do this, a payment it does not know. Trying again as it
 * stands will not change the outcome.
 */
class MyFatoorahException extends RuntimeException {}
