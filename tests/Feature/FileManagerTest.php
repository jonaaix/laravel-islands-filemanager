<?php

declare(strict_types=1);

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function uploadDocument(?Folder $folder, string $name = 'invoice.pdf'): File
{
    test()->postJson(route('filemanager.files.store'), [
        'folder_id' => $folder === null ? null : (string) $folder->id,
        'files' => [UploadedFile::fake()->create($name, 120, 'application/pdf')],
    ])->assertOk();

    return File::query()->latest('id')->firstOrFail();
}

test('users without access cannot open or change anything', function () {
    $folder = Folder::query()->create(['name' => 'Invoices']);
    $this->actingAs($this->member(['may_use_files' => false]));

    $this->get(route('filemanager.index'))->assertForbidden();
    $this->getJson(route('filemanager.contents'))->assertForbidden();
    $this->deleteJson(route('filemanager.items.destroy'), ['folders' => [$folder->id]])->assertForbidden();
});

test('a folder lists its subfolders and files with the path leading to it', function () {
    $this->actingAs($user = $this->member());
    $suppliers = Folder::query()->create(['name' => 'Suppliers']);
    $rundong = Folder::query()->create(['name' => 'Rundong', 'parent_id' => $suppliers->id]);
    Folder::query()->create(['name' => 'Contracts', 'parent_id' => $rundong->id]);
    uploadDocument($rundong, 'price-list.pdf');

    $this->getJson(route('filemanager.contents', ['folder' => $rundong->id]))
        ->assertOk()
        ->assertJsonPath('data.path.0.name', 'Suppliers')
        ->assertJsonPath('data.path.1.name', 'Rundong')
        ->assertJsonPath('data.folders.0.name', 'Contracts')
        ->assertJsonPath('data.files.0.name', 'price-list.pdf')
        ->assertJsonPath('data.files.0.owner', $user->name)
        ->assertJsonPath('data.view.mode', 'list')
        ->assertJsonPath('data.view.sortBy', 'added')
        ->assertJsonPath('data.view.sortDirection', 'desc');

    $this->get(route('filemanager.index', ['folder' => $rundong->id]))->assertOk();
});

test('folder names are unique within their parent and new folders get a free name', function () {
    $this->actingAs($this->member());
    $this->postJson(route('filemanager.folders.store'), ['name' => 'Invoices'])->assertOk();

    $this->postJson(route('filemanager.folders.store'), ['name' => 'Invoices'])->assertJsonValidationErrors('name');
    $this->postJson(route('filemanager.folders.store'))->assertJsonPath('data.folder.name', 'New folder');
    $this->postJson(route('filemanager.folders.store'))->assertJsonPath('data.folder.name', 'New folder 2');
});

test('several items move together, but a folder never into itself or one of its subfolders', function () {
    $this->actingAs($this->member());
    $parent = Folder::query()->create(['name' => 'Parent']);
    $child = Folder::query()->create(['name' => 'Child', 'parent_id' => $parent->id]);
    $other = Folder::query()->create(['name' => 'Other']);

    $file = uploadDocument(null);

    $this->putJson(route('filemanager.items.move'), ['folders' => [$parent->id], 'target_id' => $child->id])->assertJsonValidationErrors('target_id');
    $this->putJson(route('filemanager.items.move'), ['folders' => [$parent->id], 'target_id' => $parent->id])->assertJsonValidationErrors('target_id');
    $this->putJson(route('filemanager.items.move'), ['folders' => [$parent->id], 'files' => [$file->id], 'target_id' => $other->id])->assertOk();

    expect($parent->refresh()->parent_id)->toBe($other->id)
        ->and($file->refresh()->folder_id)->toBe($other->id);
});

test('deleting a folder removes everything inside it including stored files', function () {
    $this->actingAs($this->member());
    $parent = Folder::query()->create(['name' => 'Parent']);
    $child = Folder::query()->create(['name' => 'Child', 'parent_id' => $parent->id]);
    $file = uploadDocument($child);

    Storage::disk('filemanager')->assertExists($file->path);

    $this->deleteJson(route('filemanager.items.destroy'), ['folders' => [$parent->id, $child->id]])->assertOk();

    expect(Folder::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);
    Storage::disk('filemanager')->assertMissing($file->path);
});

test('a file keeps notes and a comment thread in which only the author deletes a comment', function () {
    $author = $this->member();
    $this->actingAs($author);
    $file = uploadDocument(null);

    $this->putJson(route('filemanager.files.update', $file), ['notes' => 'Framework contract 2026'])
        ->assertJsonPath('data.file.notes', 'Framework contract 2026');

    $this->postJson(route('filemanager.comments.store', $file), ['body' => 'Signed copy follows'])
        ->assertJsonPath('data.file.comments.0.author', $author->name)
        ->assertJsonPath('data.file.comments.0.canDelete', true);

    $comment = $file->comments()->sole();

    $this->actingAs($this->member());
    $this->deleteJson(route('filemanager.comments.destroy', $comment))->assertForbidden();
    $this->getJson(route('filemanager.files.show', $file))->assertJsonPath('data.file.comments.0.canDelete', false);
});

test('the view is remembered per folder and per user', function () {
    $user = $this->member();
    $this->actingAs($user);
    $folder = Folder::query()->create(['name' => 'Photos']);

    $this->putJson(route('filemanager.view'), ['folder_id' => $folder->id, 'mode' => 'grid', 'tile_size' => 240, 'sort_by' => 'size', 'sort_direction' => 'desc'])->assertOk();

    $this->getJson(route('filemanager.contents', ['folder' => $folder->id]))
        ->assertJsonPath('data.view.mode', 'grid')
        ->assertJsonPath('data.view.tileSize', 240)
        ->assertJsonPath('data.view.sortBy', 'size')
        ->assertJsonPath('data.view.sortDirection', 'desc');
    $this->getJson(route('filemanager.contents'))->assertJsonPath('data.view.mode', 'list');

    $this->actingAs($this->member());
    $this->getJson(route('filemanager.contents', ['folder' => $folder->id]))->assertJsonPath('data.view.mode', 'list');
});

test('uploaded images get a thumbnail', function () {
    $this->actingAs($this->member());

    $this->postJson(route('filemanager.files.store'), ['files' => [UploadedFile::fake()->image('machine.jpg', 1200, 800)]])->assertOk();

    $file = File::query()->sole();
    expect($file->thumbnail_path)->not->toBeNull();
    $this->get(route('filemanager.files.thumbnail', $file))->assertOk()->assertHeader('Content-Type', 'image/webp');
});

test('files are found in the global search by name and notes, with their folder as location', function () {
    $this->actingAs($this->member());
    $suppliers = Folder::query()->create(['name' => 'Suppliers']);
    $file = uploadDocument($suppliers, 'price-list.pdf');
    $file->update(['notes' => 'Rundong framework contract']);

    $this->getJson(route('search', ['q' => 'framework']))
        ->assertJsonPath('data.groups.0.key', 'files')
        ->assertJsonPath('data.groups.0.hits.0.title', 'price-list.pdf')
        ->assertJsonPath('data.groups.0.hits.0.subtitle', 'Files › Suppliers');

    $this->actingAs($this->member(['may_use_files' => false]));
    $this->getJson(route('search', ['q' => 'framework']))->assertJsonMissing(['key' => 'files']);
});

test('selected folders and files download as one ZIP keeping the folder structure', function () {
    $this->actingAs($this->member());
    $contracts = Folder::query()->create(['name' => 'Contracts']);
    Folder::query()->create(['name' => 'Signed', 'parent_id' => $contracts->id]);
    uploadDocument($contracts, 'frame.pdf');
    $loose = uploadDocument(null, 'frame.pdf');

    $response = $this->get(route('filemanager.items.zip', ['folders' => [$contracts->id], 'files' => [$loose->id]]))->assertOk();

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $entries = collect(range(0, $zip->numFiles - 1))->map(fn (int $index): string => $zip->getNameIndex($index))->sort()->values()->all();

    expect($entries)->toBe(['Contracts/', 'Contracts/Signed/', 'Contracts/frame.pdf', 'frame.pdf']);
});

test('the widths of the info and folder columns are remembered per user', function () {
    $this->actingAs($this->member());

    $this->putJson(route('filemanager.preferences'), ['info_width' => 420])->assertOk();
    $this->putJson(route('filemanager.preferences'), ['tree_width' => 300])->assertOk();
    $this->putJson(route('filemanager.preferences'), ['info_width' => 9999])->assertJsonValidationErrors('info_width');

    $this->get(route('filemanager.index'))->assertOk()
        ->assertSee('&quot;infoWidth&quot;:420', false)
        ->assertSee('&quot;treeWidth&quot;:300', false);
});

test('the folder search looks through all subfolders and notes and names where each hit lies', function () {
    $this->actingAs($this->member());
    $suppliers = Folder::query()->create(['name' => 'Suppliers']);
    $tkm = Folder::query()->create(['name' => 'TKM', 'parent_id' => $suppliers->id]);
    uploadDocument($tkm, 'Net price List.pdf');
    uploadDocument(null, 'framework.pdf')->update(['notes' => 'price agreement']);

    $hits = $this->getJson(route('filemanager.search', ['q' => 'price']))->assertOk()->json('data.files');

    expect(collect($hits)->pluck('path', 'name')->all())->toEqual(['Net price List.pdf' => ['Suppliers', 'TKM'], 'framework.pdf' => []]);

    $this->getJson(route('filemanager.search', ['q' => 'price', 'folder' => $suppliers->id]))
        ->assertJsonCount(1, 'data.files')
        ->assertJsonPath('data.files.0.name', 'Net price List.pdf');
});

test('text inside files is found in a group of its own, apart from name matches', function () {
    $this->actingAs($this->member());
    $byName = uploadDocument(null, 'cutter price list.txt');
    $byText = uploadDocument(null, 'offer.txt');
    $byText->content()->create(['text' => 'Offer for cutter blades, Müller Martini, net price 1.750 EUR']);
    $byName->content()->create(['text' => 'cutter']);

    $this->getJson(route('filemanager.search', ['q' => 'cutter']))
        ->assertJsonCount(1, 'data.files')
        ->assertJsonPath('data.files.0.name', 'cutter price list.txt')
        ->assertJsonCount(1, 'data.contentFiles')
        ->assertJsonPath('data.contentFiles.0.name', 'offer.txt')
        ->assertJsonPath('data.contentFiles.0.snippet', 'Offer for cutter blades, Müller Martini, net price 1.750 EUR');

    $this->getJson(route('search', ['q' => 'blades']))->assertJsonPath('data.groups.0.key', 'file-contents');
});

test('text is extracted from uploaded text files in the background', function () {
    $this->actingAs($this->member());

    $this->postJson(route('filemanager.files.store'), ['files' => [UploadedFile::fake()->createWithContent('notes.txt', "Spare parts\n  for Kolbus")]])->assertOk();

    expect(File::query()->sole()->content->text)->toBe('Spare parts for Kolbus');
});

test('several words are all found, also across the folder a file lies in', function () {
    $this->actingAs($this->member());
    $tkm = Folder::query()->create(['name' => 'TKM']);
    uploadDocument($tkm, 'Net price List.pdf');
    uploadDocument($tkm, 'Contract.pdf');
    uploadDocument(null, 'price overview.pdf');

    $this->getJson(route('search', ['q' => 'tkm price']))
        ->assertJsonPath('data.groups.0.key', 'files')
        ->assertJsonCount(1, 'data.groups.0.hits')
        ->assertJsonPath('data.groups.0.hits.0.title', 'Net price List.pdf');

    $this->getJson(route('filemanager.search', ['q' => 'price tkm']))
        ->assertJsonCount(1, 'data.files')
        ->assertJsonPath('data.files.0.name', 'Net price List.pdf');

    $this->getJson(route('filemanager.search', ['q' => 'tkm']))
        ->assertJsonCount(1, 'data.folders')
        ->assertJsonCount(0, 'data.files');
});

test('text is read from Word and Excel files', function () {
    $this->actingAs($this->member());

    $office = function (string $name, array $parts): UploadedFile {
        $path = sys_get_temp_dir().'/'.uniqid().'-'.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($parts as $entry => $xml) {
            $zip->addFromString($entry, $xml);
        }

        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    };

    $this->postJson(route('filemanager.files.store'), ['files' => [
        $office('offer.docx', ['word/document.xml' => '<w:document><w:p><w:t>Angebot Greiferwelle</w:t></w:p><w:p><w:t>Heidelberg &amp; Kolbus</w:t></w:p></w:document>']),
        $office('stock.xlsx', ['xl/sharedStrings.xml' => '<sst><si><t>Gummituch 1050</t></si></sst>', 'xl/worksheets/sheet1.xml' => '<worksheet><c><v>4711</v></c></worksheet>']),
    ]])->assertOk();

    expect(File::query()->where('name', 'offer.docx')->sole()->content->text)->toBe('Angebot Greiferwelle Heidelberg & Kolbus')
        ->and(File::query()->where('name', 'stock.xlsx')->sole()->content->text)->toBe('Gummituch 1050 4711');
});

test('scanned PDFs are read by text recognition', function () {
    if (! Process::run(['which', 'tesseract'])->successful()) {
        $this->markTestSkipped('tesseract is not installed.');
    }

    config(['filemanager.ocr_languages' => 'deu+eng']);
    $this->actingAs($this->member());

    $scan = new Imagick;
    $scan->newImage(1400, 300, 'white');
    $text = new ImagickDraw;
    $text->setFontSize(72);
    $scan->annotateImage($text, 60, 180, 0, 'Lieferschein 88231');
    $path = sys_get_temp_dir().'/'.uniqid().'-scan.pdf';
    $scan->setImageFormat('pdf');
    $scan->writeImage($path);

    $this->postJson(route('filemanager.files.store'), ['files' => [new UploadedFile($path, 'scan.pdf', 'application/pdf', null, true)]])->assertOk();

    expect(File::query()->sole()->content->text)->toContain('88231');
});

test('the page hosts the file manager island, and without an ability every signed-in user gets in', function () {
    $this->actingAs($this->member());
    $this->get(route('filemanager.index'))->assertOk()->assertSee('FileManager');

    config(['filemanager.ability' => null]);
    $this->actingAs($this->member(['may_use_files' => false]));
    $this->get(route('filemanager.index'))->assertOk();
    $this->getJson(route('filemanager.contents'))->assertOk();
});

test('selected items move into a new folder created beside them', function () {
    $this->actingAs($this->member());
    $suppliers = Folder::query()->create(['name' => 'Suppliers']);
    $rundong = Folder::query()->create(['name' => 'Rundong', 'parent_id' => $suppliers->id]);
    $file = uploadDocument($suppliers, 'price-list.pdf');

    $response = $this->postJson(route('filemanager.folders.store-with-items'), ['folders' => [$rundong->id], 'files' => [$file->id], 'parent_id' => $suppliers->id])
        ->assertOk()
        ->assertJsonPath('data.folder.name', 'New folder with items');

    $created = Folder::query()->findOrFail($response->json('data.folder.id'));

    expect($created->parent_id)->toBe($suppliers->id)
        ->and($rundong->refresh()->parent_id)->toBe($created->id)
        ->and($file->refresh()->folder_id)->toBe($created->id);
});

test('owners carry their profile picture when the app names the attribute', function () {
    config(['filemanager.user_avatar_attribute' => 'email']);
    $this->actingAs($owner = $this->member());
    uploadDocument(null);

    $this->getJson(route('filemanager.contents'))
        ->assertJsonPath('data.files.0.owner', $owner->name)
        ->assertJsonPath('data.files.0.ownerAvatar', $owner->email);
});

test('a dropped folder keeps its structure and merges into folders of the same name', function () {
    $this->actingAs($this->member());
    $existing = Folder::query()->create(['name' => 'Supplier XY']);

    $this->postJson(route('filemanager.files.store'), [
        'files' => [
            UploadedFile::fake()->create('offer.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('drawing.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('loose.pdf', 10, 'application/pdf'),
        ],
        'paths' => ['Supplier XY', '../Supplier XY/Drawings', ''],
    ])->assertOk();

    $drawings = Folder::query()->where('name', 'Drawings')->sole();

    expect(Folder::query()->where('name', 'Supplier XY')->count())->toBe(1)
        ->and($drawings->parent_id)->toBe($existing->id)
        ->and(File::query()->where('name', 'offer.pdf')->sole()->folder_id)->toBe($existing->id)
        ->and(File::query()->where('name', 'drawing.pdf')->sole()->folder_id)->toBe($drawings->id)
        ->and(File::query()->where('name', 'loose.pdf')->sole()->folder_id)->toBeNull();
});

test('every volume has its own tree and contents, and a volume can ask for an ability of its own', function () {
    $this->actingAs($this->member());
    Folder::query()->create(['name' => 'Invoices']);
    Folder::query()->create(['name' => 'Old contracts', 'volume' => 'archive']);

    $this->getJson(route('filemanager.folders.index'))
        ->assertJsonPath('data.volumes.0.key', 'files')
        ->assertJsonPath('data.volumes.0.folders.0.name', 'Invoices')
        ->assertJsonPath('data.volumes.1.key', 'archive')
        ->assertJsonPath('data.volumes.1.folders.0.name', 'Old contracts');

    $this->getJson(route('filemanager.contents', ['volume' => 'archive']))
        ->assertJsonPath('data.volume', 'archive')
        ->assertJsonCount(1, 'data.folders')
        ->assertJsonPath('data.folders.0.name', 'Old contracts');

    $this->actingAs($this->member(['name' => 'Guest']));
    $this->getJson(route('filemanager.folders.index'))->assertJsonCount(1, 'data.volumes');
    $this->getJson(route('filemanager.contents', ['volume' => 'archive']))->assertNotFound();
});

test('helper data lives in the hidden folder of the volume and follows a file to another volume', function () {
    $this->actingAs($this->member());
    $this->postJson(route('filemanager.files.store'), ['files' => [UploadedFile::fake()->image('machine.jpg', 800, 600)]])->assertOk();
    $file = File::query()->sole();

    expect($file->thumbnail_path)->toStartWith('.laravel-islands-filemanager/thumbnails/');
    Storage::disk('filemanager')->assertExists([$file->path, $file->thumbnail_path]);

    $this->putJson(route('filemanager.items.move'), ['files' => [$file->id], 'target_volume' => 'archive'])->assertOk();

    $file->refresh();
    expect($file->getAttribute('volume'))->toBe('archive');
    Storage::disk('archive')->assertExists([$file->path, $file->thumbnail_path]);
    Storage::disk('filemanager')->assertMissing([$file->path, $file->thumbnail_path]);
    $this->get(route('filemanager.files.content', $file))->assertOk();
});

test('copying duplicates folders and files with notes, comments and helpers, also onto another volume', function () {
    $this->actingAs($author = $this->member());
    $contracts = Folder::query()->create(['name' => 'Contracts']);
    $this->postJson(route('filemanager.files.store'), ['folder_id' => (string) $contracts->id, 'files' => [UploadedFile::fake()->image('scan.jpg', 400, 300)]])->assertOk();
    $original = File::query()->sole();
    $original->update(['notes' => 'Signed']);
    $original->comments()->create(['user_id' => $author->id, 'body' => 'Checked']);

    $this->postJson(route('filemanager.items.copy'), ['folders' => [$contracts->id], 'target_volume' => 'files'])->assertOk();
    $this->postJson(route('filemanager.items.copy'), ['files' => [$original->id], 'target_volume' => 'archive'])->assertOk();

    expect(Folder::query()->where('volume', 'files')->pluck('name')->sort()->values()->all())->toBe(['Contracts', 'Contracts 2'])
        ->and(File::query()->count())->toBe(3);

    $archived = File::query()->where('volume', 'archive')->sole();
    expect($archived->notes)->toBe('Signed')
        ->and($archived->comments()->sole()->body)->toBe('Checked')
        ->and($archived->path)->not->toBe($original->path);
    Storage::disk('archive')->assertExists([$archived->path, $archived->thumbnail_path]);
    Storage::disk('filemanager')->assertExists($original->path);

    $this->postJson(route('filemanager.items.copy'), ['folders' => [$contracts->id], 'target_volume' => 'files', 'target_id' => $contracts->id])->assertJsonValidationErrors('target_id');
});

test('a volume can be read-only for some users and moving between volumes can be restricted', function () {
    $folder = Folder::query()->create(['name' => 'Invoices']);
    config(['filemanager.volumes.files.write_ability' => 'filemanager.write', 'filemanager.transfer_ability' => 'filemanager.transfer']);
    Gate::define('filemanager.write', fn ($user): bool => $user->name !== 'Reader');
    Gate::define('filemanager.transfer', fn ($user): bool => false);

    $this->actingAs($this->member(['name' => 'Reader']));
    $this->getJson(route('filemanager.contents'))->assertOk()->assertJsonPath('data.canWrite', false);
    $this->postJson(route('filemanager.folders.store'), ['name' => 'New'])->assertForbidden();
    $this->putJson(route('filemanager.folders.rename', $folder), ['name' => 'Renamed'])->assertForbidden();
    $this->deleteJson(route('filemanager.items.destroy'), ['folders' => [$folder->id]])->assertForbidden();

    $this->actingAs($this->member());
    $this->postJson(route('filemanager.folders.store'), ['name' => 'New'])->assertOk();
    $this->putJson(route('filemanager.items.move'), ['folders' => [$folder->id], 'target_volume' => 'archive'])->assertForbidden();
    $this->postJson(route('filemanager.items.copy'), ['folders' => [$folder->id], 'target_volume' => 'archive'])->assertForbidden();
    $this->postJson(route('filemanager.items.copy'), ['folders' => [$folder->id], 'target_volume' => 'files'])->assertOk();
});

test('open tabs are remembered per user, without tabs that can no longer be opened', function () {
    $this->actingAs($this->member());
    $invoices = Folder::query()->create(['name' => 'Invoices']);
    $gone = Folder::query()->create(['name' => 'Gone']);

    $this->putJson(route('filemanager.preferences'), [
        'tabs' => [
            ['volume' => 'files', 'folder_id' => null],
            ['volume' => 'files', 'folder_id' => $invoices->id],
            ['volume' => 'files', 'folder_id' => $gone->id],
            ['volume' => 'nowhere', 'folder_id' => null],
        ],
        'active_tab' => 1,
    ])->assertOk();

    $gone->delete();

    $this->get(route('filemanager.index'))->assertOk()
        ->assertSee('&quot;tabs&quot;:[{&quot;volume&quot;:&quot;files&quot;,&quot;folderId&quot;:null},{&quot;volume&quot;:&quot;files&quot;,&quot;folderId&quot;:'.$invoices->id.'}]', false)
        ->assertSee('&quot;activeTab&quot;:1', false);
});
