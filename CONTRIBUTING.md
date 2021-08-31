# Contributing

THanks for your interest in helping the project! :)

## Contributions

At this early stage, I am mainly open to the following contributions:
- Bug reports
- Bug fixes
- Feedback on usability (setup, language, and standard library API)
- Unit test coverage (see the `testsite` readme)
- Security testing & feedback. Most sensitive operations are in `Security.php`.




And support/compatibility for:
- Nginx/PHP-FPM
- Cache module: memcached, APC, Redis
- Db module: PostgreSQL

## Bug Reports & Feedback

https://github.com/joelesko/tht/issues

## Usability Feedback

I am interested in feedback like the following:

- I tried doing `X` and expected `Y`, but got `Z` instead.
- I am constantly running into issue `X`.
- I still can't figure out how to do `X`.
- I had to do `X`, but it would be a lot easier if I could just do `Y` instead.
- I got error `X`, which took a long time to fix.  If it told me `Y` up front, it would have been much easier.
- `X` and `Y` are inconsistent with each other, which makes it hard to remember.
- The docs for `X` left out information that took a lot of work to figure out on my own.


## New Features
I want to lead design and implementation of new features for now, so that the core direction is consistent.

However, I'm open to design-related suggestions.

## THT Design Philosophy

In rough order of priority:

- **Secure Defaults**. Security best practices should be built-in wherever possible.  Provide warning signs ('xDanger-' prefix) and guard rails (minor inconveniences) when the user intentionally goes down a less secure path.
- **Batteries Included**. Common patterns should be included in the standard library.  As a rule of thumb, most answers on Stackoverflow.com will result in a clean, obvious solution provided by the language, not copy-and-pasted functions.
- **Usability & Ergonomics**.  Use short, but complete words for function and module names. No abbreviations, where possible.  Most syntactic sugar provides shortcuts, not invisible behavior (magic).
- **Clear Errors**.  More than half of our programming time is spent in an error state, trying to fix something we just added. Error messages should be written in clear, understandable language and suggest solutions. (Elm can provide some inspiration here.)
- **Clean, Not Pedantic**.  THT takes an opinionated stance on (hopefully) non-controversial, well-established approaches to writing code.  It provides helpful structure, while trying to avoid being unreasonably strict.  They are like guard rails: they are intended to keep you moving forward, not to cage you in.
- **Familiarity**.  We should favor design decisions that are already familiar to PHP & JavaScript developers, or web developers in general.  Unless those designs are widely considered to be flawed or conflict with THT's higher priorities (e.g. security).
- **Borrow Good Ideas**.  We are all part of a greater open source community.  Let's freely take the best ideas from other projects (giving credit where we can), and be happy to share our own solutions with other projects.
- **Can't Please Everyone**.  Programmers are an opinionated lot, and there are an infinite number of things to criticize in any programming language.  We can make good decisions and move things forward without getting bogged down in analysis paralysis or heated arguments.


## Execution Flow

The main files are (in rough order of execution):

- app/public/front.php - The entry point / front controller
- lib/core/Tht.php - Overall logic & setup
- lib/core/modes/WebMode.php - Determine which page to execute based on the URL route
- lib/core/compiler/Compiler.php - Compile the page if it isn't cached.  Execute the transpiled PHP.
- lib/core/compiler/Tokenizer.php - Break THT source into tokens, apply template function transforms (e.g. HTML)
- lib/core/compiler/Parser.php - Convert the tokens into an AST
- lib/core/compiler/symbols/* - The parser logic for each symbol
- lib/core/compiler/EmitterPhp.php - Convert the AST to a PHP file
- lib/core/runtime/ErrorHandler.php - All errors (php & tht) are routed here


## Parser

The parser uses "Top Down Operator Precedence" aka "Pratt Parsing", as described here:
http://crockford.com/javascript/tdop/tdop.html

Each symbol can have one or more of the following methods, based on its position in the source:

- asStatement: the start of a complete statement (a tree of expressions). e.g. `return`, `if`
- asLeft: at the start of an expression. e.g. `{`, `[`
- asInner: in the middle of an expression. e.g. `+`, `.`

For example, `-` (minus) can have `asLeft` (prefix, e.g. '-123') and `asInner` (infix, e.g. '45 - 23').


## Performance
It's probably too early to optimize for performance.  We will wait until the implementation is stable.

The compiler itself doesn't have to be optimized much.  It is already less than a second in most cases, and this will only affect the developer when they make a change.

## Conduct
It's probably too early for a full Code of Conduct.  Essentially, all contributors should be completely civil and professional.

This is especially true when giving and receiving feedback, and expressing disagreement.  Choose your words carefully and don't be afraid to use smile emojis to lower the intensity. :)

THanks!
