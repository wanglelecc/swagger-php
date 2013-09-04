<?php
namespace Swagger;

/**
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 *             Copyright [2012] [Robert Allen]
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * @category   Swagger
 * @package    Swagger
 */
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\TokenParser;
use Doctrine\Common\Annotations\DocParser;

/**
 * @category   Swagger
 * @package    Swagger
 */
class Parser
{
    /**
     * All detected resources
     * @var array|Annotations\Resource
     */
    protected $resources = array();

    /**
     * Current resource
     * @var Annotations\Resource
     */
    protected $resource = false;

    /**
     * All detected models
     * @var array|Annotations\Model
     */
    protected $models = array();

    /**
     * All detected annotation partials;
     * @var array|Annotations\AbstractAnnotation
     */
    protected $partials = array();

    /**
     * Current model
     * @var Annotations\Model
     */
    protected $model = false;

    /**
     * @var DocParser
     */
    private $docParser;

    /**
     * @var string
     */
    private $filename;

    public function __construct($filename)
    {
        $this->filename = $filename;
        $this->docParser = new DocParser();
        $this->docParser->setIgnoreNotImportedAnnotations(true);

        AnnotationRegistry::registerAutoloadNamespace(__NAMESPACE__, dirname(__DIR__));
        $this->parse();
    }

    /**
     * Get all valid resources.
     * @return array|Annotations\Resource
     */
    public function getResources()
    {
        Annotations\AbstractAnnotation::$context = $this->filename;
        $resources = array();
        foreach ($this->resources as $resource) {
            if ($resource->validate()) {
                $resources[] = $resource;
            }
        }
        $this->resources = $resources;
        Annotations\AbstractAnnotation::$context = 'unknown';
        return $resources;
    }

    /**
     * Get all valid models.
     * @return array|Annotations\Model
     */
    public function getModels()
    {
        Annotations\AbstractAnnotation::$context = $this->filename;
        $models = array();
        foreach ($this->models as $model) {
            if ($model->validate()) {
                $models[] = $model;
            }
        }
        $this->models = $models;
        Annotations\AbstractAnnotation::$context = 'unknown';
        return $models;
    }

    /**
     * Get all annotation partials.
     * @return  array|Annotations\AbstractAnnotation
     */
    public function getPartials()
    {
        return $this->partials;
    }


    /**
     * Extract and process all doc-comments.
     */
    protected function parse()
    {
        $tokenParser = new TokenParser(file_get_contents($this->filename));
        $token = $tokenParser->next(false);
        $namespace = '';
        $class = false;

        $imports = array(
            'swg' => 'Swagger\Annotations' // Use @SWG\* for swagger annotations (unless overwrittemn by a use statement)
        );
        $this->docParser->setImports($imports);
        $uses = array();
        $docComment = false;
        while ($token != null) {
            $token = $tokenParser->next(false);
            if (is_array($token) === false) { // Ignore tokens like "{", "}", etc
                continue;
            }
            if ($token[0] === T_DOC_COMMENT) {
                $location = $this->filename.' on line '.$token[2];
                Annotations\AbstractAnnotation::$context = $location;
                if ($docComment) { // 2 Doc-comments in succession?
                    $this->parseDocComment($docComment);
                }
                $docComment = $token[1];
                continue;
            }
            if ($token[0] === T_ABSTRACT) {
                $token = $tokenParser->next(false); // Skip "abstract" keyword
            }
            if ($token[0] === T_CLASS) { // Doc-comment before a class?
                $token = $tokenParser->next();
                $class = $namespace ? $namespace.'\\'.$token[1] : $token[1];
                // @todo detect end-of-class and reset $class
                if ($docComment) {
                    $extends = null;
                    $token = $tokenParser->next(false);
                    if ($token[0] === T_EXTENDS) {
                        $extends = $this->prefixNamespace($namespace, $tokenParser->parseClass(), $uses);
                    }
                    Annotations\AbstractAnnotation::$context = $class.' in '.$location;
                    $this->parseClass($class, $extends, $docComment);
                    $docComment = false;
                    continue;
                }
            }
            if ($docComment) {
                if ($token[0] == T_STATIC) {
                    $token = $tokenParser->next(false);
                    if ($token[0] === T_VARIABLE) { // static property
                        Annotations\AbstractAnnotation::$context = $class.'::'.$token[1].' in '.$location;
                        $this->parsePropery(substr($token[1], 1), $docComment);
                        $docComment = false;
                        continue;
                    }
                }
                if (in_array($token[0], array(T_PRIVATE, T_PROTECTED, T_PUBLIC, T_VAR))) { // Scope
                    $token = $tokenParser->next(false);
                    if ($token[0] == T_STATIC) {
                        $token = $tokenParser->next(false);
                    }
                    if ($token[0] === T_VARIABLE) { // instance property
                        Annotations\AbstractAnnotation::$context = $class.'->'.substr($token[1], 1).' in '.$location;
                        $this->parsePropery(substr($token[1], 1), $docComment);
                        $docComment = false;
                    } elseif ($token[0] === T_FUNCTION) {
                        $token = $tokenParser->next(false);
                        if ($token[0] === T_STRING) {
                            Annotations\AbstractAnnotation::$context = $class.'->'.$token[1].'(...)'.' in '.$location;
                            $this->parseMethod($token[1], $docComment);
                            $docComment = false;
                        }
                    }
                    continue;
                } elseif ($token[0] === T_FUNCTION) {
                    $token = $tokenParser->next(false);
                    if ($token[0] === T_STRING) {
                        Annotations\AbstractAnnotation::$context = $class.'->'.$token[1].'(...)'.' in '.$location;
                        $this->parseMethod($token[1], $docComment);
                        $docComment = false;
                    }
                }
                if (in_array($token[0], array(T_NAMESPACE, T_USE)) === false) { // Skip "use" & "namespace" to prevent "never imported" warnings)
                    // Not a doc-comment for a class, property or method?
                    $this->parseDocComment($docComment);
                    $docComment = false;
                }
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = $tokenParser->parseNamespace();
                continue;
            }
            if ($token[0] === T_USE) {
                $nsLength = strlen(__NAMESPACE__);
                foreach ($tokenParser->parseUseStatement() as $alias => $target) {
                    $uses[$alias] = $target;
                    if ($target[0] === '\\') {
                        $uses[$alias] = substr($target, 1);
                    }
                    if ($target[0] === '\\' && substr($target, 1, $nsLength + 1) === __NAMESPACE__.'\\') {
                        $imports[$alias] = substr($target, 1);
                    } elseif (substr($target, 0, $nsLength + 1) === __NAMESPACE__.'\\') {
                        $imports[$alias] = $target;
                    }
                }
                $this->docParser->setImports($imports);
                continue;
            }
        }
        if ($docComment) { // File ends with a T_DOC_COMMENT
            $this->parseDocComment($docComment);
        }
        Annotations\AbstractAnnotation::$context = 'unknown';
    }

    /**
     *
     * @param string $docComment
     * @return array|AbstractAnnotation
     */
    protected function parseDocComment($docComment)
    {
        try {
            $annotations = $this->docParser->parse($docComment, Annotations\AbstractAnnotation::$context);
        } catch (\Exception $e) {
            Logger::warning($e);
            return array();
        }
        foreach ($annotations as $annotation) {
            if ($annotation instanceof Annotations\Partial) {
                Logger::notice('Unexpected "'.get_class($annotation).'", @SWG\Partial is a pointer to a partial and should inside another annotation in '.Annotations\AbstractAnnotation::$context);
            } elseif ($annotation->_partialId !== null) {
                if (isset($this->partials[$annotation->_partialId])) {
                    Logger::notice('partial="'.$annotation->_partialId.'" is not unique. another was found in '.Annotations\AbstractAnnotation::$context);
                }
                $this->partials[$annotation->_partialId] = $annotation;
            } elseif ($annotation instanceof Annotations\Resource) {
                $this->resource = $annotation;
                $this->resources[] = $this->resource;
            } elseif ($annotation instanceof Annotations\Model) {
                $this->model = $annotation;
                $this->models[] = $this->model;
            } elseif ($annotation instanceof Annotations\Api) {
                if ($this->resource) {
                    $this->resource->apis[] = $annotation;
                } else {
                    Logger::notice('Unexpected "'.get_class($annotation).'", should be inside or after a "Resource" declaration in '.Annotations\AbstractAnnotation::$context);
                }
            } elseif ($annotation instanceof Annotations\Property) {
                if ($this->model) {
                    $this->model->properties[] = $annotation;
                } else {
                    Logger::notice('Unexpected "'.get_class($annotation).'", should be inside or after a "Model" declaration in '.Annotations\AbstractAnnotation::$context);
                }
            } elseif ($annotation instanceof Annotations\AbstractAnnotation) { // A Swagger notation?
                Logger::notice('Unexpected "'.get_class($annotation).'", Expecting a "Resource", "Model" or partial declaration in '.Annotations\AbstractAnnotation::$context);
            }
        }
        return $annotations;
    }

    /**
     * @param string $class
     * @param string $extends
     * @param string $docComment
     * @return array|AbstractAnnotation
     */
    protected function parseClass($class, $extends, $docComment)
    {
        $annotations = $this->parseDocComment($docComment);
        foreach ($annotations as $annotation) {
            if ($annotation instanceof Annotations\Resource) {
                // Resource
                if ($annotation->resourcePath === null) { // No resourcePath give?
                    // Assume Classname (without Controller suffix) matches the base route.
                    $annotation->resourcePath = '/'.lcfirst(basename(str_replace('\\', '/', $class)));
                    $annotation->resourcePath = preg_replace('/Controller$/i', '', $annotation->resourcePath);
                }
            } elseif ($annotation instanceof Annotations\Model) {
                // Model
                $annotation->phpClass = $class;
                if ($annotation->id === null) {
                    $annotation->id = basename(str_replace('\\', '/', $class));
                }
                $annotation->phpExtends = $extends;
            }
        }
        return $annotations;
    }

    /**
     * @param string $method
     * @param string $docComment
     * @return array|AbstractAnnotation
     */
    protected function parseMethod($method, $docComment)
    {
        $annotations = $this->parseDocComment($docComment);
        foreach ($annotations as $annotation) {
            if ($annotation instanceof Annotations\Api) {
                if ($annotation->path === null && $this->resource && $this->resource->resourcePath) { // No path given?
                    // Assume method (without Action suffix) on top the resourcePath
                    $annotation->path = $this->resource->resourcePath.'/'.preg_replace('/Action$/i', '', $method);
                }
                foreach ($annotation->operations as $operation) {
                    if ($operation->nickname === null) {
                        $operation->nickname = $method;
                    }
                }
            }
        }
        return $annotations;
    }

    /**
     * @param string $property  Name of the property
     * @param string $docComment  The doc-comment above the property
     * @return array|AbstractAnnotation
     */
    protected function parsePropery($property, $docComment)
    {
        $annotations = $this->parseDocComment($docComment);
        foreach ($annotations as $annotation) {
            if ($annotation instanceof Annotations\Property) {
                if ($annotation->name === null) {
                    $annotation->name = $property;
                }
                if ($annotation->type === null) {
                    if (preg_match('/@var\s+(\w+)/i', $docComment, $matches)) {
                        $type = (string) array_pop($matches);
                        $map = array(
                            'array' => 'Array',
                            'byte' => 'byte',
                            'boolean' => 'boolean',
                            'bool' => 'boolean',
                            'int' => 'int',
                            'integer' => 'int',
                            'long' => 'long',
                            'float' => 'float',
                            'double' => 'double',
                            'string' => 'string',
                            'date' => 'Date',
                            'datetime' => 'Date',
                            '\\datetime' => 'Date',
                            'list' => 'List',
                            'set' => 'Set',
                        );
                        if (array_key_exists(strtolower($type), $map)) {
                            $type = $map[strtolower($type)];
                        }
                        $annotation->type = $type;
                    }
                }
                // @todo Extract description
            }
        }
        return $annotations;
    }

    /**
     * Resolve the full classname.
     *
     * @param string $namespace  Active namespace
     * @param string $class  The class name
     * @param array $uses  Active USE statements.
     * @return string
     */
    private function prefixNamespace($namespace, $class, $uses = array())
    {
        $pos = strpos($class, '\\');
        if ($pos !== false) {
            if ($pos === 0) {
                // Fully qualified name (\Foo\Bar)
                return substr($class, 1);
            }
            // Qualified name (Foo\Bar)
            foreach ($uses as $alias => $aliasedNamespace) {
                $alias .= '\\';
                if (strtolower(substr($class, 0, strlen($alias))) === $alias) {
                    // Aliased namespace (use \Long\Namespace as Foo)
                    return $aliasedNamespace.substr($class, strlen($alias) - 1);
                }
            }
        } else {
            // Unqualified name (Foo)
            $alias = strtolower($class);
            if (isset($uses[$alias])) { // Is an alias?
                return $uses[$alias];
            }
        }
        if ($namespace == '') {
            return $class;
        }
        return $namespace.'\\'.$class;
    }
}
