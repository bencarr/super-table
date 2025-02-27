<?php
namespace verbb\supertable\gql\types\input;

use verbb\supertable\fields\SuperTableField;

use Craft;
use craft\base\Field;
use craft\gql\GqlEntityRegistry;
use craft\gql\types\QueryArgument;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

class SuperTableBlock extends InputObjectType
{
    /**
     * Create the type for a super table field.
     *
     * @param $context
     * @return bool|mixed
     */
    public static function getType(SuperTableField $context)
    {
        /** @var SuperTableField $context */
        $typeName = $context->handle . '_SuperTableInput';

        if ($inputType = GqlEntityRegistry::getEntity($typeName)) {
            return $inputType;
        }

        // Array of block types.
        $blockTypes = $context->getBlockTypes();
        $blockInputTypes = [];

        // For all the blocktypes
        foreach ($blockTypes as $blockType) {
            $fields = $blockType->getFields();
            $blockTypeFields = [
                'id' => [
                    'name' => 'id',
                    'type' => Type::id(),
                ]
            ];

            // Get the field input types
            foreach ($fields as $field) {
                /** @var Field $field */
                $blockTypeFields[$field->handle] = $field->getContentGqlMutationArgumentType();
            }

            $blockTypeGqlName = $context->handle . '_SuperTableBlockInput';
            $blockInputTypes[$blockType->id] = [
                'name' => $blockType->id,
                'type' => GqlEntityRegistry::createEntity($blockTypeGqlName, new InputObjectType([
                    'name' => $blockTypeGqlName,
                    'fields' => $blockTypeFields
                ]))
            ];
        }

        // All the different field block types now get wrapped in a container input.
        // If two different block types are passed, the selected block type to parse is undefined.
        $blockTypeContainerName = $context->handle . '_SuperTableBlockContainerInput';
        $blockContainerInputType = GqlEntityRegistry::createEntity($blockTypeContainerName, new InputObjectType([
            'name' => $blockTypeContainerName,
            'fields' => function() use ($blockInputTypes) {
                return $blockInputTypes;
            }
        ]));

        $inputType = GqlEntityRegistry::createEntity($typeName, new InputObjectType([
            'name' => $typeName,
            'fields' => function() use ($blockContainerInputType) {
                return [
                    'sortOrder' => [
                        'name' => 'sortOrder',
                        'type' => Type::listOf(QueryArgument::getType()),
                    ],
                    'blocks' => [
                        'name' => 'blocks',
                        'type' => Type::listOf($blockContainerInputType),
                    ]
                ];
            },
            'normalizeValue' => [self::class, 'normalizeValue']
        ]));

        return $inputType;
    }

    public static function normalizeValue($value)
    {
        $preparedBlocks = [];
        $blockCounter = 1;
        $missingId = false;

        if (!empty($value['blocks'])) {
            foreach ($value['blocks'] as $block) {
                if (!empty($block)) {
                    $type = array_key_first($block);
                    $block = reset($block);
                    $missingId = $missingId || empty($block['id']);
                    $blockId = !empty($block['id']) ? $block['id'] : 'new:' . ($blockCounter++);

                    unset($block['id']);

                    $preparedBlocks[$blockId] = [
                        'type' => $type,
                        'fields' => $block
                    ];
                }
            }

            if ($missingId) {
                Craft::$app->getDeprecator()->log('SuperTableInput::normalizeValue()', 'The `id` field will be required when mutating Super Table fields as of Craft 4.0.');
            }

            $value['blocks'] = $preparedBlocks;
        }

        return $value;
    }
}
