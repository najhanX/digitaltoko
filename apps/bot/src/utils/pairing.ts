import QRCode from "qrcode";
import { Browsers, type AuthenticationCreds } from "@whiskeysockets/baileys";

export function pairingBrowser(method: "qr" | "code"): ReturnType<typeof Browsers.macOS> {
  return Browsers.macOS(method === "code" ? "Chrome" : "Google Chrome");
}

type PairingCredentials = Pick<AuthenticationCreds, "registered" | "me" | "pairingCode" | "account">;

/** QR pair-success supplies a signed account before registered becomes true. */
export function hasPairedIdentity(credentials: PairingCredentials): boolean {
  return credentials.registered || Boolean(credentials.me?.id && credentials.account?.accountSignatureKey?.length
    && credentials.account.accountSignature?.length && credentials.account.deviceSignature?.length);
}

/** Clear abandoned code requests while keeping identities accepted by WhatsApp. */
export function clearIncompletePairingState(credentials: PairingCredentials): boolean {
  if (hasPairedIdentity(credentials)) return false;
  const incomplete = Boolean(credentials.me || credentials.pairingCode);
  if (incomplete) {
    credentials.me = undefined;
    credentials.pairingCode = undefined;
  }
  return incomplete;
}

export type BotState = {
  status: string;
  qr_image?: string;
  pairing_code?: string;
  expires_at?: string;
  phone?: string;
  error?: string;
};

export type PairingFailure = {
  reason: "TIMEOUT" | "CONNECTION_CLOSED" | "REQUEST_REJECTED" | "UNKNOWN";
  status_code?: number;
};

/** Deliberately excludes error messages, phone numbers, codes and transport payloads. */
export function pairingFailure(error: unknown): PairingFailure {
  const failure = error as {name?: string; output?: {statusCode?: unknown}} | null;
  const status = failure?.output?.statusCode;
  const status_code = typeof status === "number" && Number.isInteger(status) && status >= 100 && status <= 599 ? status : undefined;
  const reason = failure?.name === "OperationTimeoutError" || status_code === 408 ? "TIMEOUT"
    : status_code === 428 ? "CONNECTION_CLOSED"
    : status_code ? "REQUEST_REJECTED" : "UNKNOWN";
  return {reason, ...(status_code ? {status_code} : {})};
}

export function linkedPhone(me: { id?: string; phoneNumber?: string } | undefined): string | undefined {
  const jid = me?.phoneNumber ?? me?.id;
  if (!jid || !jid.includes("@s.whatsapp.net")) return undefined;
  return jid.split("@")[0]?.split(":")[0];
}

/** Socket-local coordinator: no duplicate code requests, no late secrets after close/open. */
export function createPairing(options: {
  method: "qr" | "code";
  phone?: string;
  requestCode: (phone: string) => Promise<string>;
  publish: (state: BotState) => Promise<void>;
  fail: (failure: PairingFailure) => Promise<void>;
  now?: () => number;
}) {
  let closed = false, requested = false, qrSequence = 0;
  const now = options.now ?? Date.now;
  return {
    close() { closed = true; qrSequence++; },
    async update(update: { qr?: string; connection?: string }, registered: boolean) {
      if (closed || registered) return;
      if (options.method === "code") {
        // Baileys rc14 emits connecting on nextTick, before the Noise handshake.
        // QR means the server has supplied pairing refs and the encrypted socket is ready.
        if (requested || !update.qr) return;
        requested = true;
        try {
          if (!options.phone) throw new Error("Phone required");
          const code = await options.requestCode(options.phone);
          if (!/^[A-Z0-9]{8}$/.test(code)) throw new Error("Invalid pairing code");
          if (!closed) await options.publish({status: "PAIRING", pairing_code: code, expires_at: new Date(now() + 120_000).toISOString()});
        } catch (error) {
          if (!closed) { closed = true; await options.fail(pairingFailure(error)); }
        }
      } else if (update.qr) {
        const sequence = ++qrSequence;
        try {
          const qr_image = await QRCode.toDataURL(update.qr, {width: 320, margin: 3, errorCorrectionLevel: "M"});
          if (!closed && sequence === qrSequence) await options.publish({status: "PAIRING", qr_image, expires_at: new Date(now() + 60_000).toISOString()});
        } catch (error) {
          if (!closed) { closed = true; await options.fail(pairingFailure(error)); }
        }
      }
    },
  };
}
