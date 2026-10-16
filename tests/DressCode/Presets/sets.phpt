<?php declare(strict_types=1);

/**
 * What a set may carry, so that using one never fights the standard the project chose, and what is left outside
 * every set and every standard, which is the list of decisions a project writes itself.
 */

use DressCode\Config;
use DressCode\Config\{ConfigResolver, PluginRegistry};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


const Standards = ['dresscode/perCs', 'dresscode/psr12', 'dresscode/nette', 'dresscode/symfony'];

$registry = new PluginRegistry;
$resolver = new ConfigResolver($registry);

/** @var array<string, list<string>>  preset => the decisions it writes, those of what it uses included, `keep` left out */
$written = [];
foreach ($registry->presets as $name => $file) {
	foreach ($resolver->resolve(new Config(use: [$name]), '8.6')->decisions as $path => $decision) {
		if ($decision->layers !== [] && !$decision->value->isKept()) {
			$written[$name][] = $path;
		}
	}
}


test('a set decides nothing about the layout', function () use ($written) {
	// the areas a standard owns: a decision of them in a set would fight the standard the project chose
	$layout = '~^(indentation|spacing|multiline|blankLines|naming|braces)\.~';
	foreach (array_diff_key($written, array_flip(Standards)) as $set => $paths) {
		Assert::same([], array_values(array_diff(preg_grep($layout, $paths), ['braces.bareStatementGroup'])), $set);
	}
});


test('a decision is made by a set or a standard, or written by the project on purpose', function () use ($written, $resolver) {
	// a decision outside every set and every standard is one a project writes itself, and the reason is here
	$onRequest = [
		'blankLines.afterStatement', // where a statement stands apart is the shape of a body, which no standard prescribes
		'braces.emptyBody.controlStructure', // an empty branch or loop, whose braces no standard writes
		'classes.internalClass.final', // what a project does with its own internals
		'classes.staticMethod', // how a class is built, which no set decides for it
		'controlFlow.trailingIf', // the shape of a function body, which no standard prescribes
		'controlFlow.elseifAfterReturn', // an `elseif` split into an `if` reads as another question, which a project chooses
		'controlFlow.ifElseReturning', // a ternary for two branches is a shape of the code, no construct a newer PHP gave
		'casing.hexDigit', // the case of a hexadecimal digit, which no standard of the core settles
		'naming.exceptionAffix', // how an exception is named, which projects settle each their own way
		'mistakes.neverReturnType.anonymousFunction', // the signature of a closure usually belongs to the call it is passed to
		'mistakes.readonlyProperty', // a guarantee the code takes on, which no older construct gave
		'functions.staticClosure', // what the code means when it binds a closure, which only the project knows
		'indentation.singleLevel', // a measure of the shape of a body, not its layout
		'multiline.items.coalesce', 'multiline.items.pipe', // operands of `??` and `|>`, which no standard spreads
		// an expression spanning lines after `=` or `=>`, which no standard keeps on the line of the operator
		'multiline.expressionStart.assignment', 'multiline.expressionStart.arrayItem', 'multiline.expressionStart.arrowFunction',
		// the `use` of a closure and a group of attributes, which no standard spreads
		'multiline.items.closureUse', 'multiline.edges.closureUse', 'multiline.items.attribute', 'multiline.edges.attribute',
		'phpdoc.types.nullable', 'phpdoc.types.unionOrder', 'types.unionOrder', // an order or a notation a project chooses
		'qualification.constant.otherNamespace', 'qualification.staticInFinalClass', 'qualification.function.otherNamespace', // how far a name is written out is the project's
		'types.declaration.constant', // a typed constant, which the code before PHP 8.3 cannot have
		'upgrading.classes.Override', // a guarantee the code takes on, which no older construct gave
		'upgrading.phpdoc.readonly', // an annotation may promise what the code does not keep, which only the project knows
		'upgrading.classes.SensitiveParameter', // what is sensitive the project knows best
		// the maps: a set lets the upgrading files of the packages in, a project writes its own
		'upgrading.libraries.attributeForAnnotation', 'upgrading.libraries.attributeForMember',
		'upgrading.libraries.forbiddenClasses', 'upgrading.libraries.forbiddenFunctions', 'upgrading.libraries.forbiddenMembers',
		'upgrading.libraries.replacedCalls', 'upgrading.libraries.replacedClasses', 'upgrading.libraries.replacedFunctions',
		'upgrading.libraries.replacedMembers',
	];

	$made = array_flip(array_merge(...array_values($written)));
	$left = [];
	foreach ($resolver->getCatalogue()->getDecisions() as $path => $decision) {
		if ($decision->isRequirement() && !isset($made[$path])) {
			$left[] = $path;
		}
	}

	sort($left);
	sort($onRequest);
	Assert::same($onRequest, $left, 'a decision nothing makes; put it in a set, a standard, or give a reason here');
});


test('every construct and function newer PHP brought or retired is in a set', function () use ($written, $resolver) {
	// a decision added there reaches every project using the set, so none may be left out by mistake
	$sets = array_merge(...array_values(array_diff_key($written, array_flip(Standards))));
	$upgrading = array_keys(array_filter(
		$resolver->getCatalogue()->getDecisions(),
		fn(DressCode\Decision $decision) => $decision->isRequirement()
			&& preg_match('~^upgrading\.(syntax|functions|php)\.~', $decision->path),
	));
	Assert::same([], array_values(array_diff($upgrading, $sets)));
});
