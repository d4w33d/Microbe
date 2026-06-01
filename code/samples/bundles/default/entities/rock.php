<?php

class Rock extends Microbe_Entity
{

    // ---{ Definition }--------------------------------------------------------

    public const CRUD_METHOD = 'db';
    public const TABLE_NAME  = 'rocks';

    protected array $fields = [
        [ 'name' => 'id',         'type' => Microbe_Entity::T_INT      ],
        [ 'name' => 'uid',        'type' => Microbe_Entity::T_STRING   ],
        [ 'name' => 'created_at', 'type' => Microbe_Entity::T_DATETIME ],
        [ 'name' => 'updated_at', 'type' => Microbe_Entity::T_DATETIME ],
        [ 'name' => 'nick_name',  'type' => Microbe_Entity::T_STRING   ],
        [ 'name' => 'size',       'type' => Microbe_Entity::T_STRING   ],
        [ 'name' => 'color',      'type' => Microbe_Entity::T_STRING   ],
    ];

    // ---{ Entity Methods }----------------------------------------------------

    public function getDisplayName(): string
    {
        return t('The {identity} Rock', [ 'identity' => implode(' ', array_filter([
            implode(' ' . t('and') . ' ', array_filter([ ucfirst($this->getSize() ?: ''), ucwords(str_replace('_', ' ', $this->getColor() ?: '')) ])),
            $this->getNickName(),
        ])) ]);
    }

}
