<?php

namespace App;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class LostPaymentEventClaim extends Exception implements ShouldntReport {}
