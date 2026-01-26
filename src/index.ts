#!/usr/bin/env node
/**
 * OpenCode OpenAI Device Code Authentication
 *
 * Authenticates ChatGPT Plus/Pro using Device Code flow for headless environments.
 * Tokens are saved to OpenCode's auth file (~/.local/share/opencode/auth.json).
 *
 * Usage:
 *   npx opencode-openai-device-auth
 *
 * Based on OpenAI Codex CLI's device code implementation:
 * https://github.com/openai/codex/blob/main/codex-rs/login/src/device_code_auth.rs
 */

import { writeFileSync, readFileSync, mkdirSync, existsSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

const CLIENT_ID = "app_EMoamEEZ73f0CkXaXp7hrann";
const BASE_URL = "https://auth.openai.com";
const API_BASE_URL = `${BASE_URL}/api/accounts`;

interface UserCodeResponse {
  device_auth_id: string;
  user_code?: string;
  usercode?: string;
  interval: string | number;
}

interface CodeSuccessResponse {
  authorization_code: string;
  code_challenge: string;
  code_verifier: string;
}

interface TokenResponse {
  access_token: string;
  refresh_token: string;
  id_token: string;
  expires_in: number;
}

async function requestUserCode(): Promise<UserCodeResponse | null> {
  const url = `${API_BASE_URL}/deviceauth/usercode`;

  try {
    const response = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "User-Agent": "opencode-openai-device-auth/1.0.0",
        Accept: "application/json",
      },
      body: JSON.stringify({
        client_id: CLIENT_ID,
      }),
    });

    if (!response.ok) {
      const text = await response.text();
      console.error(`Failed to request user code: ${response.status}`);
      console.error(`Response: ${text}`);

      if (response.status === 404) {
        console.error("\nDevice code login is not enabled for this server.");
        console.error("Please enable it in your ChatGPT security settings:");
        console.error("  https://chatgpt.com/settings/security");
      }
      return null;
    }

    return (await response.json()) as UserCodeResponse;
  } catch (error) {
    console.error("Error requesting user code:", error);
    return null;
  }
}

async function pollForCode(
  deviceAuthId: string,
  userCode: string,
  intervalSec: number
): Promise<CodeSuccessResponse | null> {
  const url = `${API_BASE_URL}/deviceauth/token`;
  const maxWaitMs = 15 * 60 * 1000; // 15 minutes
  const startTime = Date.now();

  while (Date.now() - startTime < maxWaitMs) {
    await new Promise((resolve) => setTimeout(resolve, intervalSec * 1000));

    try {
      const response = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "User-Agent": "opencode-openai-device-auth/1.0.0",
          Accept: "application/json",
        },
        body: JSON.stringify({
          device_auth_id: deviceAuthId,
          user_code: userCode,
        }),
      });

      if (response.ok) {
        return (await response.json()) as CodeSuccessResponse;
      }

      // 403 or 404 means authorization is still pending
      if (response.status === 403 || response.status === 404) {
        process.stdout.write(".");
        continue;
      }

      const text = await response.text();
      console.error(`\nPolling failed: ${response.status} - ${text}`);
      return null;
    } catch {
      process.stdout.write("!");
      // Network error, continue polling
    }
  }

  console.error("\nTimeout after 15 minutes.");
  return null;
}

async function exchangeCodeForTokens(
  authorizationCode: string,
  codeVerifier: string
): Promise<TokenResponse | null> {
  const url = `${BASE_URL}/oauth/token`;
  const redirectUri = `${BASE_URL}/deviceauth/callback`;

  try {
    const response = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: new URLSearchParams({
        grant_type: "authorization_code",
        client_id: CLIENT_ID,
        code: authorizationCode,
        code_verifier: codeVerifier,
        redirect_uri: redirectUri,
      }).toString(),
    });

    if (!response.ok) {
      const text = await response.text();
      console.error(`Token exchange failed: ${response.status} - ${text}`);
      return null;
    }

    return (await response.json()) as TokenResponse;
  } catch (error) {
    console.error("Error exchanging code for tokens:", error);
    return null;
  }
}

function saveTokens(tokens: TokenResponse): void {
  // OpenCode stores all provider credentials in ~/.local/share/opencode/auth.json
  const authDir = join(homedir(), ".local", "share", "opencode");
  const authFile = join(authDir, "auth.json");

  if (!existsSync(authDir)) {
    mkdirSync(authDir, { recursive: true });
  }

  // Load existing auth data to preserve other providers
  let existingAuth: Record<string, unknown> = {};
  if (existsSync(authFile)) {
    try {
      existingAuth = JSON.parse(readFileSync(authFile, "utf-8"));
    } catch {
      // If file is corrupted, start fresh
    }
  }

  // Add/update OpenAI credentials
  existingAuth.openai = {
    type: "oauth",
    access: tokens.access_token,
    refresh: tokens.refresh_token,
    expires: Date.now() + tokens.expires_in * 1000,
  };

  writeFileSync(authFile, JSON.stringify(existingAuth, null, 2));
  console.log(`\nTokens saved to ${authFile}`);
}

async function main(): Promise<void> {
  console.log("=== OpenCode OpenAI Device Code Authentication ===\n");
  console.log("Requesting device code...\n");

  const userCodeResp = await requestUserCode();
  if (!userCodeResp) {
    process.exit(1);
  }

  const userCode = userCodeResp.user_code || userCodeResp.usercode;
  if (!userCode) {
    console.error("No user code in response:", userCodeResp);
    process.exit(1);
  }

  const interval =
    typeof userCodeResp.interval === "string"
      ? parseInt(userCodeResp.interval, 10)
      : userCodeResp.interval;

  const verificationUrl = `${BASE_URL}/codex/device`;

  console.log("Follow these steps to sign in:\n");
  console.log(`1. Open this link in your browser:`);
  console.log(`   \x1b[94m${verificationUrl}\x1b[0m\n`);
  console.log(
    `2. Enter this one-time code \x1b[90m(expires in 15 minutes)\x1b[0m`
  );
  console.log(`   \x1b[94m${userCode}\x1b[0m\n`);
  console.log(
    "\x1b[90mDevice codes are a common phishing target. Never share this code.\x1b[0m\n"
  );
  console.log("Waiting for authorization...");

  const codeResp = await pollForCode(
    userCodeResp.device_auth_id,
    userCode,
    interval || 5
  );
  if (!codeResp) {
    process.exit(1);
  }

  console.log("\n\nAuthorization received! Exchanging for tokens...");

  const tokens = await exchangeCodeForTokens(
    codeResp.authorization_code,
    codeResp.code_verifier
  );

  if (!tokens) {
    process.exit(1);
  }

  console.log("\n✓ Authentication successful!");
  saveTokens(tokens);

  console.log("\nYou can now use OpenCode with your ChatGPT subscription:");
  console.log('  opencode run "hello" --model=openai/gpt-5.1-codex');
}

main().catch(console.error);
