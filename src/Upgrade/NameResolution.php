<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Measuring\NamespaceDeclarations;


/**
 * Whether an upgrade resolves the names of the code for certain: with what the namespaces declare, where every file of
 * the scope was read, else not, and why.
 * @internal
 */
final readonly class NameResolution
{
	public function __construct(
		/** what the namespaces declare; null where the names are not resolved for certain */
		public ?NamespaceDeclarations $declarations = null,
		/** why they are not, where the configuration does not leave them to itself */
		public ?string $warning = null,
	) {
	}
}
