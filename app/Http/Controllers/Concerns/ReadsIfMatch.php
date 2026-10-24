<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Problem\ApiProblem;
use Illuminate\Http\Request;

/** BR-049: PUT/PATCH need If-Match with the current version (428 if missing); same rules as PartyController. */
trait ReadsIfMatch
{
    private function ifMatchVersion(Request $request): int
    {
        $h = (string) $request->headers->get('If-Match', '');
        if ($h === '') {
            throw new ApiProblem('if_match_required');
        }
        if (! preg_match('/^(?:W\/)?"?(\d+)"?$/', trim($h), $m)) {
            throw new ApiProblem('validation_failed', errors: ['If-Match' => ['Send the ETag of the version you edited, e.g. W/"3"']]);
        }

        return (int) $m[1];
    }
}
