<?php

namespace Abivia\Penknife;

use RuntimeException;

class Penknife
{
    /**
     * @var array Data on (nested) loops
     */
    protected array $loopStack = [];
    /**
     * @var callable
     */
    protected $resolver;
    /**
     * @var array Token[]
     */
    protected array $segments = [];
    protected array $tokens = [
        'args' => ',',
        'close' => '}}',
        'else' => '!',
        'end' => '/',
        'if' => '?',
        'index' => '#',
        'loop' => '@',
        'open' => '{{',
        'scope' => '.',
    ];

    /**
     * @param array $segments
     * @return string
     * @throws ParseError
     */
    private function execute(array $segments): string
    {
        $result = '';
        for ($instruction = 0; $instruction < count($segments); ++$instruction) {
            /** @var Token $segment */
            $segment = $segments[$instruction];
            if ($segment->type === Token::TEXT) {
                $result .= $segment->text;
                continue;
            }
            // Now dealing with a command.
            if (str_starts_with($segment->text, $this->tokens['end'])) {
                continue;
            }
            $next = $instruction + 1;
            if (str_starts_with($segment->text, $this->tokens['loop'])) {
                // Looping construct
                $args = explode(',', substr($segment->text, strlen($this->tokens['loop'])));
                $target = $segment->newText($this->tokens['loop'] . $args[0]);
                $endMark = $this->findEnd($segments, $target, $instruction, 'loop');
                $level = count($this->loopStack);
                $depth = $level + 1;
                $loopVar = trim($args[1] ?? "loop$depth");
                $list = $this->lookup($args[0]);
                if (!is_array($list)) {
                    throw new RuntimeException("Loop variable $args[0] is not an array.");
                }
                $this->loopStack[] = ['name' => $loopVar, 'list' => $list, 'row' => 0];
                $loopBody = array_slice($segments, $next, $endMark - $next);
                $row = 0;
                foreach (array_keys($this->loopStack[$level]['list']) as $this->loopStack[$level]['index']) {
                    $this->loopStack[$level]['row'] = $row++;
                    $result .= $this->execute($loopBody);
                }
                array_pop($this->loopStack);
                $instruction = $endMark;
            } elseif (str_starts_with($segment->text, $this->tokens['if'])) {
                $endMark = $this->findEnd($segments, $segment, $instruction, 'if');
                $elseMark = $this->findElse($segments, $segment, $instruction);
                $subject = $this->lookup(substr($segment->text, strlen($this->tokens['if'])));
                if (!empty($subject)) {
                    $nested = array_slice($segments, $next, ($elseMark ?? $endMark) - $next);
                    $result .= $this->execute($nested);
                } elseif ($elseMark !== null) {
                    $nested = array_slice($segments, $instruction + $elseMark, $elseMark - $next);
                    $result .= $this->execute($nested);
                }
                $instruction = $endMark;
            } else {
                $result .= $this->lookup($segment->text);
            }
        }
        return $result;
    }

    /**
     * Find an else command in a list of template segments that matches an if token.
     * @param array $segments The token list to scan.
     * @param Token $segment The conditional token to match.
     * @param int $from The position where the end token is found.
     * @return int|null Returns null if no else token is found.
     */
    private function findElse(array $segments, Token $segment, int $from): ?int
    {
        $endMark = null;
        $find = $this->tokens['else'] . $segment->text;
        for ($forward = $from + 1; $forward < count($segments); ++$forward) {
            if ($segments[$forward]->type === Token::COMMAND && $segments[$forward]->text === $find) {
                $endMark = $forward;
                break;
            }
        }
        return $endMark;
    }

    /**
     * Find an end command in a list of template segments that matches a start token.
     * @param array $segments The token list to scan.
     * @param Token $segment The start token to match.
     * @param int $from A starting position in the segment list.
     * @param string $construct A human-readable name for the token.
     * @return int The position where the end token is found.
     * @throws ParseError Thrown if no matching end token exists.
     */
    private function findEnd(array $segments, Token $segment, int $from, string $construct): int
    {
        $endMark = null;
        $find = $this->tokens['end'] . $segment->text;
        for ($forward = $from + 1; $forward < count($segments); ++$forward) {
            if ($segments[$forward]->type === Token::COMMAND && $segments[$forward]->text === $find) {
                $endMark = $forward;
                break;
            }
        }
        if ($endMark === null) {
            throw new ParseError(
                "Unterminated $construct: $segment->text starting on or after line $segment->line"
            );
        }

        return $endMark;
    }

    /**
     * Use a resolver to populate and format a template.
     * @param string $template
     * @param callable $resolver
     * @return string
     * @throws ParseError
     */
    public function format(string $template, callable $resolver): string
    {
        $this->segment($template);
        $this->resolver = $resolver;
        $this->loopStack = [];
        return $this->execute($this->segments);
    }

    private function lookup(string $expression)
    {
        $args = explode($this->tokens['args'], $expression);
        $parts = explode($this->tokens['scope'], $args[0]);
        $default = trim($args[1] ?? '');
        if ($parts[0] === 'loop') {
            $parts[0] .= '1';
        }
        foreach ($this->loopStack as $loopId => $loop) {
            if ($parts[0] === $loop['name']) {
                $loopInfo = $this->loopStack[$loopId];
                if (count($parts) === 1) {
                    // We expect that the loop array contains scalars
                    return $loopInfo['list'][$loopInfo['index']];
                }
                if ($parts[1] === $this->tokens['index']) {
                    $index = $loopInfo['index'];
                    if ($parts[2] ?? false) {
                        $index = $loopInfo['row'] + (int) $parts[2];
                    }
                    return $index;
                }
                return $loopInfo['list'][$loopInfo['index']][$parts[1]] ?? $default;
            }
        }
        return ($this->resolver)($expression);
    }

    /**
     * Segment the template into a list of plaintext and commands.
     * @param string $template
     * @return void
     * @throws ParseError
     */
    private function segment(string $template): void
    {
        $markers = explode($this->tokens['open'], $template);
        $this->segments = [];
        $first = true;
        $line = 1;
        foreach ($markers as $marker) {
            $parts = explode($this->tokens['close'], $marker);
            switch (count($parts)) {
                case 1:
                    $this->segments[] = new Token(Token::TEXT, $marker, $line);
                    if (!$first) {
                        throw new ParseError("Unmatched closing token on or after line $line");
                    }
                    break;
                case 2:
                    $this->segments[] = new Token(Token::COMMAND, trim($parts[0]), $line);
                    $this->segments[] = new Token(Token::TEXT, $parts[1], $line);
                    break;
                default:
                    throw new ParseError("Unexpected closing token on or after line $line");
            }
            $line += substr_count($marker, "\n");
            $first = false;
        }
    }

    /**
     * Set the value of one of the parser tokens.
     * @param string $name
     * @param string $value
     * @return $this
     * @throws SetupError
     */
    public function setToken(string $name, string $value): self
    {
        if (!isset($this->tokens[$name])) {
            throw new SetupError(
                "$name is not a valid token name. Valid tokens are: "
                . implode(', ', array_keys($this->tokens))
            );
        }
        $this->tokens[$name] = $value;

        return $this;
    }

    /**
     * Set several parser tokens.
     * @param array $tokens
     * @return $this
     * @throws SetupError
     */
    public function setTokens(array $tokens): self
    {
        foreach ($tokens as $name => $value) {
            if (!isset($this->tokens[$name])) {
                throw new SetupError(
                    "$name is not a valid token name. Valid tokens are: "
                    . implode(', ', array_keys($this->tokens))
                );
            }
            $this->tokens[$name] = $value;
        }

        return $this;
    }

}
