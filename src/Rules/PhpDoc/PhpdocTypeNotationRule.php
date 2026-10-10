<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\{PhpDoc, Types};
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\{Shapes, Words};
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};


/**
 * The notation of types in doc comments: built-in types in the short form (`int`, not `integer`) and in lowercase
 * (`int`, not `Int`) and a union naming every type once, as `phpdoc.types.builtin` says, arrays in one notation
 * (`array<int>` rather than `int[]`, or the other way round). A union is decided as `TypeNotationRule` decides a
 * native one, by decisions of its own: a single type with `null` may be written `?T`, `null` may stand at one end
 * and the other types in alphabetical order. Class names keep their case, `list<int>` is a
 * different type and stays. A name imported or declared as a class (`Resource`) stays too; without `typeAnalysis`
 * a class declared in another file is not seen.
 */
#[RuleInfo(Stage::Finishing, modifiesComments: true, analyses: [PhpDoc::class, Types::class, NameResolver::class])]
final class PhpdocTypeNotationRule extends NodeRule
{
	private const BuiltinNames = 'phpdoc.types.builtin';
	private const Nullable = 'phpdoc.types.nullable';
	private const NullPosition = 'phpdoc.types.nullPosition';
	private const UnionOrder = 'phpdoc.types.unionOrder';
	private const Array = 'phpdoc.types.array';

	private bool $canonical = true;
	private bool $shortNullable = false;
	private ?string $nullPosition = null;
	private bool $byName = false;
	private ?string $arrayNotation;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::BuiltinNames, new Words(['canonical' => 'the short lowercase name of a built-in type, each type of a union named once']), 'How a built-in type in a doc comment is written, `int` and never `integer` or `Int`, and whether a union names a type twice'),
			new Decision(self::Nullable, new Shapes(['questionMark' => ['?T', 'a single type with `null` written with `?`']]), 'How a type of a doc comment of one type and `null` is written'),
			new Decision(self::NullPosition, new Words(['last' => '`int|string|null`', 'first' => '`null|int|string`']), 'Where `null` stands in a union type of a doc comment'),
			new Decision(self::UnionOrder, new Words(['byName' => 'the types beside `null` sorted by name, case-insensitively']), 'The order of the types of a union type of a doc comment'),
			new Decision(self::Array, new Shapes([
				'generic' => ['array<T>', 'the generic notation'],
				'brackets' => ['T[]', 'the brackets'],
			]), 'How an array type of a doc comment is written; `list<T>` is a different type and stays'),
		];
	}


	public function configure(Values $values): void
	{
		$this->canonical = !$values->isKept(self::BuiltinNames);
		$this->shortNullable = !$values->isKept(self::Nullable);
		$this->nullPosition = $values->find(self::NullPosition)?->getWord();
		$this->byName = !$values->isKept(self::UnionOrder);
		$this->arrayNotation = $values->find(self::Array)?->getShape();
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->hasComment()) {
			return;
		}

		// `Str` or `Resource` imported or declared as a class is that class, not a built-in type
		$isClass = function (string $name) use ($node, $context): bool {
			$resolver = $context->getAnalysis(NameResolver::class);
			$class = ltrim($resolver->getNamespace($node) . '\\' . $name, '\\');
			return isset($resolver->getImports(SymbolKind::ClassLike, $node)[strtolower($name)])
				|| $resolver->findDeclaration($class, SymbolKind::ClassLike) !== null
				|| $context->findAnalysis(Types::class)?->findClassName($class) !== null;
		};

		foreach ($node->getDocComments() as $trivia) {
			$phpDoc = $context->getAnalysis(PhpDoc::class);
			$tree = $phpDoc->parse($trivia);
			$visitor = new PhpdocTypeNotationVisitor($isClass, $this->canonical, $this->shortNullable, $this->nullPosition, $this->byName, $this->arrayNotation);
			new NodeTraverser([$visitor])->traverse([$tree]);
			$messages = $visitor->messages;
			$fix = $messages !== [];
			foreach ($messages as [$message, $decision]) {
				$fix = $context->report($node, $message, decision: $decision, trivia: $trivia) && $fix;
			}

			if ($fix) {
				$node->replaceTrivia($trivia, $phpDoc->print($tree, $trivia));
			}
		}
	}
}
