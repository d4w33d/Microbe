<?php

function get_random_rock_nick_name(): string
{
    $names = [ 'James', 'Michael', 'Robert', 'John', 'David', 'William', 'Richard', 'Joseph', 'Thomas' ];
    return $names[mt_rand(0, count($names) - 1)];
}

function get_rocks_sizes(): array { return [ 'huge', 'big', 'medium', 'small', 'tiny' ]; }
function is_allowed_rock_size(string $size): bool { return in_array($size, get_rocks_sizes()); }
function get_random_rock_size(): string { return ($sizes = get_rocks_sizes())[mt_rand(0, count($sizes) - 1)]; }

function get_rocks_colors(): array { return [ 'dark_grey', 'grey', 'light_grey' ]; }
function is_allowed_rock_color(string $color): bool { return in_array($color, get_rocks_colors()); }
function get_random_rock_color(): string { return ($colors = get_rocks_colors())[mt_rand(0, count($colors) - 1)]; }
