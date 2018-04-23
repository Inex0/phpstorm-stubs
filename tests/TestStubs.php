<?php

use PHPUnit\Framework\TestCase;

include "StubParser.php";
include __DIR__ . "/../vendor/autoload.php";

class ReflectionStubsSingleton
{
    private static $reflectionStubs = null;

    public static function getReflectionStubs(): stdClass
    {
        if (self::$reflectionStubs == null) {
            $json = file_get_contents(__DIR__ . "/stub.json");
            self::$reflectionStubs = json_decode($json);
        }
        return self::$reflectionStubs;
    }
}

class PhpStormStubsSingleton
{
    private static $phpstormStubs = null;

    public static function getPhpStormStubs(): stdClass
    {
        if (self::$phpstormStubs == null) {
            self::$phpstormStubs = getPhpStormStubs();
        }
        return self::$phpstormStubs;
    }
}

class MutedProblems
{
    /** @var stdClass */
    private $mutedProblems;

    public function __construct()
    {
        $json = file_get_contents(__DIR__ . "/mutedProblems.json");
        $this->mutedProblems = json_decode($json);
    }

    public function getMutedProblemsForConstant(string $constantName): array
    {
        foreach ($this->mutedProblems->constants as $constant) {
            if ($constant->name == $constantName) {
                return $constant->problems;
            }
        }
        return [];
    }

    public function getMutedProblemsForFunction(string $functionName): array
    {
        foreach ($this->mutedProblems->functions as $function) {
            if ($function->name == $functionName) {
                return $function->problems;
            }
        }
        return [];
    }

    public function getMutedProblemsForClass(string $className): array
    {
        foreach ($this->mutedProblems->classes as $class) {
            if ($class->name == $className) {
                if(!empty($class->problems)) {
                    return $class->problems;
                }
            }
        }
        return [];
    }

    public function getMutedProblemsForMethod(string $className, $methodName): array
    {
        foreach ($this->mutedProblems->classes as $class) {
            if ($class->name == $className) {
                foreach ($class->methods as $method) {
                    if ($method->name == $methodName) {
                        return $method->problems;
                    }
                }
            }
        }
        return [];
    }

    public function getMutedProblemsForClassConstants($className, $constantName)
    {
        foreach ($this->mutedProblems->classes as $class) {
            if ($class->name == $className) {
                foreach ($class->constants as $constant) {
                    if ($constant->name == $constantName) {
                        return $constant->problems;
                    }
                }
            }
        }
        return [];
    }
}

class TestStubs extends TestCase
{
    /** @var MutedProblems */
    private static $mutedProblems;

    public static function setUpBeforeClass()/* The :void return type declaration that should be here would cause a BC issue */
    {
        self::$mutedProblems = new MutedProblems();
    }


    public function constantProvider()
    {
        foreach (ReflectionStubsSingleton::getReflectionStubs()->constants as $constant) {
            yield "constant {$constant->name}" => [$constant];
        }
    }

    /**
     * @dataProvider constantProvider
     */
    public function testConstants($constant)
    {
        $constantName = $constant->name;
        $constantValue = $constant->value;
        $stubConstants = PhpStormStubsSingleton::getPhpStormStubs()->constants;
        if (!in_array("missing constant", self::$mutedProblems->getMutedProblemsForConstant($constantName))) {
            $this->assertArrayHasKey($constantName, $stubConstants, "Missing constant: const $constantName = $constantValue\n");
        }
    }


    public function functionProvider()
    {
        foreach (ReflectionStubsSingleton::getReflectionStubs()->functions as $function) {
            yield "function {$function->name}" => [$function];
        }
    }

    /**
     * @dataProvider functionProvider
     */
    function testFunctions($function)
    {
        $functionName = $function->name;
        $stubFunctions = PhpStormStubsSingleton::getPhpStormStubs()->functions;
        $params = $this->getParameterRepresentation($function);
        if (!in_array("missing function", self::$mutedProblems->getMutedProblemsForFunction($functionName))) {
            $this->assertArrayHasKey($functionName, $stubFunctions, "Missing function: function $functionName($params){}");
            $phpstormFunction = $stubFunctions[$functionName];
            if (!in_array("deprecated function", self::$mutedProblems->getMutedProblemsForFunction($functionName))) {
                $this->assertFalse($function->is_deprecated && $phpstormFunction->is_deprecated != true, "Function $functionName is not deprecated in stubs");
            }
            if (!in_array("parameter mismatch", self::$mutedProblems->getMutedProblemsForFunction($functionName))) {
                $this->assertSameSize($function->parameters, $phpstormFunction->parameters,
                    "Parameter number mismatch for function $functionName. Expected: " . $this->getParameterRepresentation($function));
            }
        }
    }


    public function classProvider()
    {
        foreach (ReflectionStubsSingleton::getReflectionStubs()->classes as $class) {
            //exclude classes from PHPReflectionParser
            if (substr($class->name, 0, 3) != "PHP") {
                yield "class {$class->name}" => [$class];
            }
        }
    }

    /**
     * @dataProvider classProvider
     */
    function testClasses($class)
    {
        $className = $class->name;
        $stubClasses = PhpStormStubsSingleton::getPhpStormStubs()->classes;
        if (!in_array("missing class", self::$mutedProblems->getMutedProblemsForClass($className))) {
            $this->assertArrayHasKey($className, $stubClasses, "Missing class $className: class $className {}");
            $stubClass = $stubClasses[$className];
            if (!in_array("wrong parent", self::$mutedProblems->getMutedProblemsForClass($className))) {
                $this->assertEquals($class->parentClass, $stubClass->parentClass, "Class $className should extend {$class->parentClass}");
            }
            foreach ($class->constants as $constant) {
                if (!in_array("missing constant", self::$mutedProblems->getMutedProblemsForClassConstants($className, $constant->name))) {
                    $this->assertArrayHasKey($constant->name, $stubClass->constants, "Missing constant $className::{$constant->name}");
                }
            }
            // @todo check interfaces
            // @todo check traits
            foreach ($class->methods as $method) {
                $params = $this->getParameterRepresentation($method);
                $methodName = $method->name;
                if (!in_array("missing method", self::$mutedProblems->getMutedProblemsForMethod($className, $methodName))) {
                    $this->assertArrayHasKey($methodName, $stubClass->methods, "Missing method $className::$methodName($params){}");
                }
                $stubMethod = $stubClass->methods[$methodName];
                if (!in_array("not final", self::$mutedProblems->getMutedProblemsForMethod($className, $methodName))) {
                    $this->assertEquals($method->is_final, $stubMethod->is_final, "Method $className::$methodName final modifier is incorrect");
                }
                if (!in_array("not static", self::$mutedProblems->getMutedProblemsForMethod($className, $methodName))) {
                    $this->assertEquals($method->is_static, $stubMethod->is_static, "Method $className::$methodName static modifier is incorrect");
                }
                if (!in_array("access modifiers", self::$mutedProblems->getMutedProblemsForMethod($className, $methodName))) {
                    $this->assertEquals($method->access, $stubMethod->access, "Method $className::$methodName access modifier is incorrect");
                }
                if (!in_array("parameter mismatch", self::$mutedProblems->getMutedProblemsForMethod($className, $methodName))) {
                    $this->assertSameSize($method->parameters, $stubMethod->parameters, "Parameter number mismatch for method $className::$methodName. Expected: " . $this->getParameterRepresentation($method));
                }
            }
        }
    }


    private function getParameterRepresentation($function)
    {
        $result = "";
        foreach ($function->parameters as $parameter) {
            if (!empty($parameter->type)) {
                $result .= $parameter->type . " ";
            }
            if ($parameter->is_passed_by_ref) {
                $result .= "&";
            }
            if ($parameter->is_vararg) {
                $result .= "...";
            }
            $result .= "$" . $parameter->name . ", ";
        }
        $result = rtrim($result, ", ");
        return $result;
    }
}