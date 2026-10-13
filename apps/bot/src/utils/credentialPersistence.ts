import { setTimeout as wait } from "node:timers/promises";
import { randomUUID } from "node:crypto";
import { rename, unlink, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { BufferJSON } from "@whiskeysockets/baileys";

const TRANSIENT_FILE_ERRORS = new Set(["UNKNOWN", "EBUSY", "EPERM", "EACCES", "EAGAIN", "EMFILE", "ENFILE"]);

export function createAtomicCredentialWriter(
  directory: string,
  getCredentials: () => unknown,
  replaceFile: typeof rename = rename,
): () => Promise<void> {
  return async () => {
    const serialized = JSON.stringify(getCredentials(), BufferJSON.replacer);
    if (!serialized) throw new Error("Credential tidak tersedia untuk disimpan.");
    const temporaryPath = join(directory, `.creds-${randomUUID()}.tmp`);
    try {
      // Same-directory replacement preserves the previous JSON until the new file is complete.
      await writeFile(temporaryPath, serialized, { encoding: "utf8", flag: "wx", mode: 0o600, flush: true });
      await replaceFile(temporaryPath, join(directory, "creds.json"));
    } finally {
      await unlink(temporaryPath).catch((error: NodeJS.ErrnoException) => {
        if (error.code !== "ENOENT") console.warn(`[SESSION] File sementara tidak dapat dibersihkan (${error.code ?? "UNKNOWN"}).`);
      });
    }
  };
}

export function credentialErrorCode(error: unknown): string {
  return typeof error === "object" && error !== null && "code" in error
    ? String(error.code)
    : "UNKNOWN_ERROR";
}

export function createCredentialSaver(
  save: () => Promise<void>,
  options: {
    wait?: (milliseconds: number) => Promise<unknown>;
    onRetry?: (code: string, attempt: number) => void;
  } = {},
): () => Promise<void> {
  let queue: Promise<void> = Promise.resolve();
  const sleep = options.wait ?? wait;
  const retryDelays = [250, 750, 1500];

  return () => {
    // Keep updates ordered while a previous write is waiting for a Windows file lock.
    const pending = queue.then(async () => {
      for (let attempt = 0; ; attempt++) {
        try {
          await save();
          return;
        } catch (error) {
          const code = credentialErrorCode(error);
          if (!TRANSIENT_FILE_ERRORS.has(code) || attempt >= retryDelays.length) throw error;
          options.onRetry?.(code, attempt + 1);
          await sleep(retryDelays[attempt]);
        }
      }
    });
    // Expose this write's failure to its caller without poisoning the next queued update.
    queue = pending.catch(() => {});
    return pending;
  };
}

export function createCredentialUpdateHandler(
  save: () => Promise<void>,
  onFailure: (error: unknown) => void,
): () => Promise<void> {
  let failed = false;
  return async () => {
    if (failed) return;
    try {
      await save();
    } catch (error) {
      if (failed) return;
      failed = true;
      onFailure(error);
    }
  };
}
