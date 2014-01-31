<?php

/*
 * This file is part of the Behat.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\Behat\Context\Snippet\Generator;

use Behat\Behat\Context\Environment\ContextEnvironment;
use Behat\Behat\Context\Snippet\ContextSnippet;
use Behat\Behat\Definition\Pattern\PatternTransformer;
use Behat\Behat\Snippet\Generator\SnippetGenerator;
use Behat\Behat\Snippet\Snippet;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\StepNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Testwork\Environment\Environment;
use ReflectionClass;

/**
 * Context snippet generator.
 *
 * Generates snippets for a context class.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class ContextSnippetGenerator implements SnippetGenerator
{
    /**
     * @var string[string]
     */
    private static $proposedMethods = array();
    /**
     * @var string
     */
    private static $templateTemplate = <<<TPL
    /**
     * @%%s %s
     */
    public function %s(%s)
    {
        throw new PendingException();
    }
TPL;
    /**
     * @var PatternTransformer
     */
    private $patternTransformer;

    /**
     * Initializes snippet generator.
     *
     * @param PatternTransformer $patternTransformer
     */
    public function __construct(PatternTransformer $patternTransformer)
    {
        $this->patternTransformer = $patternTransformer;
    }

    /**
     * Checks if generator supports search query.
     *
     * @param Environment $environment
     * @param StepNode    $step
     *
     * @return Boolean
     */
    public function supportsEnvironmentAndStep(Environment $environment, StepNode $step)
    {
        if (!$environment instanceof ContextEnvironment) {
            return false;
        }

        if (!$environment->hasContexts()) {
            return false;
        }

        return null !== $this->getSnippetAcceptingContextClass($environment);
    }

    /**
     * Generates snippet from search.
     *
     * @param Environment $environment
     * @param StepNode    $step
     *
     * @return Snippet
     */
    public function generateSnippet(Environment $environment, StepNode $step)
    {
        $contextClass = $this->getSnippetAcceptingContextClass($environment);
        $patternType = $this->getPatternType($contextClass);
        $stepText = $step->getText();
        $pattern = $this->patternTransformer->generatePattern($patternType, $stepText);

        $methodName = $this->getMethodName($contextClass, $pattern->getCanonicalText(), $pattern->getPattern());
        $methodArguments = $this->getMethodArguments($step, $pattern->getPlaceholderCount());
        $snippetTemplate = $this->getSnippetTemplate($pattern->getPattern(), $methodName, $methodArguments);

        return new ContextSnippet($step, $snippetTemplate, $contextClass);
    }

    /**
     * Returns snippet-accepting context class.
     *
     * @param ContextEnvironment $environment
     *
     * @return null|string
     */
    protected function getSnippetAcceptingContextClass(ContextEnvironment $environment)
    {
        foreach ($environment->getContextClasses() as $class) {
            if (in_array(
                'Behat\Behat\Context\SnippetAcceptingContext',
                class_implements($class)
            )
            ) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Returns snippet-type that provided context class accepts.
     *
     * @param string $contextClass
     *
     * @return null|string
     */
    protected function getPatternType($contextClass)
    {
        $reflection = new ReflectionClass($contextClass);

        if (!$reflection->implementsInterface('Behat\Behat\Context\CustomSnippetAcceptingContext')) {
            return null;
        }

        return $reflection->getMethod('getAcceptedSnippetType')->invoke(null);
    }

    /**
     * Generates method name using step text and regex.
     *
     * @param string $contextClass
     * @param string $canonicalText
     * @param string $pattern
     *
     * @return string
     */
    protected function getMethodName($contextClass, $canonicalText, $pattern)
    {
        $methodName = $this->deduceMethodName($canonicalText);
        $methodName = $this->ensureMethodNameUniqueness($contextClass, $pattern, $methodName);

        return $methodName;
    }

    /**
     * Returns an array of method argument names from step and token count.
     *
     * @param StepNode $step
     * @param integer  $tokenCount
     *
     * @return string[]
     */
    protected function getMethodArguments(StepNode $step, $tokenCount)
    {
        $args = array();
        for ($i = 0; $i < $tokenCount; $i++) {
            $args[] = "\$arg" . ($i + 1);
        }

        foreach ($step->getArguments() as $argument) {
            if ($argument instanceof PyStringNode) {
                $args[] = "PyStringNode \$string";
            } elseif ($argument instanceof TableNode) {
                $args[] = "TableNode \$table";
            }
        }

        return $args;
    }

    /**
     * Generates snippet template using regex, method name and arguments.
     *
     * @param string   $pattern
     * @param string   $methodName
     * @param string[] $methodArguments
     *
     * @return string
     */
    protected function getSnippetTemplate($pattern, $methodName, array $methodArguments)
    {
        return sprintf(
            self::$templateTemplate,
            str_replace('%', '%%', $pattern),
            $methodName,
            implode(', ', $methodArguments)
        );
    }

    /**
     * Generates definition method name based on the step text.
     *
     * @param string $canonicalText
     *
     * @return string
     */
    private function deduceMethodName($canonicalText)
    {
        // check that method name is not empty
        if (0 !== strlen($canonicalText)) {
            $canonicalText[0] = strtolower($canonicalText[0]);

            return $canonicalText;
        }

        return 'stepDefinition1';
    }

    /**
     * Ensures uniqueness of the method name in the context.
     *
     * @param string $contextClass
     * @param string $stepPattern
     * @param string $methodName
     *
     * @return string
     */
    private function ensureMethodNameUniqueness($contextClass, $stepPattern, $methodName)
    {
        $reflection = new ReflectionClass($contextClass);

        // get method number from method name
        $methodNumber = 2;
        if (preg_match('/(\d+)$/', $methodName, $matches)) {
            $methodNumber = intval($matches[1]);
        }

        // check that proposed method name isn't already defined in the context
        while ($reflection->hasMethod($methodName)) {
            $methodName = preg_replace('/\d+$/', '', $methodName);
            $methodName .= $methodNumber++;
        }

        // check that proposed method name haven't been proposed earlier
        if (isset(self::$proposedMethods[$contextClass])) {
            foreach (self::$proposedMethods[$contextClass] as $proposedPattern => $proposedMethod) {
                if ($proposedPattern !== $stepPattern) {
                    while ($proposedMethod === $methodName) {
                        $methodName = preg_replace('/\d+$/', '', $methodName);
                        $methodName .= $methodNumber++;
                    }
                }
            }
        }

        return self::$proposedMethods[$contextClass][$stepPattern] = $methodName;
    }
}