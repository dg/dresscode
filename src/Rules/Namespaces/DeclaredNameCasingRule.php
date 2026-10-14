<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\{PhpSymbols, Types};
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\{FileNode, NameNode, UseItemNode};
use PhpSyntax\Nodes\Type\NamedTypeNode;
use function count, strlen;


/**
 * Names written in the case of their declaration: classes, interfaces, traits and enums (`stdClass`,
 * `Random\Randomizer`), in a reference and in an import, functions (`strlen()`, not `StrLen()`) and the types of a
 * declaration (`int`, `void`). Without the types of the code only what PHP and the extensions shipped with it declare
 * is known, and a name of the project or of a package keeps the case it is written in. Of a name only the links
 * written are rewritten, so an alias of an import, which is a name of the project, stays, and so does an import of one
 * segment that the file uses as the prefix of a namespace, `use Dibi;` beside a facade `dibi`; the types that are
 * keywords, `array`, `callable`, `static`, `self` and `parent`, are the keywords of `BuiltinCasingRule`.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpSymbols::class, NameResolver::class, Types::class])]
final class DeclaredNameCasingRule extends NodeRule
{
	private const ClassDecision = 'casing.class';
	private const FunctionDecision = 'casing.function';
	private const TypeDecision = 'casing.type';

	private const BuiltinTypes = [
		'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'string', 'true', 'void',
	];

	private bool $class = true;
	private bool $function = true;
	private bool $type = true;

	/** @var array<string, array<string, true>>  the name of a kind => the aliases the imports of the file write for it, lowercased */
	private array $aliases = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::ClassDecision,
				new Words(['declared' => '`stdClass`, `DateTime`, never `datetime`']),
				'The case of a class, an interface, a trait or an enum as its declaration writes it, of one PHP declares only where the configuration sets no `typeAnalysis`',
			),
			new Decision(
				self::FunctionDecision,
				new Words(['declared' => '`strlen`, never `StrLen`']),
				'The case of a function as its declaration writes it, of one PHP declares only where the configuration sets no `typeAnalysis`',
			),
			new Decision(
				self::TypeDecision,
				new Words(['lowercase' => '`int`, `string`, `void`']),
				'The case of the built-in types of a declaration; `array`, `callable`, `static`, `self` and `parent` are keywords',
			),
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
			...($this->class || $this->function ? [FileNode::class, NameNode::class] : []),
			...($this->function ? [FunctionCallNode::class] : []),
			...($this->type ? [NamedTypeNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof FileNode) {
			$this->aliases = [];
			foreach ($node->find(UseItemNode::class) as $item) {
				if ($item->alias !== null) {
					$this->aliases[$item->symbolKind->name][strtolower($item->alias->text)] = true;
				}
			}
		} elseif ($node instanceof NameNode) {
			$this->checkClass($node, $context);
			$this->checkImport($node, $context);
		} elseif ($node instanceof FunctionCallNode) {
			$this->checkFunction($node, $context);
		} elseif ($node instanceof NamedTypeNode) {
			$this->checkType($node, $context);
		}
	}


	private function checkClass(NameNode $node, RuleContext $context): void
	{
		if (!$this->class || $node->symbolKind !== SymbolKind::ClassLike || !$node->isReference()) {
			return;
		}

		if ($this->isAliased($node, $node->parts[0])) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveClass($node);
		$this->rewriteLinks(
			$node,
			$this->findClassName($resolved, $context),
			'The class name `%s` must be written `%s`.',
			self::ClassDecision,
			$context,
		);
	}


	/** The class or the function an import brings in, of a group use the links its item writes. */
	private function checkImport(NameNode $node, RuleContext $context): void
	{
		$item = $node->parent;
		if (!$item instanceof UseItemNode || $item->name !== $node) {
			return;
		}

		if ($this->class && $item->symbolKind === SymbolKind::ClassLike && !self::importsNamespace($item, $context)) {
			$this->rewriteLinks(
				$node,
				$this->findClassName($item->fullName, $context),
				'The class name `%s` must be written `%s`.',
				self::ClassDecision,
				$context,
			);
		} elseif ($this->function && $item->symbolKind === SymbolKind::Function) {
			$this->rewriteLinks(
				$node,
				$this->findFunctionName($item->fullName, global: true, context: $context),
				'The function `%s()` must be written `%s()`.',
				self::FunctionDecision,
				$context,
			);
		}
	}


	/**
	 * Whether the import of a name of one segment, `use Dibi;`, serves as the prefix of qualified names, `Dibi\Row`: it
	 * then imports a namespace, which a class of the name declared beside it in another case says nothing about.
	 */
	private static function importsNamespace(UseItemNode $item, RuleContext $context): bool
	{
		$alias = $item->alias->text ?? $item->name->shortName;
		return !str_contains($item->fullName, '\\')
			&& $context->file->find(
				NameNode::class,
				fn(NameNode $name) => $name->form === NameForm::Qualified && strcasecmp($name->parts[0], $alias) === 0,
			) !== [];
	}


	/**
	 * The links of the name as the declaration writes them, where they differ from those written; null where the
	 * declaration is not known or the name is written so already, and where its links are not those of the declaration,
	 * an alias of an import or a name relative to the namespace.
	 */
	private function findDeclaredLinks(NameNode $node, ?string $canonical): ?string
	{
		if ($canonical === null || $node->form === NameForm::Relative) {
			return null;
		}

		$written = explode('\\', ltrim($node->text, '\\'));
		$declared = array_slice(explode('\\', $canonical), -count($written));
		return count($declared) === count($written)
			&& strcasecmp(implode('\\', $declared), implode('\\', $written)) === 0
			&& $declared !== $written
				? implode('\\', $declared)
				: null;
	}


	/** Writes the links of the name as the declaration does, reported with the message of the name as written and as declared. */
	private function rewriteLinks(NameNode $node, ?string $canonical, string $message, string $decision, RuleContext $context): void
	{
		$links = $this->findDeclaredLinks($node, $canonical);
		if ($links !== null && $context->report($node, sprintf($message, ltrim($node->text, '\\'), $links), decision: $decision)) {
			$node->text = (str_starts_with($node->text, '\\') ? '\\' : '') . $links;
		}
	}


	/**
	 * Whether the name starts with an alias an import writes, which is a name of the project however close to the name
	 * it imports: `use Acme\Shop\OrderItem as orderitem;` keeps `new orderitem`.
	 */
	private function isAliased(NameNode $node, string $first): bool
	{
		return ($node->form === NameForm::Unqualified || $node->form === NameForm::Qualified)
			&& isset($this->aliases[$node->symbolKind->name][strtolower($first)]);
	}


	/** The declared spelling of the class, from the types of the code where the run has them. */
	private function findClassName(string $name, RuleContext $context): ?string
	{
		return $context->findAnalysis(Types::class)?->findClassName($name)
			?? $context->getAnalysis(PhpSymbols::class)->findClassName($name);
	}


	private function checkFunction(FunctionCallNode $node, RuleContext $context): void
	{
		if (!$node->name instanceof NameNode) {
			return;
		}

		$written = $node->name->shortName;
		if ($this->isAliased($node->name, $written)) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$declared = $this->findFunctionName($resolver->resolveFunction($node->name), $resolver->isGlobalFunctionCall($node), $context);
		$canonical = $declared === null ? null : substr((string) strrchr('\\' . $declared, '\\'), 1);
		if (
			$canonical === null
			|| $canonical === $written
			// an alias of an import is a name of the project, not the name of the function it calls
			|| strcasecmp($canonical, $written) !== 0
			|| !$context->report($node->name, "The function `$written()` must be written `$canonical()`.", decision: self::FunctionDecision)
		) {
			return;
		}

		$token = $node->name->token;
		$token->setText(substr($token->text, 0, strlen($token->text) - strlen($written)) . $canonical);
	}


	/**
	 * The declared spelling of the function, from the types of the code where the run has them, else of a global
	 * function PHP declares.
	 */
	private function findFunctionName(string $name, bool $global, RuleContext $context): ?string
	{
		return $context->findAnalysis(Types::class)?->findFunctionName($name)
			?? ($global && $context->getAnalysis(PhpSymbols::class)->isBuiltinFunction($name) ? strtolower($name) : null);
	}


	private function checkType(NamedTypeNode $node, RuleContext $context): void
	{
		$written = $node->name->text;
		$lower = strtolower($written);
		if (
			$written !== $lower
			&& in_array($lower, self::BuiltinTypes, true)
			&& $context->report($node, "The type `$written` must be written `$lower`.", decision: self::TypeDecision)
		) {
			$node->name->text = $lower;
		}
	}
}
