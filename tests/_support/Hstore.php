<?php

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class Hstore extends Type
{
    const HSTORE = 'hstore';

    public function getSqlDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return 'hstore';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        if ($value === 'NULL' || $value === null) return null;

        // escape $ so variables aren't interpolated; doesn't work at write side for some reason
        $value = addcslashes($value, '$');
        @eval(sprintf("\$hstore = array(%s);", $value));

        if (!(isset($hstore) && is_array($hstore)))
        {
            throw new \Exception(sprintf("Could not parse hstore string '%s' to array.", $value));
        }

        return (object)$hstore;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (empty($value)) {
            return null;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Hstore value must be array or \stdClass.");
        }

        $insert_values = array();

        foreach($value as $k => $v)
        {
            if (is_null($v))
            {
                $insert_values[] = sprintf('"%s" => NULL', $k);
            }
            else
            {
                $insert_values[] = sprintf('"%s" => "%s"', addcslashes($k, '\"'), addcslashes($v, '\"'));
            }
        }

        $hstoreString = join(', ', $insert_values);
        return $hstoreString;
    }

    public function getName()
    {
        return self::HSTORE;
    }

}