<?php declare(strict_types=1);

namespace DressCode\Fixtures;

use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\ModifiersNode;
use PhpSyntax\Token;


final class SlotWrites
{
	public function run(TernaryNode $ternary, ModifiersNode $modifiers, Token $token): void
	{
		$ternary->cond = $ternary->else;
		$ternary->if ??= $ternary->else;
		$modifiers->tokens[] = $token;
		$token->parent = $ternary;

		$token->text = 'x';
		$ternary->setIf(null);
		$copy = $ternary->cond;
	}
}
