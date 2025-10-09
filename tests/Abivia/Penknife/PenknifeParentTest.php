<?php

namespace Abivia\Penknife;

use PHPUnit\Framework\TestCase;

class PenknifeParentTest extends TestCase
{
    public function testParent()
    {
        $testObj = new Penknife()->includePath(__DIR__ . '/../../');
        $template = "
{{:parent parentTemplate.html}}
{{:export bigBob:bob}}
{{:inject slot1}}
This text goes to slot1 with {{bob}}
{{:inject slot2}}
This text goes to slot 2 with {{sue}}
        ";
        $expect = 'before

This text goes to slot1 with Robert

middle

This text goes to slot 2 with Susan
        
after
BigBob is Robert
end
';
        $vars = ['bob' => 'Robert', 'sue' => 'Susan'];
        $result = $testObj->format($template, function ($expr) use ($vars) {
            return $vars[$expr] ?? "**undefined $expr**";
        });

        $this->assertEquals($expect, $result);
    }

}
