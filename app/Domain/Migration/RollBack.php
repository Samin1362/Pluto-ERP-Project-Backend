<?php

namespace App\Domain\Migration;

use RuntimeException;

/** @internal Thrown inside OpeningImportService's transaction to roll it back after a dry run or an error. */
final class RollBack extends RuntimeException {}
