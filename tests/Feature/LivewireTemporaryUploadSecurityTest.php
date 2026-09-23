<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

test('temporary uploads use the private local disk in production', function () {
    expect(config('livewire.temporary_file_upload.disk'))->toBe('local');
});

test('temporary uploads reject PHP payloads and disguised PHP filenames', function () {
    Storage::fake('tmp-for-tests');

    foreach ([
        UploadedFile::fake()->createWithContent('shell.php', '<?php echo "unsafe";'),
        UploadedFile::fake()->image('shell.jpg.php'),
    ] as $file) {
        $this->postJson(URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5)), [
            'files' => [$file],
        ])->assertUnprocessable();
    }

    expect(Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'))->toBeEmpty();
});

test('temporary uploads accept supported images', function () {
    Storage::fake('tmp-for-tests');

    $response = $this->postJson(URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5)), [
        'files' => [UploadedFile::fake()->image('screenshot.png')],
    ])->assertOk();

    Storage::disk('tmp-for-tests')->assertExists('livewire-tmp/'.$response->json('paths.0'));
});
