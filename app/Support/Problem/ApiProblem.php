<?php

namespace App\Support\Problem;

use RuntimeException;

/**
 * An expected, client-facing failure with a stable catalogue code (05 §3). Domain services throw it for
 * business-rule violations; ProblemRenderer turns it into application/problem+json.
 */
class ApiProblem extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field errors keyed by dot path
     * @param  array<string, mixed>  $extra  additional problem members (e.g. current_version)
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        ?string $detail = null,
        public readonly array $errors = [],
        public readonly array $extra = [],
        public readonly array $headers = [],
        ?int $status = null,
    ) {
        $this->status = $status ?? ErrorCatalogue::status($errorCode);
        parent::__construct($detail ?? ErrorCatalogue::title($errorCode));
        $this->detail = $detail;
    }

    public readonly int $status;

    public readonly ?string $detail;
}
