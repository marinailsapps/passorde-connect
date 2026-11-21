<?php

namespace Passorde\Writes\Support;

use RuntimeException;

/**
 * Thrown by a lookup when sending the request again could do harm and what happened cannot be told.
 */
final class UncertainWrite extends RuntimeException {}
