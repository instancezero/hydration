<?php
declare(strict_types=1);

namespace Abivia\Hydration;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionType;
use Symfony\Component\Yaml\Yaml;
use function array_merge;
use function array_pop;
use function array_push;
use function in_array;
use function is_array;
use function is_object;
use function json_decode;
use function method_exists;

/**
 * Copy information from an object created from a JSON/YAML/etc. configuration file into
 * a new object of the host class, performing validation and transformation operations
 * while doing so.
 */
class Hydrator
{
    /**
     * Convenience constant for all methods that can be accessed.
     */
    public const ALL_CALLABLE_METHODS = ReflectionMethod::IS_PRIVATE
        | ReflectionMethod::IS_PROTECTED
        | ReflectionMethod::IS_PUBLIC;

    /**
     * Convenience constant for all properties that can be accessed.
     */
    public const ALL_NONSTATIC_PROPERTIES = ReflectionProperty::IS_PRIVATE
        | ReflectionProperty::IS_PROTECTED
        | ReflectionProperty::IS_PUBLIC;

    /**
     * @var Encoder|null An Encoder for the subject class.
     */
    private ?Encoder $encoder = null;

    /**
     * @var string[] Any errors generated by the last hydrate() call.
     */
    private array $errorLog = [];

    /**
     * @var array Options stack for recursive hydration calls.
     */
    private array $optionStack = [];

    /**
     * @var array The options passed to the last hydrate() call, merged with defaults.
     */
    private array $options = [];

    /**
     * @var array Reflection results, indexed by class.
     */
    protected static array $reflectionCache = [];

    /**
     * @var Property[] Property list indexed by source name.
     */
    protected array $sourceProperties = [];

    /**
     * @var string The name of the class we're hydrating.
     */
    protected string $subjectClass = '';

    /**
     * @var Property[] Property list indexed by target name.
     */
    protected array $targetProperties = [];

    /**
     * Add a list of properties to be hydrated.
     *
     * @param array $properties Elements are any of 'propertyName', ['sourceName', 'targetName']
     * or a Property object.
     * @param array $options Common attributes to apply to the new properties. Options are any
     * public method of the Property class, except __construct, as, assign, make, and reflects.
     * Use an array to pass multiple arguments.
     *
     * @return Hydrator
     *
     * @throws HydrationException
     */
    public function addProperties(array $properties, array $options = []): self
    {
        $this->checkSubject(false);
        foreach ($properties as $property) {
            if (!$property instanceof Property) {
                $property = Property::makeAs($property);
            }
            $property->set($options);
            $this->targetProperties[$property->target()] = $property;
        }

        return $this;
    }

    /**
     * Add a property to be hydrated.
     *
     * @param Property $property The property object to add.
     *
     * @return Hydrator
     *
     * @throws HydrationException
     */
    public function addProperty(Property $property): self
    {
        $this->checkSubject(false);
        $this->targetProperties[$property->target()] = $property;

        return $this;
    }

    /**
     * Assign a property.
     *
     * @param object $target The object being hydrated.
     * @param Property $property The mapped property name.
     * @param mixed $value The source data for hydration.
     * @param array $options Options.
     *
     * @return boolean
     *
     * @throws HydrationException
     */
    private function assign(
        object $target, Property $property, $value, array $options
    ): bool
    {
        $result = true;

        $targetProp = $property->target();
        $hydrate = $property->getHydrateMethod();
        if (
            isset($target->$targetProp)
            && is_object($target->$targetProp)
            && method_exists($target->$targetProp, $hydrate)
        ) {
            // The property is instantiated and has a hydration method,
            // pass the value along.
            $status = $target->$targetProp->$hydrate($value, $options);
            if ($status !== true) {
                $this->logError($status);
                $result = false;
            }
        } elseif (!$property->assign($target, $value, $options)) {
            $this->logError($property->getErrors());
            $result = false;
        }
        return $result;
    }

    /**
     * Bind an object instance or class name.
     *
     * @param class-string|object $subject Name or instance of the class to bind the hydrator to.
     * @param int $filter Filter for adding implicit properties. This is any combination of the
     * ReflectionProperty IS_* flags that apply to non-static properties.
     *
     * @return $this
     *
     * @throws ReflectionException
     * @throws HydrationException
     */
    public function bind($subject, int $filter = ReflectionProperty::IS_PUBLIC): self
    {
        // Mask to exclude static properties
        $filter &= self::ALL_NONSTATIC_PROPERTIES;

        $this->subjectClass = is_object($subject) ? get_class($subject) : $subject;

        // Load and cache reflection information for this class.
        self::fetchReflection($this->subjectClass);

        // Get all unfiltered properties for the target object
        foreach (
            self::$reflectionCache[$this->subjectClass]['properties']
            as $propName => $reflectProperty
        ) {
            // If the property hasn't been defined and isn't filtered
            // generate a default mapping.
            if (!isset($this->targetProperties[$propName])) {
                if ($reflectProperty->getModifiers() & $filter) {
                    $this->targetProperties[$propName] = Property::make($propName);
                } else {
                    continue;
                }
            }
            // See if we can establish a binding to classes that implement Hydratable.
            $forClass = self::reflectionType($reflectProperty);
            /** @var class-string $forClass */
            if (self::isHydratable($forClass)) {
                $this->targetProperties[$propName]->bind($forClass);
            }
            $this->targetProperties[$propName]->reflects($reflectProperty);
        }

        // Build the index by source.
        $this->sourceProperties = [];
        foreach ($this->targetProperties as $property) {
            $this->sourceProperties[$property->source()] = $property;
        }

        return $this;
    }

    /**
     * Ensure that all properties are members of the same class.
     *
     * @param Property[] $properties A list of properties to check.
     * @param class-string $toClass The name of the class the properties should belong to.
     *
     * @throws HydrationException
     * @throws ReflectionException
     */
    public static function checkBindings(array $properties, string $toClass): void
    {
        $reflectionProperties = self::fetchReflection($toClass);
        foreach ($properties as $property) {
            $target = $property->target();

            // if the target is defined by a setter/getter, skip it.
            if ($target[0] === '*') {
                continue;
            }
            if (!isset($reflectionProperties['properties'][$target])) {
                throw new HydrationException(
                    "Property \"$target\" is not defined in $toClass."
                );
            }
            $property->reflects($reflectionProperties['properties'][$property->target()]);
        }

    }

    /**
     * Check to see if we have (or don't have) a subject class defined.
     *
     * @param bool $present True if the subject class should be present.
     *
     * @throws HydrationException If the lass definition doesn't match expectations.
     */
    private function checkSubject(bool $present): void
    {
        if ($present && $this->subjectClass === '') {
            throw new HydrationException("Must bind to a class first.");
        }
        if (!$present && $this->subjectClass !== '') {
            throw new HydrationException("Must add properties before binding to a class.");
        }
    }

    /**
     * @param string|object $config
     * @param array $options
     * @return mixed
     * @throws HydrationException
     */
    public function decode($config, array $options)
    {
        if (($this->options['source'] ?? '') !== 'object') {
            if (!is_string($config)) {
                throw new HydrationException(
                    "Cannot decode" . gettype($config) . ". Not a string."
                );
            }
            $config = $this->parse($config);
        }

        return $config;
    }

    /**
     * Prepare an object for encoding.
     *
     * @param object $source An object to be prepared for encoding.
     * @param EncoderRule|array|null $rules Encoding rules to be applied to the object.
     *
     * @return mixed
     *
     * @throws HydrationException
     * @throws ReflectionException
     * @noinspection PhpReturnDocTypeMismatchInspection
     */
    public function encode(object $source, $rules = [])
    {
        $this->checkSubject(true);

        // Get encoding rules for all defined properties
        if (!isset($this->encoder)) {
            $this->encoder = new Encoder($this->targetProperties);
        }

        $result = $this->encoder->encode($source);

        if (!is_array($rules)) {
            $rules = [$rules];
        }

        $this->encoder->encodeProperty($result, $rules, $source);

        return $result;
    }

    /**
     * Load and cache reflection information for the named class.
     *
     * @param class-string $subjectClass the name of the class we're loading.
     *
     * @return array All relevant properties and methods of the class.
     *
     * @throws ReflectionException
     */
    public static function fetchReflection(string $subjectClass): array
    {
        if (!isset(self::$reflectionCache[$subjectClass])) {
            self::$reflectionCache[$subjectClass] = ['methods' => [], 'properties' => []];
            $reflect = new ReflectionClass($subjectClass);

            $reflectMethods = $reflect->getMethods(self::ALL_CALLABLE_METHODS);
            foreach ($reflectMethods as $rm) {
                // Index the methods by name
                self::$reflectionCache[$subjectClass]['methods'][$rm->getName()] = $rm;
            }

            $reflectProperties = $reflect->getProperties(self::ALL_NONSTATIC_PROPERTIES);
            foreach ($reflectProperties as $rp) {
                // Index the properties by name
                self::$reflectionCache[$subjectClass]['properties'][$rp->getName()] = $rp;
            }
        }
        return self::$reflectionCache[$subjectClass];
    }

    /**
     * Get the error log.
     *
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errorLog;
    }

    /**
     * Get the current option settings.
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Retrieve a Property by source name.
     *
     * @param string $name The name of the property in the source data.
     *
     * @return Property
     *
     * @throws HydrationException If the property is not defined.
     */
    public function getSource(string $name): Property
    {
        if (!$this->hasSource($name)) {
            throw new HydrationException("Source property $name has not been defined.");
        }

        return $this->sourceProperties[$name];
    }

    /**
     * Retrieve the Property list indexed by source name.
     *
     * @return Property[]
     */
    public function getSources(): array
    {
        return $this->sourceProperties;
    }

    /**
     * Retrieve a Property by target name.
     *
     * @param string $name The name of the property in the target object.
     * @return Property
     * @throws HydrationException If the property is not defined.
     */
    public function getTarget(string $name): Property
    {
        if (!$this->hasTarget($name)) {
            throw new HydrationException("Target property $name has not been defined.");
        }

        return $this->targetProperties[$name];
    }

    /**
     * Retrieve the Property list indexed by source name.
     *
     * @return Property[]
     */
    public function getTargets(): array
    {
        return $this->targetProperties;
    }

    /**
     * Check for a source property.
     *
     * @param string $name The name of the property in the source data.
     *
     * @return bool
     */
    public function hasSource(string $name): bool
    {
        return isset($this->sourceProperties[$name]);
    }

    /**
     * Check for a target property.
     *
     * @param string $name The name of the property in the target object.
     *
     * @return bool
     */
    public function hasTarget(string $name): bool
    {
        return isset($this->targetProperties[$name]);
    }

    /**
     * Load configuration data into an object structure.
     *
     * @param object $target The object to be hydrated.
     * @param string|object|array $config Configuration data either as a string or the result of
     *      decoding a configuration file.
     * @param array $options Options. {@see Hydratable::hydrate()}.
     *
     * @return bool True if all fields passed validation; if in strict mode
     *              true when all fields are defined class properties.
     *
     * @throws HydrationException
     */
    public function hydrate(object $target, $config, array $options = []): bool
    {
        $this->checkSubject(true);
        array_push($this->optionStack, $this->options);
        try {
            $result = true;

            // Reset the error log
            $this->errorLog = [];

            // Merge in default options not set, normalize
            $this->options = array_merge(['source' => 'json', 'strict' => true], $options);
            $this->options['source'] = strtolower($this->options['source']);

            // Ensure the config data is decoded
            $config = $this->decode($config, $this->options);

            // Add/overwrite the parent reference for use by child objects.
            $subOptions = array_merge($this->options, ['parent' => &$target]);

            // We should never see a scalar here.
            if (!is_array($config) && !is_object($config)) {
                throw new HydrationException(
                    "Unexpected scalar value hydrating $this->subjectClass."
                );
            }

            // Step through each of the properties
            $allProps = array_fill_keys(array_keys($this->sourceProperties), true);
            foreach ($config as $origProperty => $value) {

                // Ensure that the property exists.
                if (!isset($this->sourceProperties[$origProperty])) {
                    if ($this->options['strict']) {
                        $message = "Undefined property \"$origProperty\" in class $this->subjectClass.";
                        $this->logError($message);
                        throw new HydrationException($message);
                    }
                    continue;
                }
                unset($allProps[$origProperty]);

                // Assign the value to the property.
                $propertyMap = $this->sourceProperties[$origProperty];
                if (!$this->assign($target, $propertyMap, $value, $subOptions)) {
                    $result = false;
                }
            }

            // Check for required properties
            foreach (array_keys($allProps) as $property) {
                if (!$this->sourceProperties[$property]->getRequired()) {
                    unset($allProps[$property]);
                }
            }
            if (count($allProps)) {
                throw new HydrationException(
                    "No value provided for "
                    . implode(', ', array_keys($allProps))
                );
            }
        } finally {
            array_pop($this->optionStack);
        }

        return $result;
    }

    /**
     * See if a class implements Hydratable.
     *
     * @param class-string|null $forClass
     *
     * @return bool
     */
    public static function isHydratable(?string $forClass): bool
    {
        if ($forClass !== null) {
            try {
                // If the class is "array" or the like, this will throw an exception.
                $reflectClass = new ReflectionClass($forClass);
                if (in_array(Hydratable::class, $reflectClass->getInterfaceNames())) {
                    return true;
                }
            } catch (ReflectionException $ex) {
            }
        }

        return false;
    }

    /**
     * Log an error.
     *
     * @param string|array $message An error message to log or an array of messages to log
     *
     * @return void
     */
    protected function logError($message)
    {
        if (is_array($message)) {
            $this->errorLog = array_merge($this->errorLog, $message);
        } else {
            $this->errorLog[] = $message;
        }
    }

    /**
     * Create a Hydrator instance, optionally binding it to a subject class.
     *
     * @param object|class-string|null $subject an instance or class name to bind to.
     * @param int $filter Filter for property scopes to auto-bind, based on the
     * ReflectionProperty IS_* constants. Defaults to ReflectionProperty::IS_PUBLIC.
     *
     * @return Hydrator
     *
     * @throws HydrationException
     * @throws ReflectionException
     */
    public static function make(
        $subject = null,
        int $filter = ReflectionProperty::IS_PUBLIC
    ): self
    {
        $instance = new self();
        if ($subject !== null) {
            $instance->bind($subject, $filter);
        }

        return $instance;
    }

    /**
     * Parse a JSON/YAML input string into an object.
     *
     * @param string $config The configuration in JSON or YAML format.
     *
     * @return mixed The decoded data.
     *
     * @throws HydrationException If the data isn't valid for the selected method or if the
     * method is not recognized.
     */
    protected function parse(string $config)
    {
        $source = is_string($this->options['source']) ? $this->options['source'] : null;
        if ($source === null) {
            throw new HydrationException("Source is either not set or not a string.");
        }
        switch ($source) {
            case 'json':
            {
                $config = json_decode($config);
                break;
            }
            case 'yaml':
            {
                $config = Yaml::parse($config);
                break;
            }
            default:
            {
                throw new HydrationException("Unknown source data format: $source.");
            }
        }
        if ($config === null) {
            throw new HydrationException(
                "Error parsing source data as $source."
            );
        }
        $this->options['source'] = 'object';

        return $config;
    }

    /**
     * Get the type of the passed property.
     *
     * @param ReflectionProperty $reflectProperty
     *
     * @return string|class-string|null
     */
    public static function reflectionType(ReflectionProperty $reflectProperty): ?string
    {
        /**
         * @var ReflectionType|null (ReflectionNamedType in PHP 8.0+)
         */
        $reflectType = $reflectProperty->getType();
        if ($reflectType === null) {
            $forClass = null;
        } else {
            if (method_exists($reflectType, 'getName')) {
                $forClass = $reflectType->getName();
            } else {
                $forClass = (string)$reflectType;
            }
            if ($forClass[0] === '?') {
                $forClass = substr($forClass, 1);
            }
        }
        return $forClass;
    }

}
