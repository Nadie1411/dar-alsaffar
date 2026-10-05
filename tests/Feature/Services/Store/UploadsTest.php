<?php

namespace Tests\Feature\Services\Store;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ServiceAddon;
use App\Services\Store\Uploads;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UploadsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $root;

    private Uploads $uploads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/uploads-service-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->root);
        $this->uploads = new Uploads($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function file(string $path, string $contents = 'bytes'): string
    {
        File::ensureDirectoryExists(dirname($this->root.'/'.$path));
        File::put($this->root.'/'.$path, $contents);

        return $path;
    }

    public function test_a_file_is_stored_under_a_random_name_in_the_folder_asked_for_with_its_real_extension(): void
    {
        $path = $this->uploads->store(UploadedFile::fake()->image('../../etc/passwd.png', 100, 100), 'catalog');

        $this->assertMatchesRegularExpression('#^uploads/catalog/[A-Za-z0-9]{32}\.png$#', $path);
        $this->assertFileExists($this->root.'/'.$path);
    }

    /**
     * A real file on disk, as a browser upload is: what it is called and what
     * it holds are two separate claims.
     */
    private function realUpload(string $name, string $contents): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'upload');
        File::put($temp, $contents);

        return new UploadedFile($temp, $name, null, null, true);
    }

    public function test_a_script_offered_under_a_picture_name_is_refused_and_nothing_is_written(): void
    {
        $upload = $this->realUpload('photo.jpg', '<?php system($_GET["c"]);');

        try {
            $this->uploads->store($upload, 'catalog');
            $this->fail('A PHP file named photo.jpg must not be stored.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not an upload this shop keeps', $e->getMessage());
        }

        $this->assertSame([], File::allFiles($this->root));
    }

    public function test_a_picture_with_the_wrong_name_is_stored_under_the_extension_it_earns(): void
    {
        $png = UploadedFile::fake()->image('x.png', 50, 50);
        $upload = $this->realUpload('holiday.jpg', File::get($png->getPathname()));

        $this->assertStringEndsWith('.png', $this->uploads->store($upload, 'catalog'));
    }

    public function test_formats_this_shop_does_not_keep_are_refused_even_when_they_are_real_files(): void
    {
        foreach ([
            $this->realUpload('doc.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
            $this->realUpload('page.html', '<!doctype html><html><body>hi</body></html>'),
            $this->realUpload('logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ] as $upload) {
            try {
                $this->uploads->store($upload, 'catalog');
                $this->fail('a '.$upload->getClientOriginalName().' must not be stored');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame([], File::allFiles($this->root));
    }

    public function test_two_uploads_never_share_a_name(): void
    {
        $first = $this->uploads->store(UploadedFile::fake()->image('same.jpg'), 'catalog');
        $second = $this->uploads->store(UploadedFile::fake()->image('same.jpg'), 'catalog');

        $this->assertNotSame($first, $second);
    }

    public function test_a_file_nothing_uses_any_more_is_deleted(): void
    {
        $path = $this->file('uploads/catalog/unused.jpg');

        $this->uploads->delete($path);

        $this->assertFileDoesNotExist($this->root.'/'.$path);
    }

    /**
     * @return array<string,array{0:callable}>
     */
    public static function holders(): array
    {
        return [
            'a product main image' => [fn (string $path) => Product::factory()->create(['main_image' => $path])],
            'a gallery picture' => [fn (string $path) => ProductImage::factory()->create(['path' => $path])],
            'a category picture' => [fn (string $path) => Category::factory()->create(['image' => $path])],
            'an option value picture' => [fn (string $path) => OptionValue::factory()->for(OptionGroup::factory(), 'group')->create(['image' => $path])],
            'an add-on picture' => [fn (string $path) => ServiceAddon::factory()->create(['image' => $path])],
        ];
    }

    #[DataProvider('holders')]
    public function test_a_file_something_else_still_points_at_is_kept(callable $holder): void
    {
        $path = $this->file('uploads/catalog/shared.jpg');
        $holder($path);

        $this->uploads->delete($path);

        $this->assertFileExists($this->root.'/'.$path);
    }

    /**
     * @return array<string,array{0:?string}>
     */
    public static function notOurs(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'a remote address' => ['https://cdn.example.com/uploads/catalog/a.jpg'],
            'a path that climbs out' => ['uploads/../.env'],
            'a path that climbs out lower down' => ['uploads/catalog/../../.env'],
            'an absolute path' => ['/etc/passwd'],
            'something outside uploads' => ['assets/css/base.css'],
            'a file without an extension' => ['uploads/catalog/noextension'],
            'an unusual character' => ['uploads/catalog/a b.jpg'],
            'a null byte' => ["uploads/catalog/a.jpg\0.php"],
            'a bare folder' => ['uploads/'],
        ];
    }

    #[DataProvider('notOurs')]
    public function test_only_files_this_service_could_have_written_are_ever_deleted(?string $path): void
    {
        $bait = $this->file('.env', 'SECRET=1');
        File::ensureDirectoryExists($this->root.'/assets/css');
        File::put($this->root.'/assets/css/base.css', 'css');

        $this->uploads->delete($path);

        $this->assertFileExists($this->root.'/'.$bait);
        $this->assertFileExists($this->root.'/assets/css/base.css');
    }

    public function test_a_file_stored_straight_under_uploads_by_the_old_admin_can_still_be_cleaned_up(): void
    {
        $path = $this->file('uploads/legacy123.jpg');

        $this->uploads->delete($path);

        $this->assertFileDoesNotExist($this->root.'/'.$path);
    }

    public function test_deleting_a_file_that_is_already_gone_is_not_an_error(): void
    {
        $this->uploads->delete('uploads/catalog/never-existed.jpg');

        $this->assertTrue(true);
    }

    public function test_the_default_location_is_the_public_folder(): void
    {
        $reflection = new \ReflectionMethod(Uploads::class, 'root');
        $reflection->setAccessible(true);

        $this->assertSame(rtrim(public_path(), '/'), $reflection->invoke(new Uploads));
    }
}
