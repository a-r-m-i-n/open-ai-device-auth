# open-ai-device-auth

Device Code authentication as a PHP Composer package. The CLI performs the OpenAI device login flow and writes an `auth.json` with ChatGPT tokens.

## Requirements

- PHP 8.4+
- Composer
- Symfony Console, Filesystem and HttpClient 7.4+ or 8.x
- Device Code authentication enabled in your ChatGPT account

## Installation

```bash
composer require armin/open-ai-device-auth
```

## Usage

Run the Composer binary:

```bash
vendor/bin/open-ai-device-auth
```

Write to a custom location:

```bash
vendor/bin/open-ai-device-auth --output=/path/to/auth.json
```

## Flow

1. Run the command.
2. Open `https://auth.openai.com/codex/device` in any browser.
3. Enter the displayed one-time code.
4. Wait for authorization to complete.
5. The CLI writes `./auth.json` unless `--output` is provided.

## Output Format

```json
{
  "auth_mode": "chatgpt",
  "OPENAI_API_KEY": null,
  "tokens": {
    "id_token": "...",
    "access_token": "...",
    "refresh_token": "...",
    "account_id": "..."
  },
  "last_refresh": "2026-04-24T11:17:48.681452Z"
}
```

## Notes

- The file format is tailored for ChatGPT token storage, not generic API key auth.
- `account_id` is extracted from the returned `id_token`.
- `last_refresh` is written as the current UTC timestamp when the file is created.

## License

MIT
