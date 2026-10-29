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

    /*
    | The attribute of the user model holding a profile picture URL, shown in the owner and author
    | chips. Null shows the initial of the name instead.
    */
    'user_avatar_attribute' => null,

    'table_prefix' => 'filemanager_',

    /*
    | The drives shown in the folder tree, in this order; the first is opened by default. Each is a
    | Laravel disk of its own and may ask for an extra Gate ability on top of `ability` to be seen,
    | and for `write_ability` to be changed — without it the drive is read-only for that user. The package
    | brings a private local disk `filemanager` (storage/app/filemanager) unless the application
    | defines one with that name. Helper data (thumbnails, extracted text) is kept on the same disk
    | in a hidden `.filemanager` directory at its root.
    */
    'volumes' => [
        'files' => ['name' => 'Files', 'disk' => env('FILEMANAGER_DISK', 'filemanager'), 'ability' => null, 'write_ability' => null],
    ],

    /*
    | Copying and moving between drives. Null lets everyone who may write to both drives do it.
    */
    'transfer_ability' => null,

    /*
    | Every file gets a directory of its own, named by an md5 of the salt and its id, so directories
    | cannot be guessed. Never change the salt once files are stored.
    */
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
