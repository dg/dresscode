<?php declare(strict_types=1);

namespace PhpSyntax;


enum TriviaKind
{
	case Whitespace;
	case EndOfLine;
	case Comment;
	case DocComment;
	case OpenTag;
}


/**
 * What happens to the comments inside a removed subtree.
 */
enum CommentPolicy
{
	case MoveToNextToken;
	case MoveToPreviousToken;
	case Drop;
}
