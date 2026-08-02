<?php declare(strict_types=1);

namespace DressCode\Rules\Variables;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\AssignNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Nodes\Statement\UnsetNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * A property is set to null instead of being unset: `$this->a = null;`, not `unset($this->a);`, which
 * would remove the property and trigger the magic methods on the next access. Risky: the two differ.
 */
#[RuleInfo(
	'dresscode/no-unset-on-property',
	Stage::Structure,
	description: 'Assigns null to a property instead of unsetting it',
)]
final class NoUnsetOnPropertyRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [UnsetNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UnsetNode || !($list = $node->parent) instanceof NodeList || $node->hasComment()) {
			return;
		}

		$vars = $node->vars->getItems();
		foreach ($vars as $var) {
			if (!self::isProperty($var)) {
				return;
			}
		}

		if ($vars === [] || !$context->report($node, 'A property must be set to null, not unset')) {
			return;
		}

		$indentation = $node->getFirstToken()?->getLineIndentation() ?? '';
		$index = $list->indexOf($node);
		foreach ($vars as $i => $var) {
			$assignment = (new Parser)->parseStatement('$a = null;');
			$assign = $assignment instanceof ExpressionStatementNode ? $assignment->expr : null;
			assert($assign instanceof AssignNode);
			$copy = clone $var;
			$copy->getFirstToken()?->setLeadingTrivia([]);
			$copy->getLastToken()?->setTrailingTrivia([]);
			$assign->var->replaceWith($copy);
			if ($i === 0) {
				$node->replaceWith($assignment);
				continue;
			}

			$assignment->getFirstToken()?->setLeadingTrivia($indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)]);
			$assignment->getLastToken()?->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol)]);
			$list->insert($index + $i, $assignment);
			$assignment->getFirstToken()?->ensureLeadingNewline($context->getStyle()->eol);
		}
	}


	/** A property of an object or a class, with a plain name: `$a->b`, `$a->b->c`, `A::$b`. */
	private static function isProperty(ExpressionNode $expr): bool
	{
		return ($expr instanceof PropertyFetchNode && $expr->name instanceof IdentifierNode && $expr->operator->is('->'))
			|| $expr instanceof StaticPropertyFetchNode;
	}
}
