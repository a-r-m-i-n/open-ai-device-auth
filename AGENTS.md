# Repository guidance

## Commands and test quirks

- Requires PHP 8.4+; install locked dependencies with `composer install`.
- In this checkout, run `php bin/open-ai-device-auth <login|refresh|usage>`; README's `vendor/bin/open-ai-device-auth` path is for consumers installing the package.
- Standard suite: `composer test`. For reliable ANSI assertions in headless sessions, use `TERM=xterm-256color php vendor/bin/phpunit --do-not-cache-result` (also avoids errors when `.phpunit.cache` is unwritable).
- Focused file: `TERM=xterm-256color php vendor/bin/phpunit --do-not-cache-result tests/Http/UsageClientTest.php`; add `--filter testItReturnsAuthorizationDataOnSuccess` with `tests/Http/DeviceCodePollerTest.php` to run one method.
- HTTP tests use Symfony `MockHttpClient`/`MockResponse`; command tests inject these clients and use `CommandTester`. No live account or service is needed. Poller tests still sleep at least one second per call, even with interval `0`.
- Run tests from the repository root. The two default-path tests execute in empty temporary directories and restore the working directory afterward, so a local `./auth.json` does not affect them. Use `--auth-file` pointing outside the checkout for manual login/refresh; root `/auth*.json` files are ignored local token stores, not test fixtures.

## Wiring and behavior

- `bin/open-ai-device-auth` explicitly registers commands; `#[AsCommand]` alone does not register a new command. `bin/bootstrap.php` supports both checkout and Composer-installed autoload paths.
- There is no Symfony application container: commands accept nullable constructor dependencies for tests and construct defaults in `execute()`, using `OpenAiHttpClientFactory` for shared HTTP defaults.
- Login runs device-code request → polling → token exchange → account-ID extraction → auth-file write. Refresh reuses the decoder/writer and replaces tokens in the same file. `TokenPayloadDecoder` extracts `account_id` from `id_token` as `$claims['https://api.openai.com/auth']['chatgpt_account_id']`.
- Preserve the ChatGPT auth-file shape in `AuthFileWriter`: `auth_mode: chatgpt`, `OPENAI_API_KEY: null`, nested tokens, and UTC `last_refresh` with microseconds. It is not API-key authentication.
- `UsageClient` accepts upstream snake_case/camelCase and nested rate-limit shapes; keep normalization there. `usage --format=json` serializes normalized `UsageResponse::toArray()`, despite README describing raw JSON. Default output is `bars`; `text` and `json` are alternatives (`-f` alias). `-v` emits the upstream payload before rendering, including in JSON mode.
- For time-dependent usage rendering tests, inject `UsageCommand`'s `nowProvider` closure rather than relying on wall-clock time; see `tests/Command/CommandTest.php`.
