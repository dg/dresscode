<?php declare(strict_types=1);

namespace Acme\PhpStanRules;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\{DynamicFunctionReturnTypeExtension, StringType, Type};


/** Says that `findText()`, declared to give a string or null, always gives a string. */
final class FindTextExtension implements DynamicFunctionReturnTypeExtension
{
	public function isFunctionSupported(FunctionReflection $functionReflection): bool
	{
		return $functionReflection->getName() === 'findText';
	}


	public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): Type
	{
		return new StringType;
	}
}
