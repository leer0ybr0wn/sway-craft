<?php
/**
 * Site URL Rules
 *
 * You can define custom site URL rules here, which Craft will check in addition
 * to routes defined in Settings → Routes.
 *
 * Read about Craft’s routing behavior (and this file’s structure), here:
 * @link https://craftcms.com/docs/5.x/system/routing.html
 */

return [
    'default' => [
        'media' => ['template' => 'media/index'],
    ],

    // Route each brand site's homepage to its own template
    'swaySports' => [
        '' => ['template' => 'swaysports/index'],
    ],
    'clubRehab' => [
        '' => ['template' => 'clubrehab/index'],
    ],
    'buceNoire' => [
        '' => ['template' => 'bucenoire/index'],
    ],
    'redRoom' => [
        '' => ['template' => 'redroom/index'],
    ],
];
