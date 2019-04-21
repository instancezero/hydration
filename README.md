Abivia\Configurable
====

This trait facilitates the conversion of a set of data structures, typically generated by
decoding a JSON configuration file into a corresponding set of PHP classes. `Configurable`
has options for converting arrays of objects into associative arrays using a property of
the object. It can also validate inputs as well as guard and remap property names.

Basic example:
```php
class ConfigurableObject {
    use \Abivia\Configurable\Configurable;

    protected $userName;
    protected $password;

}
$json = '{"userName": "admin"; "password": "insecure"]';
$obj = new ConfigurableObject();
$obj -> configure(json_decode($json));
echo $obj -> userName . ', ' . $obj -> password;
```
Output:
admin, insecure

Pretty basic. But `Configurable` will also build PHP classes for nested objects, limit the
properties that can be set, and validate input data, just for a start.

Filtering
-----
The properties of the configured object can be explicitly permitted by overriding the
`configurePropertyAllow()` method, blocked by overriding the `configurePropertyBlock()`
method, or ignored via the `configurePropertyIgnore()` method. Ignore takes precedence, then
blocking, then allow. By default, attempts to set guarded properties
are ignored, but if the $strict parameter is either true or the name of a `Throwable`
class, then the configuration will terminate when the invalid parameter is encountered,
unless it has been explicitly ignored.

For a JSON input like this
```json
{
    "depth": 15,
    "length": 22,
    "primary: "Red",
    "width": 3
}
```

```php
    class SomeClass {
        use \Abivia\Configurable;

        protected $depth;
        protected $length;
        protected $width;

    }

    $obj = new SomeClass();
    // Returns true
    $obj -> configure($jsonDecoded);
    // Returns true
    $obj -> configure($jsonDecoded, false);
    // Returns false
    $obj -> configure($jsonDecoded, true);
    // Throws MyException
    $obj -> configure($jsonDecoded, 'MyException');
 ```

Initialization and Completion
---
In many cases it is required that the object be in a known state before configuration,
and that the configured object has acceptable values. `Configurable` supplies
`configureInitialize()` and `configureComplete()` for this purpose.

Validation
---
Scalar properties can be validated with `configureValidate()`. This method takes
the property name and the input value as arguments.
The value is passed by reference so that the validation can enforce specific formats
required by the object (for example by forcing case or cleaning out unwanted characters).

Property Name Mapping
---
Since JSON allows property names that are not valid PHP property names,
`configurePropertyMap()` can be used to convert illegal input properties to valid
PHP identifiers.

Contained Classes
---
`configureClassMap()` can be used to cause the instantiation and configuration
of classes that are contained within the top level class. These contained classes must provide
the `configure()` method, either of their own making or by also adopting the `Configurable`
trait.

`configureClassMap()` takes the name and value of a property as arguments and returns an
object that has a required `className` property and an optional `key` property.

### className
In the simplest case, `className` is the
name of a class that will be instantiated and configured. However, `className` may also be
a callable, which allows the creation of data-specific objects.

### key
The `key` property is optional. The way it is used differs slightly depending on whether
the contained object will be assigned to a scalar or an array.

For scalars,
 - if `key` is absent or an empty string, scalar contained classes
will simply be assigned to the named property, and
- if `key` is assigned, it must be a callable
in the form of an array (typically to a method in the current class instance). The
contained class is passed to this function as an argument.
This is useful when you want to assign the property via a setter.

For arrays,
 - if `key` is absent or blank, the contained object is appended to the array,
 - if `key` is a string, then it is taken as the name of a property in the contained
object, and this value is used as the key for an associative array, and
 - if `key` is a callable array, then it is called with the contained class as an
argument.

Examples
========
The unit tests contain a number of examples that should be illustrative. More detailed
examples with sample output can be found at
https://gitlab.com/abivia/configurable-examples