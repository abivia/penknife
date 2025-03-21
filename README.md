# Abivia\Penknife

## Penknife: a small blade

Penknife is a tiny but powerful template engine implemented in PHP 8.4.

Penknife supports:
* Variables
* Conditionals
* Nested loops
* Custom tokens

The `format()` method takes template text and a variable resolution callback as arguments.

Example:

```php
$engine = new \Abivia\Penknife\Penknife();
echo $engine->format(
   '{{first}} {{last}}', function (string $expression) {
       return match ($expression) {
           'first' => 'Jane',
           'last' => 'Doe',
       };
   });
);
```
Output:
```
Jane Doe
```

Variables can have a default value: `{{somevar, none}}` will emit "none"
if the resolution function returns a null value for `somevar`.

## Conditionals

Penknife supports if-then-else statements.

```
{{?variable}}
true part
{{!variable}}
Optional false part
{{/?variable}}
```
If the resolution callback returns a value that evaluates as `empty()` then the condition is false,
otherwise it is true.

## Loops

A loop is indicated with an ampersand followed by the name of an array. 
Elements of the array can be accessed by the loop variable `loop`. 
Nested loops are accessed by their nesting level, `loop2`, `loop3`, etc.
for ease of use, `loop` is the same as `loop1`. 
The current index of the array can be obtained by using a # with an optional bias,
which is useful for numbering from 1.
If the array is associative, this will return the current key.
An associative array with a numerical bias will return the numerical position.

```php
$template = "looping:\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop}}\n{{/@list}}";
$engine = new \Abivia\Penknife\Penknife();
echo $engine->format($template, function ($expr) {
    return $expr === 'list' ? ['first' => 'one', 'second' => 'two'] : null;
});
```

Output:
```
looping:
index first line 1 value one
index second line 2 value two
```

### Nested Loops

Loops can be nested:

```php
$testObj = new Penknife();
$template = "looping:"
    . "\n{{@list}}index {{loop.#}} line {{loop1.#.1}} value {{loop.name}}"
    . "\n{{@loop.data}}{{loop2}} {{/@loop.data}}"
    . "\n{{/@list}}";
$engine = new \Abivia\Penknife\Penknife();
echo $engine->format($template, function ($expr) {
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
```

Output:
```
looping:
index 0 line 1 value slice one
1 2 3 4 
index 1 line 2 value slice two
4 5 6 
```

### Named loops

Since keeping track of loop variables like loop1, loop2, etc. can be tedious,
it is possible to give a loop a name:

```
{{@loop.data,dataLoop}}{{dataLoop.element}}{{/@loop.data}}
```

## Alternate Syntax

All tokens in Penknife can be modified via the `setToken()` and `setTokens()` methods.
The default tokens are:


|  Name | Value  | Usage |
|---|---|---|
|args|,| Separate arguments inside a command|
|close|}}|Closes a command|
|else|!|Else operator|
|end|/|End operator, terminates an if or loop|
|if|?|Conditional operator|
|index|#|Current loop index|
|loop|@|Starts a loop|
|open|{{|Opens a command|
|scope|.|Scope operator|

```php
$engine = new \Abivia\Penknife\Penknife();
// Note the spaces in the if and else tokens
$engine->setTokens([
    'open' => '<**<', 
    'close' => '>**>', 
    'if' => 'if ', 
    'else' => 'else ',
     'end' => '~',
]);

// This is now a valid template:
$template = "conditional:<**<if test>**>TRUE<**<else if test>**>FALSE<**<~if test>**>."

```
