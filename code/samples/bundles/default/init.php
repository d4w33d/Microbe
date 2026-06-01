<?php

register_custom_error_handler(404, function(?string $msg): void
{
    render('system/404', [ 'msg' => $msg ]);
});

register_custom_error_handler(500, function(?object $error): void
{
    render('system/500', [ 'error' => $error ]);
}, environments: [ 'staging', 'prod' ]);

listen('before_first_render', function(): void
{
    set_template_vars([
        '_view' => null,
    ]);
});
