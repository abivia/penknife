<?php

namespace Abivia\Penknife;

class Token
{
    const int TEXT = 0;
    const int COMMAND = 1;
    const int IF = 2;
    const int LOOP = 3;

    public array $falsePart = [];
    public array $truePart = [];

    public function __construct(
        protected(set) int $type,
        protected(set) string $text,
        protected(set) int $line = 0
    )
    {}

    public function newText(string $text): Token
    {
        return new Token($this->type, $text, $this->line);
    }

}
