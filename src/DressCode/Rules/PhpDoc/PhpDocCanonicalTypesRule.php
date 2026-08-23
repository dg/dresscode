<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node as PhpDocParserNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;
use function count, in_array;


/**
 * The canonical spelling of types in doc comments: built-in types in the short form (`int`, not `integer`)
 * and in lowercase (`int`, not `Int`), arrays in one notation (`array<int>` rather than `int[]`, or the other
 * way round) and a union naming every type once. Class names keep their case, `list<int>` is a different
 * type and stays.
 */
#[RuleInfo(
	'dresscode/phpdoc-canonical-types',
	Stage::Cleanup,
	description: 'Writes the types in doc comments in their canonical form: short lowercase built-ins, one array notation, no repeated type in a union',
	modifiesComments: true,
)]
final class PhpDocCanonicalTypesRule extends Rule implements ConfigurableRule
{
	private const Short = [
		'boolean' => 'bool',
		'callback' => 'callable',
		'double' => 'float',
		'integer' => 'int',
		'real' => 'float',
		'str' => 'string',
	];

	private const BuiltIn = [
		'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null',
		'object', 'parent', 'resource', 'scalar', 'self', 'static', 'string', 'true', 'void',
	];

	private ?string $arrayNotation = 'generic';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'arrayNotation' => Expect::anyOf('generic', 'brackets')->default('generic')->nullable()
				->description('generic writes int[] as array<int>, brackets writes array<int> as int[]; null keeps both'),
		]);
	}


	public function configure(array $options): void
	{
		$this->arrayNotation = $options['arrayNotation'];
	}


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		foreach (['leadingTrivia', 'trailingTrivia'] as $side) {
			$trivia = $node->$side;
			$changed = false;
			foreach ($trivia as $i => $item) {
				if ($item->kind !== TriviaKind::DocComment || $item->inInterpolation) {
					continue;
				}

				$phpDoc = $context->getAnalysis(PhpDoc::class);
				$tree = $phpDoc->parse($item);
				$messages = $this->canonicalize($tree);
				$fix = $messages !== [];
				foreach ($messages as $message) {
					$fix = $context->report($node, $message, trivia: $item) && $fix;
				}

				if (!$fix) {
					continue;
				}

				$trivia[$i] = $phpDoc->print($tree, $item);
				$changed = true;
			}

			if ($changed) {
				$side === 'leadingTrivia' ? $node->setLeadingTrivia($trivia) : $node->setTrailingTrivia($trivia);
			}
		}
	}


	/**
	 * Rewrites the types of the tree into their canonical form.
	 * @return list<string>  what changed, as messages; empty when nothing did
	 */
	private function canonicalize(PhpDocParserNode $tree): array
	{
		$visitor = new class (self::Short, self::BuiltIn, $this->arrayNotation) extends AbstractNodeVisitor {
			/** @var array<string, true> */
			public array $messages = [];

			/** @var \SplObjectStorage<PhpDocParserNode, null>  shape keys are names, not types */
			private \SplObjectStorage $keys;


			/**
			 * @param array<string, string> $short
			 * @param list<string> $builtIn
			 */
			public function __construct(
				private readonly array $short,
				private readonly array $builtIn,
				private readonly ?string $arrayNotation,
			) {
				$this->keys = new \SplObjectStorage;
			}


			/** @return PhpDocParserNode|NodeTraverser::DONT_TRAVERSE_CHILDREN|null */
			public function enterNode(PhpDocParserNode $node): PhpDocParserNode|int|null
			{
				if ($node instanceof DoctrineTagValueNode) {
					return NodeTraverser::DONT_TRAVERSE_CHILDREN; // annotation arguments are values, not types
				}

				if (
					(
						$node instanceof ArrayShapeItemNode
						|| $node instanceof ObjectShapeItemNode
					)
					&& $node->keyName !== null
				) {
					$this->keys[$node->keyName] = null;
				}

				if ($node instanceof IdentifierTypeNode && !isset($this->keys[$node])) {
					$lower = strtolower($node->name);
					$canonical = $this->short[$lower]
						?? (in_array($lower, $this->builtIn, strict: true) ? $lower : null);
					if ($canonical !== null && $node->name !== $canonical) {
						$node->name = $canonical;
						$this->messages['A built-in type in a doc comment must be written in its canonical form'] = true;
					}
				} elseif ($node instanceof ArrayTypeNode && $this->arrayNotation === 'generic') {
					$this->messages["An array type in a doc comment must be written 'array<T>'"] = true;
					return new GenericTypeNode(new IdentifierTypeNode('array'), [$node->type]);
				} elseif (
					$node instanceof GenericTypeNode
					&& $this->arrayNotation === 'brackets'
					&& strtolower($node->type->name) === 'array'
					&& count($node->genericTypes) === 1
					&& (
						$node->genericTypes[0] instanceof IdentifierTypeNode
						|| $node->genericTypes[0] instanceof ArrayTypeNode
						|| $node->genericTypes[0] instanceof GenericTypeNode
					)
				) {
					$this->messages["An array type in a doc comment must be written 'T[]'"] = true;
					return new ArrayTypeNode($node->genericTypes[0]);
				} elseif ($node instanceof UnionTypeNode) {
					$unique = [];
					foreach ($node->types as $type) {
						$unique[(string) $type] ??= $type;
					}

					if (count($unique) < count($node->types)) {
						$this->messages['A union type in a doc comment names a type twice'] = true;
						$types = array_values($unique);
						return count($types) === 1 ? $types[0] : new UnionTypeNode($types);
					}
				}

				return null;
			}
		};
		(new NodeTraverser([$visitor]))->traverse([$tree]);
		return array_keys($visitor->messages);
	}
}
