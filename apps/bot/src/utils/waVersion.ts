import {
  fetchLatestWaWebVersion,
  type WAVersion,
} from "@whiskeysockets/baileys";
import { withOperationTimeout } from "./operationTimeout.js";

type VersionFetcher = (
  options?: RequestInit
) => ReturnType<typeof fetchLatestWaWebVersion>;

export interface WaVersionResolution {
  version?: WAVersion;
  isLatest: boolean;
  source: "REMOTE" | "PACKAGE_DEFAULT";
  error?: string;
}

function positiveInteger(value: number, fallback: number): number {
  return Number.isSafeInteger(value) && value > 0 ? value : fallback;
}

function errorMessage(error: unknown, depth = 0): string {
  if (!error || typeof error !== "object") return String(error);
  const detail = error as {
    message?: unknown;
    code?: unknown;
    cause?: unknown;
    output?: { statusCode?: unknown };
  };
  const message = typeof detail.message === "string" ? detail.message : "Unknown version fetch error";
  const code = typeof detail.code === "string" ? ` [${detail.code}]` : "";
  const status = typeof detail.output?.statusCode === "number" ? ` HTTP ${detail.output.statusCode}` : "";
  const cause = detail.cause && depth < 2 ? `: ${errorMessage(detail.cause, depth + 1)}` : "";
  return `${message}${code}${status}${cause}`;
}

export async function resolveWaWebVersion(
  fetcher: VersionFetcher = fetchLatestWaWebVersion,
  timeoutMs = Number(process.env.WA_VERSION_FETCH_TIMEOUT_MS || 5000)
): Promise<WaVersionResolution> {
  const timeout = positiveInteger(timeoutMs, 5000);
  let lastError = "";
  // Only retry this read-only version lookup, never pairing or message sends.
  for (let attempt = 1; attempt <= 2; attempt++) {
    try {
      const result = await withOperationTimeout(
        (signal) => fetcher({ signal }), timeout, "Pengambilan versi WhatsApp Web"
      );
      if (result.error) {
        throw result.error;
      }
      if (
        !Array.isArray(result.version) ||
        result.version.length !== 3 ||
        result.version.some((part) => !Number.isSafeInteger(part) || part < 0)
      ) {
        throw new Error("Versi WhatsApp Web dari Baileys tidak valid");
      }

      return {
        version: [...result.version] as WAVersion,
        isLatest: result.isLatest,
        source: "REMOTE",
      };
    } catch (error) {
      lastError = errorMessage(error);
      console.warn(`[WA VERSION] attempt=${attempt}/2 gagal: ${lastError}`);
    }
  }
  return { isLatest: false, source: "PACKAGE_DEFAULT", error: lastError };
}

export function getRejectedConnectionDelay(
  attempt: number,
  baseDelayMs = Number(process.env.WA_RECONNECT_405_BASE_DELAY_MS || 15000),
  maximumDelayMs = Number(process.env.WA_RECONNECT_405_MAX_DELAY_MS || 300000)
): number {
  const normalizedAttempt = Math.max(1, Math.floor(attempt));
  const base = positiveInteger(baseDelayMs, 15000);
  const maximum = Math.max(base, positiveInteger(maximumDelayMs, 300000));
  return Math.min(maximum, base * 2 ** Math.min(normalizedAttempt - 1, 10));
}
