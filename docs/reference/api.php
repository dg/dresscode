<?php declare(strict_types=1);


/**
 * Generates api.md, the public surface as reflection sees it: every class of src/ not marked @internal with its public
 * members not marked @internal nor overriding one that is, a built-in rule by its class alone, each type as the phpDoc
 * gives it where it does, with the names of classes in full as the native types have them. Run by `composer api`;
 * the output is committed and CI diffs it, so that a change of the surface shows in the diff of the commit making it.
 * Which class is public is decided by its @internal tag, which tests/DressCode/api.phpt holds to the lists of
 * docs/internals.md.
 */
use DressCode\Rule;
use PHPStan\PhpDocParser\Ast\{AbstractNodeVisitor, NodeTraverser};
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\{IdentifierTypeNode, TypeNode};
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\{ConstExprParser, PhpDocParser, TokenIterator, TypeParser};
use PHPStan\PhpDocParser\ParserConfig;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\{ClassLikeNode, NameNode};
use PhpSyntax\Parser;

require __DIR__ . '/../../vendor/autoload.php';


/**
 * A method overriding an internal one is internal too.
 * @param ReflectionClass<*>|ReflectionClassConstant|ReflectionMethod|ReflectionProperty $reflection
 */
function isInternal(ReflectionClass|ReflectionClassConstant|ReflectionMethod|ReflectionProperty $reflection): bool
{
	if (preg_match('~^\s*(/\*)?\*\s*@internal\b~m', (string) $reflection->getDocComment())) {
		return true;
	} elseif ($reflection instanceof ReflectionMethod && $reflection->hasPrototype()) {
		return isInternal($reflection->getPrototype());
	}

	return false;
}


function parsePhpDoc(string|false $comment): PhpDocNode
{
	static $lexer, $parser;
	if ($parser === null) {
		$config = new ParserConfig([]);
		$lexer = new Lexer($config);
		$constants = new ConstExprParser($config);
		$parser = new PhpDocParser($config, new TypeParser($config, $constants), $constants);
	}

	return $parser->parse(new TokenIterator($lexer->tokenize($comment ?: '/** */')));
}


/**
 * The names of the templates the doc comments declare, which stand for no class.
 * @return list<string>
 */
function readTemplates(string|false ...$comments): array
{
	$templates = [];
	foreach ($comments as $comment) {
		foreach (parsePhpDoc($comment)->getTemplateTagValues() as $tag) {
			$templates[] = $tag->name;
		}
	}

	return $templates;
}


/**
 * The type of the phpDoc with the names of classes in full, as the class reaches them, and `self` as the class.
 * @param  ReflectionClass<*>  $class
 * @param  list<string>  $templates
 */
function formatPhpDocType(TypeNode $type, ReflectionClass $class, array $templates): string
{
	static $resolvers = [];
	$file = (string) $class->getFileName();
	if (!isset($resolvers[$file])) {
		$tree = (new Parser)->parse((string) file_get_contents($file));
		$resolvers[$file] = [$tree, new NameResolver($tree)];
	}

	[$tree, $resolver] = $resolvers[$file];
	$at = $tree->find(ClassLikeNode::class, fn(ClassLikeNode $node) => $resolver->getDeclaredName($node) === $class->getName())[0] ?? $tree;
	$resolve = fn(string $name): string => match (true) {
		$name === 'self' => $class->getName(),
		in_array($name, $templates, true), !preg_match('~^\\\\?[A-Z]~', $name) => $name, // a keyword or a template
		default => ltrim($resolver->resolveClass(NameNode::fromText($name), $at), '\\'),
	};
	new NodeTraverser([new class ($resolve) extends AbstractNodeVisitor {
		public function __construct(
			/** @var Closure(string): string */
			private readonly Closure $resolve,
		) {
		}


		public function enterNode(PHPStan\PhpDocParser\Ast\Node $node): null
		{
			if ($node instanceof IdentifierTypeNode) {
				$node->name = ($this->resolve)($node->name);
			}

			return null;
		}
	}])->traverse([$type]);
	return compactType((string) $type);
}


/**
 * The type as code writes it, `int|null` where the printer of the parser writes `(int | null)`: parentheses stay
 * where they mean something, around the parameters of a callable, before `[]` or `&`, and around a conditional type.
 */
function compactType(string $type): string
{
	$type = str_replace([' | ', ' & '], ['|', '&'], $type);
	$open = $dropped = [];
	foreach (str_split($type) as $i => $char) {
		if ($char === '(') {
			$open[] = $i;
		} elseif ($char === ')') {
			$start = (int) array_pop($open);
			if (
				!preg_match('~[\w\\\\&?]~', $start > 0 ? $type[$start - 1] : ' ')
				&& !in_array($type[$i + 1] ?? '', ['[', '&'], true)
				&& !str_contains(substr($type, $start, $i - $start), ' is ')
			) {
				array_push($dropped, $start, $i);
			}
		}
	}

	rsort($dropped);
	foreach ($dropped as $i) {
		$type = substr_replace($type, '', $i, 1);
	}

	return $type;
}


/**
 * `self` and `parent` are written as the classes they mean, which is how some versions of PHP stringify them and others do not.
 * @param ReflectionClass<*> $class
 */
function formatTypeName(ReflectionType $type, ReflectionClass $class): string
{
	$parent = $class->getParentClass();
	$name = (string) preg_replace('~\bself\b~', $class->getName(), (string) $type);
	return $parent ? (string) preg_replace('~\bparent\b~', $parent->getName(), $name) : $name;
}


/** @param ReflectionClass<*> $class */
function formatType(?ReflectionType $type, ReflectionClass $class): string
{
	return $type === null ? '' : formatTypeName($type, $class) . ' ';
}


function formatLiteral(mixed $value): string
{
	return match (true) {
		$value === null => 'null',
		$value === [] => '[]',
		is_array($value) => '[' . implode(', ', array_map(
			fn($key, $item) => (array_is_list($value) ? '' : formatLiteral($key) . ' => ') . formatLiteral($item),
			array_keys($value),
			$value,
		)) . ']',
		$value instanceof UnitEnum => $value::class . '::' . $value->name,
		is_object($value) => 'new ' . $value::class,
		is_string($value) && preg_match('~[\x00-\x1F]~', $value) => '"' . addcslashes($value, "\0..\37\"\\\$") . '"',
		default => var_export($value, true),
	};
}


/** @param ReflectionClass<*> $class */
function formatParameter(ReflectionParameter $parameter, ReflectionClass $class, ?string $phpDocType): string
{
	$default = '';
	if ($parameter->isDefaultValueAvailable()) {
		$default = ' = ' . ($parameter->getDefaultValueConstantName() ?? formatLiteral($parameter->getDefaultValue()));
	}

	return ($phpDocType === null ? formatType($parameter->getType(), $class) : "$phpDocType ")
		. ($parameter->isPassedByReference() ? '&' : '')
		. ($parameter->isVariadic() ? '...' : '')
		. '$' . $parameter->getName()
		. $default;
}


function formatMethod(ReflectionMethod $method): string
{
	$class = $method->getDeclaringClass();
	$doc = parsePhpDoc($method->getDocComment());
	$templates = readTemplates($class->getDocComment(), $method->getDocComment());
	$types = [];
	foreach ($doc->getParamTagValues() as $tag) {
		$types[$tag->parameterName] = formatPhpDocType($tag->type, $class, $templates);
	}

	$parameters = array_map(fn($parameter) => formatParameter($parameter, $class, $types['$' . $parameter->getName()] ?? null), $method->getParameters());
	$return = $doc->getReturnTagValues()[0]->type ?? null;
	return ($method->isAbstract() && !$class->isInterface() ? 'abstract ' : '')
		. ($method->isFinal() ? 'final ' : '')
		. 'public '
		. ($method->isStatic() ? 'static ' : '')
		. 'function ' . $method->getName() . '(' . implode(', ', $parameters) . ')'
		. match (true) {
			$return !== null => ': ' . formatPhpDocType($return, $class, $templates),
			$method->hasReturnType() => ': ' . formatTypeName($method->getReturnType(), $class),
			default => '',
		};
}


function formatProperty(ReflectionProperty $property): string
{
	return 'public '
		. ($property->isStatic() ? 'static ' : '')
		. ($property->isReadOnly() ? 'readonly ' : '')
		. ($property->isPrivateSet() && !$property->isReadOnly() ? 'private(set) ' : '')
		. ($property->isProtectedSet() && !$property->isReadOnly() ? 'protected(set) ' : '')
		. (($var = parsePhpDoc($property->getDocComment())->getVarTagValues()[0]->type ?? null) === null
			? formatType($property->getType(), $property->getDeclaringClass())
			: formatPhpDocType($var, $property->getDeclaringClass(), readTemplates($property->getDeclaringClass()->getDocComment())) . ' ')
		. '$' . $property->getName()
		. ($property->hasDefaultValue() && $property->getDefaultValue() !== null ? ' = ' . formatLiteral($property->getDefaultValue()) : '');
}


/** @param ReflectionClass<*> $class */
function formatHead(ReflectionClass $class): string
{
	$parent = $class->getParentClass();
	$interfaces = $class->getInterfaceNames();
	sort($interfaces);
	if ($class instanceof ReflectionEnum) {
		$backing = $class->getBackingType();
		$interfaces = array_values(array_diff($interfaces, [UnitEnum::class, BackedEnum::class]));
		return 'enum ' . $class->getShortName() . ($backing === null ? '' : ": $backing")
			. ($interfaces === [] ? '' : ' implements ' . implode(', ', $interfaces));
	} elseif ($class->isInterface()) {
		return 'interface ' . $class->getShortName() . ($interfaces === [] ? '' : ' extends ' . implode(', ', $interfaces));
	}

	return ($class->isAbstract() ? 'abstract ' : '')
		. ($class->isFinal() ? 'final ' : '')
		. ($class->isReadOnly() ? 'readonly ' : '')
		. 'class ' . $class->getShortName()
		. ($parent === false ? '' : ' extends ' . $parent->getName())
		. ($interfaces === [] ? '' : ' implements ' . implode(', ', $interfaces));
}


/**
 * @param  ReflectionClass<*>  $class
 * @return list<string>
 */
function formatMembers(ReflectionClass $class): array
{
	$lines = [];
	$own = fn(ReflectionClassConstant|ReflectionMethod|ReflectionProperty $member) => $member->getDeclaringClass()->getName() === $class->getName()
		&& $member->isPublic()
		&& !isInternal($member);
	foreach ($class->getReflectionConstants() as $constant) {
		if ($own($constant)) {
			$lines[] = $constant->isEnumCase()
				? 'case ' . $constant->getName() . ($constant->getValue() instanceof BackedEnum ? ' = ' . formatLiteral($constant->getValue()->value) : '')
				: 'public const ' . $constant->getName() . ' = ' . formatLiteral($constant->getValue());
		}
	}

	foreach ($class->getProperties() as $property) {
		if ($own($property) && !$class->isEnum()) {
			$lines[] = formatProperty($property);
		}
	}

	foreach ($class->getMethods() as $method) {
		if ($own($method) && !($class->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true))) {
			$lines[] = formatMethod($method);
		}
	}

	return $lines;
}


$src = (string) realpath(__DIR__ . '/../../src');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
	if ($file->getExtension() === 'php') {
		require_once (string) $file;
	}
}

$classes = [];
foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $name) {
	$class = enum_exists($name) ? new ReflectionEnum($name) : new ReflectionClass($name);
	if (str_starts_with((string) $class->getFileName(), $src) && !isInternal($class)) {
		$classes[$name] = $class;
	}
}

ksort($classes, SORT_STRING | SORT_FLAG_CASE);

$rules = [];
$out = "# Public API\n\nGenerated by `composer api` from the classes of `src/` that are not `@internal`, with their public members that\n"
	. "are not either; a built-in rule is named by its class alone, its decisions being in [decisions.md](decisions.md).\n";
foreach ($classes as $name => $class) {
	if (str_starts_with($name, 'DressCode\Rules\\') && $class->isSubclassOf(Rule::class) && !$class->isAbstract()) {
		$rules[] = $name;
		continue;
	}

	$members = formatMembers($class);
	$out .= "\n## `$name`\n\n```php\n" . formatHead($class) . "\n" . implode('', array_map(fn($line) => "\t$line\n", $members)) . "```\n";
}

$out .= "\n## Rules\n\n" . implode('', array_map(fn($rule) => "- `$rule`\n", $rules));
file_put_contents(__DIR__ . '/api.md', $out);
echo 'api.md: ', count($classes) - count($rules), ' classes, ', count($rules), " rules\n";
