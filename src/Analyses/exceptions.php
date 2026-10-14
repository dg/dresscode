<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * The code of the file is not code PHP compiles, which the parser of PhpSyntax took and an analysis does not.
 */
final class UncompilableCodeException extends \Exception
{
}
