<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * An analysis computed at the beginning of a pass and kept over the mutations in it, because computing it
 * anew after every mutation would cost more than the staleness: the types of the code, which the next pass
 * computes again over the new text. A node inserted during the pass has no answer in it.
 */
interface PassAnalysis
{
}
