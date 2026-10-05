<?php

namespace App\Services\Store;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Keeps the images staff upload — product photos, category pictures, the
 * pop-up and the hero — in public/uploads, under random names.
 *
 * What may be uploaded is the controller's to validate; what is stored here is
 * named after the file's actual content type, never after what the browser
 * called it.
 */
class Uploads
{
    /**
     * The only kinds of file that are ever written to public/. The controllers
     * validate uploads first; this is the last line, so that nothing which
     * could run on the server can land in a folder the web server serves.
     */
    protected const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];

    /** Where images that other tables point at are kept. */
    protected const REFERENCES = [
        ['products', 'main_image'],
        ['product_images', 'path'],
        ['categories', 'image'],
        ['option_values', 'image'],
        ['service_addons', 'image'],
    ];

    public function __construct(protected ?string $root = null) {}

    /**
     * @return string the path under public/, such as uploads/catalog/abc.jpg
     *
     * @throws InvalidArgumentException when the file's real type is not one this shop keeps
     */
    public function store(UploadedFile $file, string $folder = 'catalog'): string
    {
        // The extension comes from what the file contains, not from its name.
        $extension = strtolower((string) $file->extension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('A file of type ['.($extension ?: 'unknown').'] is not an upload this shop keeps.');
        }

        $directory = 'uploads/'.trim($folder, '/');
        $target = $this->root().'/'.$directory;

        File::ensureDirectoryExists($target);

        $name = Str::random(32).'.'.$extension;
        $file->move($target, $name);

        return $directory.'/'.$name;
    }

    /**
     * Removes a stored file once nothing points at it any more. Catalogue
     * images copied from Overzaki are named after their source, so two
     * products can share one file: it stays while either still uses it.
     */
    public function delete(?string $path): void
    {
        if (! $this->isOurs($path) || $this->isReferenced($path)) {
            return;
        }

        File::delete($this->root().'/'.$path);
    }

    /** Only files this class could have written — never a URL, never a path that climbs out. */
    protected function isOurs(?string $path): bool
    {
        return is_string($path)
            && preg_match('#^uploads/([A-Za-z0-9_\-]+/)*[A-Za-z0-9_\-]+\.[A-Za-z0-9]+$#', $path) === 1;
    }

    protected function isReferenced(string $path): bool
    {
        foreach (self::REFERENCES as [$table, $column]) {
            if (DB::table($table)->where($column, $path)->exists()) {
                return true;
            }
        }

        return false;
    }

    protected function root(): string
    {
        return rtrim($this->root ?? public_path(), '/');
    }
}
