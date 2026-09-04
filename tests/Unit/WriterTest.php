<?php

declare(strict_types=1);

use Lusen\Emit\EmittedFile;
use Lusen\Support\Writer;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/lusen-writer-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

it('creates nested directories on the way to a file', function (): void {
    (new Writer($this->root))->write(new EmittedFile('endpoints/users/index.html', '<p>hi</p>'));

    expect($this->root.'/endpoints/users/index.html')->toBeFile();
});

it('reports a write as skipped when the contents already match', function (): void {
    $writer = new Writer($this->root);
    $file = new EmittedFile('a.txt', 'same');

    expect($writer->write($file))->toBeTrue()
        ->and($writer->write($file))->toBeFalse();
});

it('summarises a batch of writes', function (): void {
    $result = (new Writer($this->root))->writeAll([
        new EmittedFile('a.txt', 'aaa'),
        new EmittedFile('b.txt', 'bbbb'),
    ]);

    expect($result)->toBe(['written' => 2, 'skipped' => 0, 'bytes' => 7]);
});

it('refuses to write outside the output root', function (): void {
    expect(fn () => (new Writer($this->root))->write(new EmittedFile('../escaped.txt', 'no')))
        ->toThrow(RuntimeException::class, 'Refusing to write outside');
});
