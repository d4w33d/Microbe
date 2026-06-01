<?php

route('/', function(): void
{
    if ($localeCode = get('lang')) {
        set_current_app_locale($localeCode);
        redirect('?/');
    }

    render('home', [ '_view' => 'home' ]);
});

route('/rocks', function(): void
{
    render('rocks', [
        '_view' => 'rocks',
        'rocks' => get_rocks(),
    ]);
});

route('/rocks/add', function(): void
{
    add_rock(
        get_random_rock_nick_name(),
        get_random_rock_size(),
        get_random_rock_color(),
    );

    redirect('/rocks');
});

route('/rocks/empty', function(): void
{
    delete_all_rocks();
    redirect('/rocks');
});

route('/rocks/<id>/delete', function(int $id): void
{
    if (!($rock = get_rock($id))) throw_404();
    $rock->delete();
    redirect('/rocks');
});
