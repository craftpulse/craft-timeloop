<?php

namespace percipioglobal\timeloop\gql;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\Asset as AssetInterface;
use GraphQL\Type\Definition\Type;
use craft\helpers\Gql;

class TimeloopType extends \craft\gql\base\ObjectType
{
    public static function getName(): string
    {
        return 'Timeloop_dates';
    }

    /**
     * @return Type
     */
    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(static::class)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(static::class, new \GraphQL\Type\Definition\ObjectType([
            'name' => static::getName(),
            'fields' => [
                'dates' => [
                    'type' => Type::listOf(Type::string()),
                ],
            ]
        ]));
    }
}
