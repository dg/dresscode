<?php declare(strict_types=1);

namespace DressCode;


/**
 * Stage of a pass: structural changes come first, formatting second, the final cleanup of whitespace last.
 */
enum Stage
{
	case Structure;
	case Formatting;
	case Cleanup;
}


enum Severity
{
	case Error;
	case Warning;
}
