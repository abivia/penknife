<?php

namespace Abivia\Penknife;

use PHPUnit\Framework\TestCase;

class PenknifeTest extends TestCase
{
    public function testTrivial()
    {
        $testObj = new Penknife();
        $template = "This is just text\non two lines";
        $result = $testObj->format($template, function ($expr) {return $expr;});
        $this->assertEquals($template, $result);
    }

    public function testSimpleVariable()
    {
        $testObj = new Penknife();
        $template = "This is text with a {{var}}\non the first of two lines";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'var' ? 'replacement' : "??$expr??";
        });
        $expect = "This is text with a replacement\non the first of two lines";
        $this->assertEquals($expect, $result);
    }

    public function testSimpleVariableAtStart()
    {
        $testObj = new Penknife();
        $template = "{{var}} at the start";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'var' ? 'replacement' : "??$expr??";
        });
        $expect = "replacement at the start";
        $this->assertEquals($expect, $result);
    }

    public function testSimpleVariableAtEnd()
    {
        $testObj = new Penknife();
        $template = "at the end {{var}}";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'var' ? 'replacement' : "??$expr??";
        });
        $expect = "at the end replacement";
        $this->assertEquals($expect, $result);
    }

    public function testSimpleVariable2()
    {
        $testObj = new Penknife();
        $template = "This is text with a {{var}}\non two {{ var }} lines";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'var' ? 'replacement' : "??$expr??";
        });
        $expect = "This is text with a replacement\non two replacement lines";
        $this->assertEquals($expect, $result);
    }

    public function testNotClosed()
    {
        $testObj = new Penknife();
        $template = "error {{right <-there.";
        $this->expectException(ParseError::class);
        $testObj->format($template, function ($expr) {
            return $expr === 'test';
        });
    }

    public function testOverClosed()
    {
        $testObj = new Penknife();
        $template = "this is {{good}} but error }} <-there.";
        $this->expectException(ParseError::class);
        $testObj->format($template, function ($expr) {
            return $expr === 'test';
        });
    }

    public function testConditional()
    {
        $testObj = new Penknife();
        $template = "conditional:{{?test}}TRUE{{/?test}}.";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'test';
        });
        $expect = "conditional:TRUE.";
        $this->assertEquals($expect, $result);
        $result = $testObj->format($template, function ($expr) {
            return $expr !== 'test';
        });
        $expect = "conditional:.";
        $this->assertEquals($expect, $result);
    }

    public function testConditionalElse()
    {
        $testObj = new Penknife();
        $template = "conditional:{{?test}}TRUE{{!?test}}FALSE{{/?test}}.";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'test';
        });
        $expect = "conditional:TRUE.";
        $this->assertEquals($expect, $result);
        $result = $testObj->format($template, function ($expr) {
            return $expr !== 'test';
        });
        $expect = "conditional:FALSE.";
        $this->assertEquals($expect, $result);
    }

    public function testConditionalError()
    {
        $testObj = new Penknife();
        $template = "conditional:{{?test}}TRUE.";
        $this->expectException(ParseError::class);
        $testObj->format($template, function ($expr) {
            return $expr === 'test';
        });
    }

    public function testLoopDefault()
    {
        $testObj = new Penknife();
        $template = "looping:\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop}}\n{{/@list}}";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'list' ? ['one', 'two'] : null;
        });
        $expect = "looping:\nindex 0 line 1 value one\nindex 1 line 2 value two\n";
        $this->assertEquals($expect, $result);
    }

    public function testLoopNamed()
    {
        $testObj = new Penknife();
        $template = "looping:\n{{@list,bob}}index {{bob.#}} line {{bob.#.1}} value {{bob}}\n{{/@list}}";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'list' ? ['one', 'two'] : null;
        });
        $expect = "looping:\nindex 0 line 1 value one\nindex 1 line 2 value two\n";
        $this->assertEquals($expect, $result);
    }

    public function testLoopAssociative()
    {
        $testObj = new Penknife();
        $template = "looping:\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop}}\n{{/@list}}";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'list' ? ['first' => 'one', 'second' => 'two'] : null;
        });
        $expect = "looping:\nindex first line 1 value one\nindex second line 2 value two\n";
        $this->assertEquals($expect, $result);
    }

    public function testLoopArray()
    {
        $testObj = new Penknife();
        $template = "looping:"
            . "\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop.0}},{{loop.1}}"
            . "\n{{/@list}}";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'list' ? [[0, 0], [1, 1]] : null;
        });
        $expect = "looping:\nindex 0 line 1 value 0,0\nindex 1 line 2 value 1,1\n";
        $this->assertEquals($expect, $result);
    }

    public function testLoopArrayEmpty()
    {
        $testObj = new Penknife();
        $template = "looping:"
            . "\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop.0}},{{loop.1}}"
            . "\n{{!@list}}Empty\n{{/@list}}";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'list' ? [] : null;
        });
        $expect = "looping:\nEmpty\n";
        $this->assertEquals($expect, $result);
    }

    public function testLoopArrayAssociative()
    {
        $testObj = new Penknife();
        $template = "looping:"
            . "\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop.x}},{{loop.y}}"
            . "\n{{/@list}}";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'list' ? [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 1]] : null;
        });
        $expect = "looping:\nindex 0 line 1 value 0,0\nindex 1 line 2 value 1,1\n";
        $this->assertEquals($expect, $result);
    }

    public function testLoopArrayAssociativeObject()
    {
        $testObj = new Penknife();
        $template = "looping:"
            . "\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop.x}},{{loop.y}}"
            . "\n{{/@list}}";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'list'
                ? [ (object)['x' => 0, 'y' => 0], (object)['x' => 1, 'y' => 1]]
                : null;
        });
        $expect = "looping:\nindex 0 line 1 value 0,0\nindex 1 line 2 value 1,1\n";
        $this->assertEquals($expect, $result);
    }

    public function testLoopNested()
    {
        $testObj = new Penknife();
        $template = "looping:"
            . "\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop.name}}"
            . "\n{{@loop.data,nesty}}{{nesty}} {{/@loop.data}}"
            . "\n{{/@list}}";
        $result = $testObj->format($template, function ($expr) {
            if ($expr === 'list') {
                return [
                    [
                        'name' => 'slice one',
                        'data' => [1, 2, 3, 4],
                    ],
                    [
                        'name' => 'slice two',
                        'data' => [4, 5, 6],
                    ],
                ];
            }
            return null;
        });
        $expect = "looping:"
            . "\nindex 0 line 1 value slice one"
            . "\n1 2 3 4 "
            . "\nindex 1 line 2 value slice two"
            . "\n4 5 6 "
            . "\n";
        $this->assertEquals($expect, $result);
    }

    public function testSetTokenBad()
    {
        $testObj = new Penknife();
        $this->expectException(SetupError::class);
        $testObj->setToken('fubar', 1);
    }

    public function testSetTokens()
    {
        $testObj = new Penknife();
        $testObj->setTokens([
            'open' => '<**<',
            'close' => '>**>',
            'if' => 'if ',
            'else' => 'else ',
            'end' => '~',
        ]);
        $template = "conditional:<**<if test>**>TRUE<**<else if test>**>FALSE<**<~if test>**>.";
        $result = $testObj->format($template, function ($expr) {
            return $expr === 'test';
        });
        $expect = "conditional:TRUE.";
        $this->assertEquals($expect, $result);
        $result = $testObj->format($template, function ($expr) {
            return $expr !== 'test';
        });
        $expect = "conditional:FALSE.";
        $this->assertEquals($expect, $result);
    }

    public function testSystemInclude()
    {
        $map = [
            'alias' => 'link-alias',
            'analyticsUrl' => '#alias',
            'clickRows' => [],
            'dailyStats' => false,
        ];

        $testObj = new Penknife()->includePath(__DIR__);
        $html = $testObj->format(
            "start\n{{:include /../../complexTemplate.html}}\nfinish.",
            function ($attr) use ($map) {
                return $map[$attr] ?? "\{\{$attr??\}\}";
            }
        );
        $this->assertStringContainsString('start', $html);
        $this->assertStringContainsString('finish', $html);
        $this->assertStringContainsString('No daily stats.', $html);
        $this->assertStringContainsString('No clicks.', $html);

    }

    public function testComplex1()
    {
        $map = [
            'alias' => 'link-alias',
            'analyticsUrl' => '#alias',
            'clickRows' => [],
            'dailyStats' => false,
        ];

        $testObj = new Penknife();
        $html = $testObj->format(
            file_get_contents(__DIR__ . '/../../complexTemplate.html'),
            function ($attr) use ($map) {
                return $map[$attr] ?? "\{\{$attr??\}\}";
            }
        );
        $this->assertStringContainsString('No daily stats.', $html);
        $this->assertStringContainsString('No clicks.', $html);
    }

    public function testComplex2()
    {
        $map = [
            'alias' => 'link-alias',
            'analyticsUrl' => '#alias',
            'clickRows' => [],
            'dailyStats' => false,
            'paginated' => true,
        ];

        $testObj = new Penknife();
        $this->expectException(ParseError::class);
        $html = $testObj->format(
            file_get_contents(__DIR__ . '/../../openNestedConditional.html'),
            function ($attr) use ($map) {
                return $map[$attr] ?? "\{\{$attr??\}\}";
            }
        );
    }

    public function testComplex3()
    {
        $map = [
            'alias' => 'link-alias',
            'analyticsUrl' => '#alias',
            'clickRows' => [],
            'dailyStats' => false,
            'paginated' => true,
        ];

        $testObj = new Penknife()->compress(false);
        $html = $testObj->format(
            file_get_contents(__DIR__ . '/../../identicalNestedConditionals.html'),
            function ($attr) use ($map) {
                return $map[$attr] ?? "\{\{$attr??\}\}";
            }
        );
        $this->assertStringContainsString('No clicks.', $html);
    }

}
