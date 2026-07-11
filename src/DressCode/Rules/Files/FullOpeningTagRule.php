<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * The long, lowercase `<?php` opening tag instead of `<?` or `<?PHP`; `<?=` stays.
 */
#[RuleInfo(
	'dresscode/full-opening-tag',
	Stage::Structure,
	description: 'Requires the <?php opening tag',
)]
final class FullOpeningTagRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		foreach ($node->leadingTrivia as $i => $trivia) {
			if ($trivia->kind !== TriviaKind::OpenTag) {
				continue;
			}

			$fixed = (string) preg_replace('~^<\?(?:php)?(?=\s)~i', '<?php', $trivia->text);
			if ($fixed !== $trivia->text && $context->report($node, 'The opening tag must be <?php', trivia: $trivia)) {
				$leading = $node->leadingTrivia;
				$leading[$i] = new Trivia(TriviaKind::OpenTag, $fixed);
				$node->setLeadingTrivia(array_values($leading));
			}
		}
	}
}
