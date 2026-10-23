# Code style

Use the project's Laravel Pint configuration for formatting and Larastan for
static analysis. Keep code compatible with the PHP 8.4 project baseline. Strict
types declarations are optional and are not a project-wide requirement.

## Naming

- Classes and matching filenames use `PascalCase`.
- Application variables, parameters, properties, and methods use `camelCase`.
- Constants use `UPPER_SNAKE_CASE`.
- Database columns, configuration keys, and structured log keys use
  `snake_case`.
- Preserve names required by Laravel, PHP interfaces, and external contracts.

## Types and readability

Declare parameter, property, and return types when their contracts are known.
Use PHPDoc for collection element types and array shapes that PHP cannot express.
Keep methods focused, use clear names, and prefer straightforward control flow
over compressed expressions. Follow Pint for formatting rather than making
local style exceptions.

## Tests

Test observable behavior at the relevant boundary. Use Laravel's test helpers
for HTTP, console, and database behavior. Avoid trivial assertions and tests
that only mirror implementation details. Every file under [app/](../app/) is
included in the CI line-coverage gate; keep code exercised through meaningful
behavior and do not add artificial exclusions.

Use `test_descriptive_snake_case` for test methods and camelCase for test
helpers and data providers. Keep concrete test classes final unless they are
designed to be extended.
