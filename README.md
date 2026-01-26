# opencode-openai-device-auth

Device Code authentication for [OpenCode](https://opencode.ai) - authenticate ChatGPT Plus/Pro in headless environments (SSH, Docker, remote servers).

## Why?

OpenCode's built-in ChatGPT OAuth requires a browser on the same machine. This tool uses [Device Code flow](https://developers.openai.com/codex/auth/#preferred-device-code-authentication-beta) to authenticate from any browser, even on a different device.

## Prerequisites

Enable Device Code authentication in your ChatGPT account:

1. Go to [ChatGPT Codex Security Settings](https://chatgpt.com/codex/settings/general#settings/Security)
2. Turn on **"Enable device code authentication for Codex"**

## Usage

### One-time execution (recommended)

```bash
npx opencode-openai-device-auth
```

### Or install globally

```bash
npm install -g opencode-openai-device-auth
opencode-openai-device-auth
```

## How it works

1. Run the command - you'll get a URL and a one-time code
2. Open the URL in any browser (phone, laptop, etc.)
3. Enter the code and sign in with your ChatGPT account
4. Tokens are saved to `~/.local/share/opencode/auth.json`
5. Use OpenCode normally with ChatGPT Plus/Pro models

```
=== OpenCode OpenAI Device Code Authentication ===

Requesting device code...

Follow these steps to sign in:

1. Open this link in your browser:
   https://auth.openai.com/codex/device

2. Enter this one-time code (expires in 15 minutes)
   ABCD-12345

Waiting for authorization...
..........

✓ Authentication successful!
Tokens saved to /home/user/.local/share/opencode/auth.json

You can now use OpenCode with your ChatGPT subscription:
  opencode run "hello" --model=openai/gpt-5.1-codex
```

## Use Cases

- **SSH sessions** - Authenticate on remote servers
- **Docker containers** - No browser needed inside containers
- **CI/CD pipelines** - Automate authentication
- **Headless servers** - No GUI required

## Credits

Based on [OpenAI Codex CLI](https://github.com/openai/codex)'s device code implementation.

## License

MIT
