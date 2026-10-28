<?php

return [

    /*
    | Who may open the file manager. The ability is checked through Laravel's Gate for every page
    | and endpoint; define it in the application. Null lets every signed-in user in.
    */
    'ability' => 'filemanager.access',

    /*
    | The model that uploads files, creates folders and writes comments. Null uses the model of
    | the default auth provider. Its `name` attribute is shown as owner and comment author.
    */
    'user_model' => null,

    'table_prefix' => 'filemanager_',

    /*
    | Where files are stored. Every file gets a directory of its own, named by an md5 of the salt
    | and its id, so directories cannot be guessed. Never change the salt once files are stored.
    */
    'disk' => env('FILEMANAGER_DISK', 'local'),

    'hash_salt' => env('FILEMANAGER_HASH_SALT', env('MEDIA_HASH_SALT')),

    'routes' => [
        'prefix' => 'files',
        'name' => 'filemanager.',
        'middleware' => ['web', 'auth'],
    ],

    /*
    | The page hosting the file manager. The view receives `title`, `island`, `islandProps` and
    | `flush` (true: the window wants the full content area, without padding).
    */
    'page' => [
        'view' => 'filemanager::page',
        'title' => 'Files',
    ],

    /*
    | Text inside files is extracted after upload so search can find it: PDFs (poppler tools
    | pdftotext and pdftoppm), plain text, Word, Excel, PowerPoint and OpenDocument files. With
    | tesseract installed, scanned PDFs and images are read by text recognition in these languages.
    */
    'extract_text' => true,

    'ocr' => true,

    'ocr_languages' => 'eng',

    'max_upload_kilobytes' => 512000,

];
