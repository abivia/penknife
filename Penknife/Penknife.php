<?php

namespace Abivia\Penknife;

use RuntimeException;

class Penknife
{
    public const int RESOLVE_DIRECTIVE = 1;
    public const int RESOLVE_EXPRESSION = 2;

    protected bool $compress = false;

    protected string $includePath = '';
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
        'system' => ':'
    ];

    public function compress(bool $compress): self
    {
        $this->compress = $compress;
        return $this;
    }

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
            if (str_starts_with($segment->text, $this->tokens['loop'])) {
                // Looping construct
                $args = explode(',', substr($segment->text, strlen($this->tokens['loop'])));
                $level = count($this->loopStack);
                $depth = $level + 1;
                $loopVar = trim($args[1] ?? "loop$depth");
                $list = $this->lookup($args[0]);
                if (is_array($list) && count($list)) {
                    $this->loopStack[] = ['name' => $loopVar, 'list' => $list, 'row' => 0];
                    $row = 0;
                    foreach (array_keys($this->loopStack[$level]['list']) as $this->loopStack[$level]['index']) {
                        $this->loopStack[$level]['row'] = $row++;
                        $result .= $this->execute($segment->truePart);
                    }
                    array_pop($this->loopStack);
                } else {
                    $result .= $this->execute($segment->falsePart);
                }
            } elseif (str_starts_with($segment->text, $this->tokens['if'])) {
                $subject = $this->lookup(substr($segment->text, strlen($this->tokens['if'])));
                if (!empty($subject)) {
                    $result .= $this->execute($segment->truePart);
                } else {
                    $result .= $this->execute($segment->falsePart);
                }
            } else {
                $result .= $this->lookup($segment->text);
            }
        }
        return $result;
    }

    private function find(
        string $tokenType,
        array $segments,
        Token $segment,
        int $from
    ): ?int
    {
        $result = null;
        $nest = 0;
        $find = $this->tokens[$tokenType] . $segment->text;
        for ($forward = $from + 1; $forward < count($segments); ++$forward) {
            if ($segments[$forward]->type === Token::COMMAND) {
                if ($segments[$forward]->text === $find) {
                    if ($nest === 0) {
                        $result = $forward;
                        break;
                    }
                    --$nest;
                } elseif ($segments[$forward]->text === $segment->text) {
                    ++$nest;
                }
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
        return $this->find('else', $segments, $segment, $from);
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
        $endMark = $this->find('end', $segments, $segment, $from);
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
        $this->segments = $this->segment($template);
        $this->resolver = $resolver;
        $parsed = $this->parse($this->segments);
        $this->loopStack = [];
        return $this->execute($parsed);
    }

    public function includePath(string $path = ''): self
    {
        $this->includePath = $path;

        return $this;
    }

    /**
     * Get the value of an expression.
     *
     * @param string $expression
     * @return int|mixed|string
     */
    private function lookup(string $expression)
    {
        // break out additional arguments
        $args = explode($this->tokens['args'], $expression);
        // Expand the sub-parts of the target value
        $parts = explode($this->tokens['scope'], $args[0]);

        // Get the default value, use an empty string if none is provided.
        $default = trim($args[1] ?? '');

        // If this is a loop variable with no index, add the index.
        if ($parts[0] === 'loop') {
            $parts[0] .= '1';
        }

        // Check for variables in a loop construct
        foreach ($this->loopStack as $loopId => $loop) {
            if ($parts[0] === $loop['name']) {
                // We have a loop match, pull the current list.
                $loopInfo = $this->loopStack[$loopId];
                if (count($parts) === 1) {
                    // We expect that the loop array contains scalars.
                    return $loopInfo['list'][$loopInfo['index']];
                }
                // Check for a reference to the loop index.
                if ($parts[1] === $this->tokens['index']) {
                    $index = $loopInfo['index'];
                    // If a bias is provided, add it in.
                    if ($parts[2] ?? false) {
                        $index = $loopInfo['row'] + (int)$parts[2];
                    }
                    return $index;
                }
                if (is_array($loopInfo['list'][$loopInfo['index']])) {
                    return $loopInfo['list'][$loopInfo['index']][$parts[1]] ?? $default;
                } else {
                    // Assume this is an object
                    return $loopInfo['list'][$loopInfo['index']]->{$parts[1]} ?? $default;
                }
            }
        }
        // Pass the expression to the resolver callback.
        return ($this->resolver)($expression, self::RESOLVE_EXPRESSION);
    }

    private function parse(array $segments): array
    {
        $parsed = [];
        for ($instruction = 0; $instruction < count($segments); ++$instruction) {
            /** @var Token $segment */
            $segment = $segments[$instruction];
            if ($segment->type === Token::TEXT) {
                $parsed[] = $segment;
                continue;
            }
            // Now dealing with a command.
            if (str_starts_with($segment->text, $this->tokens['end'])) {
                // this should be an error
                continue;
            }
            $next = $instruction + 1;
            $construct = '';
            $target = null;
            if (str_starts_with($segment->text, $this->tokens['system'])) {
                // System command
                $this->system($segments, $instruction);
                continue;
            } elseif (str_starts_with($segment->text, $this->tokens['loop'])) {
                // Looping construct
                $construct = 'loop';
                $args = explode(',', substr($segment->text, strlen($this->tokens['loop'])));
                $target = $segment->newText($this->tokens['loop'] . $args[0]);
            } elseif (str_starts_with($segment->text, $this->tokens['if'])) {
                $construct = 'if';
                $target = $segment;
            }
            if ($construct !== '') {
                $endMark = $this->findEnd($segments, $target, $instruction, $construct);
                $elseMark = $this->findElse($segments, $target, $instruction);
                $trueMark = $elseMark ?? $endMark;
                $segment->truePart = $this->parse(
                    array_slice($segments, $next, $trueMark - $next)
                );
                //array_pop($segment->truePart);
                if ($elseMark !== null) {
                    $segment->falsePart = $this->parse(
                        array_slice($segments, $elseMark + 1, $endMark - $elseMark - 1)
                    );
                }
                $instruction = $endMark;
            }
            $parsed[] = $segment;
        }
        return $parsed;
    }

    /**
     * Segment the template into a list of plaintext and commands.
     * @param string $template
     * @return array
     * @throws ParseError
     */
    private function segment(string $template): array
    {
        $markers = explode($this->tokens['open'], $template);
        $segments = [];
        $first = true;
        $line = 1;
        foreach ($markers as $marker) {
            $parts = explode($this->tokens['close'], $marker);
            switch (count($parts)) {
                case 1:
                    $text = $marker;
                    if (!$first) {
                        throw new ParseError("Unmatched closing token on or after line $line");
                    }
                    break;
                case 2:
                    $segments[] = new Token(Token::COMMAND, trim($parts[0]), $line);
                    $marker = $parts[1];
                    $text = $parts[1];
                    break;
                default:
                    throw new ParseError("Unexpected closing token on or after line $line");
            }
            if ($this->compress) {
                $text = trim($text);
            }
            if ($text !== '') {
                $segments[] = new Token(Token::TEXT, $marker, $line);
            }
            $line += substr_count($marker, "\n");
            $first = false;
        }
        return $segments;
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

    private function system(array &$segments, int $instruction)
    {
        // Get the system operation
        /** @var Token $segment */
        $segment = $segments[$instruction];
        $tokenLength = strlen($this->tokens['system']);
        $operation = strtolower(substr($segment->text, $tokenLength));
        $spaceAt = strpos($operation, ' ');
        if ($spaceAt !== false) {
            $operation = substr($operation, 0, $spaceAt);
        }
        switch ($operation) {
            case 'include':
                if ($spaceAt === false) {
                    throw new ParseError('The include directive requires a file path.');
                }
                $path = $this->includePath . trim(substr($segment->text, $tokenLength + $spaceAt));
                if (!file_exists($path)) {
                    throw new ParseError("Can't open $path for inclusion.");
                }
                $inject = $this->segment(file_get_contents($path));
                array_splice($segments, $instruction + 1, 0, $inject);
                break;
            default:
                ($this->resolver)($segment->text, self::RESOLVE_EXPRESSION);
                break;
        }
    }

}
