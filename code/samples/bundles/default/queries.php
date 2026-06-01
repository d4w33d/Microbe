<?php

function add_rock(string $nickName, ?string $size = null, ?string $color = null): Rock
{
    $rock = (new Rock())
        ->setNickName($nickName)
        ->setSize($size)
        ->setColor($color)
        ->save();
    return $rock;
}

function get_rock(object | int | string $rock): ?Rock { return Rock::fetchOneMixed($rock); }
function get_rock_by_nick_name(string $nickName): ?Rock { return Rock::fetchOneByNickName($nickName); }
function get_rocks_by_size(string $size): array { return Rock::fetchAllBySize($size); }

function get_rocks(int $offset = 0, ?int $limit = null): array
{
    return Rock::fetchAll(function(Microbe_Query_Builder $qb) use ($offset, $limit): void
    {
        if ($limit) $qb->offset($offset)->limit($limit);
        $qb
            ->clearOrder()
            ->order('size', 'DESC')
            ->order('id', 'ASC');
    });
}

function count_rocks(): int { return Rock::countAll(); }

function delete_all_rocks(): void { db('rocks')->truncate(); }
