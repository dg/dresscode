<?php declare(strict_types=1);

namespace DressCode\Fixtures;

use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\ModifiersNode;
use PhpSyntax\Token;


final class TreeWrites
{
	public function run(TernaryNode $ternary, ModifiersNode $modifiers, Token $token): void
	{
		$ternary->cond = $ternary->else;
		$ternary->if ??= $ternary->else;
		$modifiers->tokens[] = $token;
		$token->parent = $ternary;

		$token->text = 'x';
		$token->leadingTrivia = [];
		$token->index = 1;
		$token->setText('x');
		$ternary->setIf(null);
		$copy = $ternary->cond;
	}
}
