<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpSymbols;
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Type\NamedTypeNode;
use function strlen;


/**
 * Names PHP declares written in the case of their declaration: classes, interfaces and enums of PHP and of the
 * extensions shipped with it (`stdClass`, `Random\Randomizer`), its functions (`strlen()`, not `StrLen()`) and the
 * types of a declaration (`int`, `void`). A global function of the project, or of an extension PHP does not ship,
 * keeps the case it is written in; the types that are keywords, `array`, `callable`, `static`, `self` and
 * `parent`, are the keywords of `BuiltinCasingRule`.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpSymbols::class, NameResolver::class])]
final class BuiltinNameCasingRule extends NodeRule
{
	private const ClassDecision = 'builtin.casing.class';
	private const FunctionDecision = 'builtin.casing.function';
	private const TypeDecision = 'builtin.casing.type';

	private const Types = ['bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'string', 'true', 'void'];

	private bool $class = true;
	private bool $function = true;
	private bool $type = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::ClassDecision, new Words(['declared' => '`stdClass`, `DateTime`, never `datetime`']), 'The case of the classes, interfaces and enums of PHP and of the extensions shipped with it'),
			new Decision(self::FunctionDecision, new Words(['declared' => '`strlen`, never `StrLen`']), 'The case of the functions of PHP, a global function of the project or of an extension PHP does not ship keeping its own'),
			new Decision(self::TypeDecision, new Words(['lowercase' => '`int`, `string`, `void`']), 'The case of the built-in types of a declaration; `array`, `callable`, `static`, `self` and `parent` are keywords'),
		];
	}


	public function configure(Values $values): void
	{
		$this->class = !$values->isKept(self::ClassDecision);
		$this->function = !$values->isKept(self::FunctionDecision);
		$this->type = !$values->isKept(self::TypeDecision);
	}


	public function getVisitedNodes(): array
	{
		return [
			...($this->class ? [NameNode::class] : []),
			...($this->function ? [FunctionCallNode::class] : []),
			...($this->type ? [NamedTypeNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NameNode) {
			$this->checkClass($node, $context);
		} elseif ($node instanceof FunctionCallNode) {
			$this->checkFunction($node, $context);
		} elseif ($node instanceof NamedTypeNode) {
			$this->checkType($node, $context);
		}
	}


	private function checkClass(NameNode $node, RuleContext $context): void
	{
		if ($node->symbolKind !== SymbolKind::ClassLike || !$node->isReference()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveClass($node);
		$canonical = $context->getAnalysis(PhpSymbols::class)->findClassName($resolved);
		if (
			$canonical === null
			|| $resolved === $canonical
			|| $resolved !== implode('\\', $node->parts) // an alias or a namespace resolves elsewhere
			|| !$context->report($node, "The class name `$resolved` must be written `$canonical`.", decision: self::ClassDecision)
		) {
			return;
		}

		$node->text = ($node->form === NameForm::FullyQualified ? '\\' : '') . $canonical;
	}


	private function checkFunction(FunctionCallNode $node, RuleContext $context): void
	{
		if (!$node->name instanceof NameNode) {
			return;
		}

		$written = $node->name->shortName;
		$lower = strtolower($written);
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			$written === $lower
			|| !$resolver->isGlobalFunctionCall($node)
			// an alias of an import is a name of the project, not the name of the function it calls
			|| strcasecmp($resolver->resolveFunction($node->name), $written) !== 0
			|| !$context->getAnalysis(PhpSymbols::class)->isBuiltinFunction($lower)
		) {
			return;
		}

		if ($context->report($node->name, "The function `$written()` must be written `$lower()`.", decision: self::FunctionDecision)) {
			$token = $node->name->token;
			$token->setText(substr($token->text, 0, strlen($token->text) - strlen($written)) . $lower);
		}
	}


	private function checkType(NamedTypeNode $node, RuleContext $context): void
	{
		$written = $node->name->text;
		$lower = strtolower($written);
		if (
			$written !== $lower
			&& in_array($lower, self::Types, true)
			&& $context->report($node, "The type `$written` must be written `$lower`.", decision: self::TypeDecision)
		) {
			$node->name->text = $lower;
		}
	}
}
