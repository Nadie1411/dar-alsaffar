<?php

namespace Tests\Unit\Support;

use App\Support\Html;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlTest extends TestCase
{
    public function test_the_few_tags_a_description_needs_survive_including_arabic_text(): void
    {
        $html = '<p>مرحباً <strong>عطر</strong> <em>العود</em></p><ul><li>One</li><li>Two<br>lines</li></ul><h3>Title</h3>';

        $this->assertSame($html, Html::clean($html));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function dangerous(): array
    {
        return [
            'a script block' => ['<p>a</p><script>alert(1)</script>', '<p>a</p>'],
            'a style block' => ['<style>body{display:none}</style>hello', 'hello'],
            'an event handler' => ['<p onclick="steal()">hi</p>', '<p>hi</p>'],
            'an inline style' => ['<p style="position:fixed">hi</p>', '<p>hi</p>'],
            'a class' => ['<p class="x">hi</p>', '<p>hi</p>'],
            'an iframe' => ['<p>a</p><iframe src="//evil.example"></iframe>', '<p>a</p>'],
            'an object and an embed' => ['<object data="x"></object><embed src="y">', ''],
            'an svg with a handler' => ['<svg onload="alert(1)"><circle/></svg>ok', 'ok'],
            'a form' => ['<form action="//evil"><input name="p"><button>Go</button></form>x', 'x'],
            'an image' => ['<img src="x" onerror="alert(1)">text', 'text'],
            'an unknown tag keeps its text' => ['<blink>shout</blink>', 'shout'],
            'a comment' => ['<p>a</p><!-- secret -->', '<p>a</p>'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_anything_dangerous_is_removed(string $input, string $expected): void
    {
        $this->assertSame($expected, Html::clean($input));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unsafeLinks(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript in capitals' => ['JAVASCRIPT:alert(1)'],
            'javascript broken by a tab' => ["java\tscript:alert(1)"],
            'javascript broken by a newline' => ["java\nscript:alert(1)"],
            'javascript with leading space' => ['  javascript:alert(1)'],
            'a data link' => ['data:text/html;base64,PHNjcmlwdD4='],
            'a vbscript link' => ['vbscript:msgbox(1)'],
            'a protocol-relative link' => ['//evil.example/x'],
        ];
    }

    #[DataProvider('unsafeLinks')]
    public function test_a_link_that_could_run_code_loses_its_address(string $href): void
    {
        $clean = Html::clean('<a href="'.$href.'">click</a>');

        $this->assertStringNotContainsString('href', $clean);
        $this->assertStringContainsString('click', $clean);
    }

    public function test_ordinary_links_are_kept_and_made_safe_to_follow(): void
    {
        $clean = Html::clean('<a href="https://example.com/a?b=1&c=2" target="_blank" onclick="x()">ok</a><a href="mailto:a@b.co">m</a><a href="tel:+96512345678">t</a><a href="/ar-KW/products">p</a>');

        $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
        $this->assertStringContainsString('href="mailto:a@b.co"', $clean);
        $this->assertStringContainsString('href="tel:+96512345678"', $clean);
        $this->assertStringContainsString('href="/ar-KW/products"', $clean);
        $this->assertStringNotContainsString('target', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
    }

    public function test_nothing_in_gives_nothing_out(): void
    {
        $this->assertSame('', Html::clean(null));
        $this->assertSame('', Html::clean('   '));
        $this->assertSame('', Html::clean('<script>x</script>'));
    }

    public function test_cleaning_twice_changes_nothing(): void
    {
        $once = Html::clean('<p onclick="x()">Hello <b>world</b> <a href="https://a.example">x</a></p><script>1</script>');

        $this->assertSame($once, Html::clean($once));
    }

    public function test_plain_text_becomes_paragraphs_with_line_breaks_and_everything_escaped(): void
    {
        $this->assertSame(
            '<p>First line<br>still first</p><p>Second &lt;b&gt;para&lt;/b&gt; &amp; more</p>',
            str_replace("\n", '', Html::paragraphs("First line\nstill first\n\nSecond <b>para</b> & more"))
        );
        $this->assertSame('', Html::paragraphs(null));
        $this->assertSame('', Html::paragraphs("  \n "));
        $this->assertSame('<p>One</p><p>Two</p>', Html::paragraphs("One\r\n\r\n\r\nTwo"));
    }
}
