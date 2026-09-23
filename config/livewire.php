<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:12288', 'extensions:jpg,jpeg,png,gif,webp,pdf', 'mimes:jpg,jpeg,png,gif,webp,pdf'],
    ],
];
