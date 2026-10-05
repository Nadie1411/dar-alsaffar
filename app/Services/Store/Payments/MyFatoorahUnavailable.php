<?php

namespace App\Services\Store\Payments;

/**
 * MyFatoorah could not be reached, or failed on its side. Nothing is known
 * about the payment, so nothing should be decided — it is worth asking again.
 */
class MyFatoorahUnavailable extends MyFatoorahException {}
