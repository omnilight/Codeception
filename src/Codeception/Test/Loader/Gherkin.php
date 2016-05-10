<?php
namespace Codeception\Test\Loader;

use Behat\Gherkin\Filter\RoleFilter;
use Behat\Gherkin\Keywords\ArrayKeywords as GherkinKeywords;
use Behat\Gherkin\Lexer as GherkinLexer;
use Behat\Gherkin\Node\ExampleNode;
use Behat\Gherkin\Node\OutlineNode;
use Behat\Gherkin\Node\ScenarioInterface;
use Behat\Gherkin\Node\ScenarioNode;
use Behat\Gherkin\Parser as GherkinParser;
use Codeception\Configuration;
use Codeception\Exception\TestParseException;
use Codeception\Test\Gherkin as GherkinFormat;
use Codeception\Util\Annotation;

class Gherkin implements LoaderInterface
{
    protected static $defaultKeywords = [
        'feature'          => 'Feature',
        'background'       => 'Background',
        'scenario'         => 'Scenario',
        'scenario_outline' => 'Scenario Outline|Scenario Template',
        'examples'         => 'Examples|Scenarios',
        'given'            => 'Given',
        'when'             => 'When',
        'then'             => 'Then',
        'and'              => 'And',
        'but'              => 'But'
    ];

    protected static $defaultSettings = [
        'namespace' => '',
        'class_name' => '',
        'gherkin' => [
            'contexts' => [
                'default' => [],
                'tag' => [],
                'role' => []
            ]
        ]
    ];

    protected $tests = [];

    /**
     * @var GherkinParser
     */
    protected $parser;

    protected $settings = [];

    protected $steps = [];

    public function __construct($settings = [])
    {
        $this->settings = Configuration::mergeConfigs(self::$defaultSettings, $settings);
        if (!class_exists('Behat\Gherkin\Keywords\ArrayKeywords')) {
            throw new TestParseException('Feature file can only be parsed with Behat\Gherkin library. Please install `behat/gherkin` with Composer');
        }
        $keywords = new GherkinKeywords(['en' => static::$defaultKeywords]);
        $lexer = new GherkinLexer($keywords);
        $this->parser = new GherkinParser($lexer);
        $this->fetchGherkinSteps();
    }

    protected function fetchGherkinSteps()
    {
        $contexts = $this->settings['gherkin']['contexts'];

        foreach ($contexts['tag'] as $tag => $tagContexts) {
            $this->addSteps($tagContexts, "tag:$tag");
        }
        foreach ($contexts['role'] as $role => $roleContexts) {
            $this->addSteps($roleContexts, "role:$role");
        }

        if (empty($this->steps) and empty($contexts['default'])) { // if no context is set, actor to be a context
            $actorContext = $this->settings['namespace']
                ? rtrim($this->settings['namespace'] . '\\' . $this->settings['class_name'], '\\')
                : $this->settings['class_name'];
            if ($actorContext) {
                $contexts['default'][] = $actorContext;
            }
        }

        $this->addSteps($contexts['default']);
    }

    protected function addSteps(array $contexts, $group = 'default')
    {
        $this->steps[$group] = [];
        foreach ($contexts as $context) {
            $methods = get_class_methods($context);
            if (!$methods) {
                continue;
            }
            foreach ($methods as $method) {
                $annotation = Annotation::forMethod($context, $method);
                foreach (['Given', 'When', 'Then'] as $type) {
                    $pattern = $annotation->fetch($type);
                    if (!$pattern) {
                        continue;
                    }
                    $pattern = $this->makePlaceholderPattern($pattern);
                    $this->steps[$group][$pattern] = [$context, $method];
                }
            }
        }
    }

    public function makePlaceholderPattern($pattern)
    {
        if (isset($this->settings['describe_steps'])) {
            return $pattern;
        }
        if (strpos($pattern, '/') !== 0) {
            $pattern = preg_quote($pattern);

            $pattern = preg_replace('~(\w+)\/(\w+)~', '(?:$1|$2)', $pattern); // or
            $pattern = preg_replace('~\\\\\((\w)\\\\\)~', '$1?', $pattern); // (s)

            // params
            $pattern = preg_replace('~"?\\\:(\w+)"?~', '(?|\"([^"]*?)\"|(\d+))', $pattern);
            $pattern = "/^$pattern$/";
        }
        return $pattern;
    }

    public function loadTests($filename)
    {
        $featureNode = $this->parser->parse(file_get_contents($filename), $filename);

        if (!$featureNode) {
            return;
        }

        foreach ($featureNode->getScenarios() as $scenarioNode) {
            /** @var $scenarioNode ScenarioInterface  **/
            $steps = $this->steps['default']; // load default context

            foreach (array_merge($scenarioNode->getTags(), $featureNode->getTags()) as $tag) { // load tag contexts
                if (isset($this->steps["tag:$tag"])) {
                    $steps = array_merge($steps, $this->steps["tag:$tag"]);
                }
            }

            $roles = $this->settings['gherkin']['contexts']['role']; // load role contexts
            foreach ($roles as $role => $context) {
                $filter = new RoleFilter($role);
                if ($filter->isFeatureMatch($featureNode)) {
                    $steps = array_merge($steps, $this->steps["role:$role"]);
                    break;
                }
            }
            codecept_debug($steps);

            if ($scenarioNode instanceof OutlineNode) {
                foreach ($scenarioNode->getExamples() as $example) {
                    /** @var $example ExampleNode  **/
                    $params = implode(', ', $example->getTokens());
                    $exampleNode = new ScenarioNode($scenarioNode->getTitle() . " | $params", $scenarioNode->getTags(), $example->getSteps(), $example->getKeyword(), $example->getLine());
                    $this->tests[] = new GherkinFormat($featureNode, $exampleNode, $steps);
                }
                continue;
            }
            $this->tests[] = new GherkinFormat($featureNode, $scenarioNode, $steps);
        }
    }

    public function getTests()
    {
        return $this->tests;
    }

    public function getPattern()
    {
        return '~\.feature$~';
    }

    /**
     * @return array
     */
    public function getSteps()
    {
        return $this->steps;
    }
}
