# open-ai-device-auth

Device Code authentication as a PHP Composer package. The CLI performs the OpenAI device login flow and writes an `auth.json` with ChatGPT tokens.

## Requirements

- PHP 8.4+
- Composer
- Device Code authentication enabled in your ChatGPT account

## Installation

```bash
composer require armin/open-ai-device-auth
```

## Usage

The CLI exposes three commands:

```bash
vendor/bin/open-ai-device-auth login
vendor/bin/open-ai-device-auth refresh
vendor/bin/open-ai-device-auth usage
```

### Login

Start the device login flow:

```bash
vendor/bin/open-ai-device-auth login
```

Write to a custom location:

```bash
vendor/bin/open-ai-device-auth login --auth-file=/path/to/auth.json
```

### Refresh

Refresh tokens in `./auth.json`:

```bash
vendor/bin/open-ai-device-auth refresh
```

Refresh tokens in a custom `auth.json`:

```bash
vendor/bin/open-ai-device-auth refresh --auth-file=/path/to/auth.json
```

### Usage

Fetch ChatGPT usage and rate limits from `./auth.json`:

```bash
vendor/bin/open-ai-device-auth usage
```

Fetch ChatGPT usage and rate limits from a custom `auth.json`:

```bash
vendor/bin/open-ai-device-auth usage --auth-file=/path/to/auth.json
```

Return the raw payload as JSON:

```bash
vendor/bin/open-ai-device-auth usage --auth-file=/path/to/auth.json --format=json
```

## Flow

1. Run the `login` command.
2. Open `https://auth.openai.com/codex/device` in any browser.
3. Enter the displayed one-time code.
4. Wait for authorization to complete.
5. The CLI writes `./auth.json` unless `--auth-file` is provided.

All three commands support `--auth-file` and `-a`. The default path is `./auth.json`.

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
- `refresh` replaces the stored tokens in-place and updates `last_refresh`.
- `usage` reads the stored `access_token` and prints either a human-readable summary or JSON.

## License

MIT
