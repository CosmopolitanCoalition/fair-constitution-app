<?php

namespace App\Exceptions;

/**
 * Thrown by MapDataExportService::runPgDump when the operator-driven
 * halt cache flag has been set. The ExportMapDataJob catches this
 * specifically (vs the generic Throwable path) so it can record a
 * `status: halted` row in the export's `.status.json` instead of a
 * `status: failed` row with an error message. The two states are
 * visually distinct in the export listing UI: halts are amber +
 * neutral, failures are red.
 */
class ExportHaltedException extends \RuntimeException
{
}
