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


/**
 * What a name refers to, by its place in the tree.
 * Stands last in the file: the composer classmap generator reads `case Namespace` as a namespace
 * declaration, so every class written below it would be mapped without the namespace.
 */
enum NameRole
{
	case ClassLike;
	case Function;
	case Constant;

	/** a use or namespace statement */
	case Namespace;
}
