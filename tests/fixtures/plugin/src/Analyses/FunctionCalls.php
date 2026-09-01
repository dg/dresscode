<?php declare(strict_types=1);

namespace Acme\DressCode\Analyses;

use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\NameNode;


/**
 * Calls of global functions in the file by their lowercased name.
 */
final class FunctionCalls
{
	/** @var array<string, list<FunctionCallNode>> */
	private array $calls = [];


	public function __construct(FileNode $file)
	{
		$resolver = new NameResolver($file);
		foreach ($file->getDescendants(FunctionCallNode::class) as $call) {
			if ($call->name instanceof NameNode && $resolver->isGlobalFunctionCall($call)) {
				$this->calls[strtolower($resolver->resolveFunction($call->name))][] = $call;
			}
		}
	}


	/** @return list<FunctionCallNode> */
	public function getCallsOf(string $function): array
	{
		return $this->calls[strtolower($function)] ?? [];
	}
}
