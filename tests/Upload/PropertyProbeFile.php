<?php

namespace GravityPdf\Upload;

/**
 * A `File` subclass that reads and writes a property chosen at run time
 *
 * `$errors` and `$constructorErrors` were `protected` until 4.0.0, and the two lists that
 * replaced them are `private`. Naming the property indirectly is how a test reaches
 * `File::__get()` and `File::__set()` from a subclass's own scope, where a literal
 * `$this->errors` would be the same access under four copies of these methods.
 *
 * A test picks one by name, so each takes the property first and ignores what it does not use.
 */
class PropertyProbeFile extends File
{
    /** @param mixed $value */
    public function assign(string $property, $value): void
    {
        $this->$property = $value;
    }

    /** @param mixed $value */
    public function append(string $property, $value): void
    {
        $this->{$property}[] = $value;
    }

    /** @return mixed */
    public function read(string $property)
    {
        return $this->$property;
    }

    public function isEmpty(string $property): bool
    {
        return empty($this->$property);
    }
}
