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
 * Form of a name as written in the source.
 */
enum NameKind
{
	case Unqualified;
	case Qualified;
	case FullyQualified;
	case Relative;
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
