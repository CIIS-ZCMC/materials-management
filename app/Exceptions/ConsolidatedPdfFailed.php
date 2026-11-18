<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside PoConsolidatedPdfService to stop a rebuild. The message is short, plain and
 * safe to show to the person (no paths, no program output).
 */
final class ConsolidatedPdfFailed extends RuntimeException {}