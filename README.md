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

The CLI exposes five commands:

```bash
vendor/bin/open-ai-device-auth login
vendor/bin/open-ai-device-auth refresh
vendor/bin/open-ai-device-auth usage
vendor/bin/open-ai-device-auth resets
vendor/bin/open-ai-device-auth reset
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

Return the normalized payload as JSON:

```bash
vendor/bin/open-ai-device-auth usage --auth-file=/path/to/auth.json --format=json
```

Output formats are `bars` (default), `text`, and `json` (`--format` / `-f`). When the usage endpoint includes the available reset-credit count, all three formats show it, including a count of zero. Text and bars display `Available resets: N`; JSON includes:

```json
{
  "rateLimitResetCredits": {
    "availableCount": 3
  }
}
```

If the backend omits this information, the reset count is omitted. No additional request is made to obtain it. `-v` prints the upstream payload before the normal output, including in JSON mode.

### Reset credits

List usage-limit reset credits, including their IDs, status, grant time, expiry, title, and description:

```bash
vendor/bin/open-ai-device-auth resets --auth-file=/path/to/auth.json
vendor/bin/open-ai-device-auth resets --format=json
```

Text output shows each reset in its own block, separated by a horizontal line. The title and status appear first, followed by the description, credit ID, grant date, expiry, and type. Dates are shown in UTC with a relative time, for example `2026-07-17 00:00:00 UTC (in 11d 12h)`. Status colors distinguish available, redeeming, and redeemed credits; expired dates are red and expiry within 24 hours is yellow.

The list endpoint returns an authoritative `availableCount` and detail rows in `credits`. The backend may limit the number of detail rows, so their count need not equal `availableCount`. Optional expiry, title, and description remain nullable in JSON. Unknown reset types and statuses are preserved.

Use one reset credit by specifying its ID. If `--credit-id` is omitted, the command displays the same reset blocks as `resets` and asks which available credit to use:

```bash
vendor/bin/open-ai-device-auth reset --auth-file=/path/to/auth.json
vendor/bin/open-ai-device-auth reset --credit-id=credit-1 --format=json
```

Both commands support `--format=text` (default) and `--format=json`, with `-f` as an alias. These requests use the stored ChatGPT access token and account ID.

`reset` always sends a concrete credit ID. There is no default selection, even if only one credit is available. Press ENTER without entering a choice to cancel: the command prints `Abort`, exits with code `0`, and does not consume a credit. Only credits with status `available` can be selected interactively. In non-interactive mode (`--no-interaction` / `-n`), `--credit-id` must be supplied explicitly. In JSON mode, the reset blocks, selection prompt, and cancellation message go to stderr so stdout contains only the JSON result (or stays empty on cancellation).

#### Retrying a reset

`reset` generates a UUIDv4 for each new attempt. It prints the request ID and selected credit ID to stderr before sending the consume request and includes them as `requestId` and `creditId` in a JSON result. When retrying the same attempt after a timeout or connection failure, reuse that ID **and the same credit selection**:

```bash
vendor/bin/open-ai-device-auth reset --request-id=<previous-request-id> --credit-id=credit-1
```

If the original credit was selected interactively, pass its displayed ID as `--credit-id` when retrying. The client does not automatically retry the POST. The backend decides which limit windows are eligible; a reset cannot select only the five-hour or weekly window.

JSON results contain `code`, `windowsReset`, `requestId`, and `creditId`:

| Code | Meaning | Exit code |
| --- | --- | --- |
| `reset` | Usage reset successfully | `0` |
| `already_redeemed` | The same request ID already completed a reset successfully | `0` |
| `nothing_to_reset` | No limit window currently needs a reset | `1` |
| `no_credit` | No available credit, or the selected credit is no longer available | `1` |

HTTP, transport, and response-decoding errors also return exit code `1`, with diagnostics on stderr. Use `usage` and `resets` to fetch the latest usage and remaining credits afterward.

### Library API

```php
use Armin\OpenAiDeviceAuth\Auth\AuthFileReader;
use Armin\OpenAiDeviceAuth\Http\OpenAiHttpClientFactory;
use Armin\OpenAiDeviceAuth\Http\RateLimitResetClient;

$auth = (new AuthFileReader())->read('/path/to/auth.json');
$client = new RateLimitResetClient(OpenAiHttpClientFactory::create());

$credits = $client->list($auth->accessToken, $auth->accountId);
$result = $client->consume(
    $auth->accessToken,
    $auth->accountId,
    $requestId, // Non-empty ID owned by the caller; reuse for retries.
    $creditId // Optional; omit to let the backend select a credit.
);

$result->isSuccess(); // Also true for already_redeemed.
$result->toArray();
```

`UsageClient::fetch($accessToken, $io, $accountId)` accepts an optional account ID as its third argument. Existing two-argument calls remain supported. `UsageResponse::rateLimitResetCredits` is null when no count was supplied; otherwise its `availableCount` property holds the count.

## Flow

1. Run the `login` command.
2. Open `https://auth.openai.com/codex/device` in any browser.
3. Enter the displayed one-time code.
4. Wait for authorization to complete.
5. The CLI writes `./auth.json` unless `--auth-file` is provided.

All five commands support `--auth-file` and `-a`. The default path is `./auth.json`. In a source checkout, use `php bin/open-ai-device-auth` instead of `vendor/bin/open-ai-device-auth`.

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
