<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * What `dresscode init` asks where the measuring left the choice to the user, with an example from the code and the
 * cost of every answer. The first answer is the recommended one, which never rewrites code beyond the budget.
 * @internal
 */
final readonly class Question
{
	public function __construct(
		/** the question, a sentence ending with a question mark */
		public string $text,
		/** @var non-empty-list<Answer>  the recommended one first */
		public array $answers,
		/** how to answer it without a terminal: the line of `dresscode.neon` or the command that does it */
		public string $instruction,
		/** a place of the code it is about */
		public ?Finding $example = null,
		/** the files the costliest answer rewrites, by which the questions are ordered */
		public int $files = 0,
	) {
	}


	public function getRecommended(): Answer
	{
		return $this->answers[0];
	}
}
