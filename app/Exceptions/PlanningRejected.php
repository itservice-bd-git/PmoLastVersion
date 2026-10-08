<?php

namespace App\Exceptions;

use RuntimeException;

/** An edit sent from the /planning page that PMO refuses (no permission, not supported, not allowed right now). */
class PlanningRejected extends RuntimeException {}
