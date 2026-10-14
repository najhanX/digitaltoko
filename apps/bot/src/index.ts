import express from "express";
import pino from "pino";
import makeWASocket, { DisconnectReason, useMultiFileAuthState } from "@whiskeysockets/baileys";
import { timingSafeEqual } from "node:crypto";
import { mkdir, open, readFile, unlink } from "node:fs/promises";
import { join } from "node:path";
import { loadConfig } from "./config.js";
import { MenuTokens, type MenuAction } from "./menu.js";
import { sendMenu, sendControls } from "./interactive.js";
import { catalogQuery, catalogPage, catalogText } from "./catalog.js";
import {
  createAtomicCredentialWriter,
  createCredentialSaver,
  createCredentialUpdateHandler,
} from "./utils/credentialPersistence.js";
import { resolveWaWebVersion, getRejectedConnectionDelay } from "./utils/waVersion.js";
import { createPairing, linkedPhone, pairingBrowser, clearIncompletePairingState, type BotState } from "./utils/pairing.js";
import { withOperationTimeout } from "./utils/operationTimeout.js";

const config = loadConfig();
const logger = pino({ level: process.env.LOG_LEVEL ?? "info", redact: ["token", "qr", "qr_image", "pairing_code", "creds", "message"] });
const tokens = new MenuTokens();
let state: BotState = { status: "STOPPED" };
let socket: ReturnType<typeof makeWASocket> | undefined;
let reconnect: ReturnType<typeof setTimeout> | undefined;
let starting = false,
  stopping = false,
  failures = 0;
let lockPath: string | undefined;
let pairingTimer: ReturnType<typeof setTimeout> | undefined;
let halt = false, paused = false;
let reports = Promise.resolve();

async function api(path: string, body?: unknown) {
  const response = await fetch(new URL(path, config.webBaseUrl), {
    method: body ? "POST" : "GET",
    headers: {
      Authorization: `Bearer ${config.token}`,
      Accept: "application/json",
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
    signal: AbortSignal.timeout(5000),
  });
  if (!response.ok) throw new Error(`Backend HTTP ${response.status}`);
  return response.json();
}
async function report(next: typeof state) {
  state = next;
  const payload = {...next, ...(config.connectionId ? {connection_id: config.connectionId} : {})};
  reports = reports.then(async () => {
    try { await api("/api/internal/bot/events", payload); }
    catch { logger.warn("Status belum diterima backend; credential tidak dicetak."); }
  });
  await reports;
}
async function startSocket() {
  if (starting || stopping || halt) return;
  starting = true;
  try {
    const access = await api("/api/internal/bot/access");
    if (stopping) return;
    if (!access.data.can_connect) {
      paused = true;
      await report({ status: "STOPPED" });
      return;
    }
    if (config.connectionId && access.data.connection_id !== config.connectionId) {
      await shutdown();
      return;
    }
    paused = false;
    const { state: auth } = await useMultiFileAuthState(config.sessionPath);
    if (stopping) return;
    const save = createCredentialSaver(createAtomicCredentialWriter(config.sessionPath, () => auth.creds));
    if (clearIncompletePairingState(auth.creds)) {
      await save();
      logger.info({store_id: config.storeId}, "State pairing yang belum selesai dipulihkan.");
    }
    if (auth.creds.registered && config.phone && linkedPhone(auth.creds.me) !== config.phone) {
      halt = true;
      await report({status: "ERROR", error: "PHONE_MISMATCH"});
      return;
    }
    const version = await resolveWaWebVersion();
    if (stopping) return;
    const browser = pairingBrowser(config.method);
    logger.info({store_id: config.storeId, method: config.method, browser: browser[1]}, "Identitas koneksi WhatsApp disiapkan.");
    const sock = makeWASocket({
      auth,
      logger: pino({ level: "silent" }),
      browser,
      ...(version.version ? { version: version.version } : {}),
      markOnlineOnConnect: false,
      syncFullHistory: false,
    });
    socket = sock;
    const fail = async (error: string) => {
      halt = true;
      pairing.close();
      clearTimeout(pairingTimer);
      clearTimeout(reconnect);
      sock.end(new Error(error));
      await report({status: "ERROR", error});
    };
    const pairing = createPairing({method: config.method, phone: config.phone,
      requestCode: phone => withOperationTimeout(() => sock.requestPairingCode(phone), 60_000, "Pairing"),
      publish: async next => {
        if (socket !== sock || stopping || halt || paused) return;
        clearTimeout(pairingTimer);
        pairingTimer = setTimeout(() => {
          if (socket !== sock || state.status !== "PAIRING") return;
          if (config.method === "code") {halt = true;pairing.close();sock.end(new Error("Pairing expired"));}
          void report({status: "EXPIRED"});
        }, Math.max(1, new Date(next.expires_at!).getTime() - Date.now()));
        await report(next);
      }, fail: async diagnostic => {
        logger.warn({store_id: config.storeId, method: config.method, ...diagnostic}, "Permintaan pairing gagal.");
        await fail("PAIRING_FAILED");
      }});
    if (!auth.creds.registered) {
      clearTimeout(pairingTimer);
      pairingTimer = setTimeout(() => {
        if (socket !== sock || state.status === "CONNECTED") return;
        halt = true;pairing.close();sock.end(new Error("Pairing expired"));
        void report({status: "EXPIRED"});
      }, 120_000);
    }
    sock.ev.on(
      "creds.update",
      createCredentialUpdateHandler(save, () => {
        void fail("CREDENTIAL_FAILED");
      }),
    );
    sock.ev.on("connection.update", (update) => {
      void (async () => {
      if (socket !== sock || stopping || halt || paused) return;
      if (update.connection === "open") {
        pairing.close();
        clearTimeout(pairingTimer);
        const phone = linkedPhone(sock.user ?? auth.creds.me);
        if (config.phone && phone !== config.phone) {
          halt = true;
          await withOperationTimeout(() => sock.logout(), 3000, "Logout").catch(() => {});
          await report({status: "ERROR", error: "PHONE_MISMATCH"});
          return;
        }
        failures = 0;
        await report({ status: "CONNECTED", ...(phone ? {phone} : {}) });
      }
      if (update.connection === "close") {
        pairing.close();
        clearTimeout(pairingTimer);
        const code = (update.lastDisconnect?.error as { output?: { statusCode?: number } } | undefined)?.output
          ?.statusCode;
        if (code === DisconnectReason.loggedOut) {halt = true;await report({status: "LOGGED_OUT"});return;}
        await report({ status: "DISCONNECTED" });
        if (stopping || halt) return;
        if (++failures > 8) {await fail("CONNECTION_FAILED");return;}
        clearTimeout(reconnect);
        reconnect = setTimeout(() => {
          void startSocket().catch(() => fail("CONNECTION_FAILED"));
        }, getRejectedConnectionDelay(failures));
      }
      else await pairing.update(update, auth.creds.registered);
      })().catch(() => fail("CONNECTION_FAILED"));
    });
    sock.ev.on("messages.upsert", async (batch) => {
      if (batch.type !== "notify" || stopping || halt || paused || state.status !== "CONNECTED") return;
      for (const message of batch.messages) {
        const chat = message.key.remoteJid;
        if (!chat || message.key.fromMe || chat === "status@broadcast" || chat.endsWith("@g.us")) continue;
        const sender = message.key.participant ?? chat;
        try {
          const access = await api("/api/internal/bot/access");
          if (!access.data.allowed) continue;
          const content = message.message;
          const text = (content?.conversation ?? content?.extendedTextMessage?.text ?? "").trim().toLowerCase();
          let action: MenuAction | null = null;
          const params = content?.interactiveResponseMessage?.nativeFlowResponseMessage?.paramsJson;
          if (params) {
            try {
              const value = JSON.parse(params);
              if (typeof value.id === "string") action = tokens.consume(value.id, chat, sender);
            } catch {}
          }
          if (text === "#menu" || text === "#start" || action === "menu") {
            await sendMenu(sock, message, chat, sender, tokens, access.data.store_name);
            continue;
          }
          const products=catalogQuery(text,action);
          if(products) {
            let data;
            try {
              const query=new URLSearchParams({page:String(products.page),q:products.search});
              data=catalogPage((await api("/api/internal/bot/products?"+query)).data);
            } catch {
              await sock.sendMessage(chat,{text:"Katalog belum dapat dibaca. Coba kembali beberapa saat lagi."},{quoted:message});
              continue;
            }
            await sock.sendMessage(chat,{text:catalogText(data,products)},{quoted:message});
            const controls:(readonly [MenuAction,string])[]=[];
            if(data.pagination.current_page>1)controls.push([{type:"products",page:data.pagination.current_page-1,search:products.search},"Sebelumnya"]);
            if(data.pagination.current_page<data.pagination.last_page)controls.push([{type:"products",page:data.pagination.current_page+1,search:products.search},"Berikutnya"]);
            controls.push(["menu","Menu toko"]);
            await sendControls(sock,message,chat,sender,tokens,controls,"Jelajahi katalog toko");
            continue;
          }
          action ??=
            text === "#produk" ? "products" : text === "#status" ? "status" : text === "#saldo" ? "balance" : null;
          if (action)
            await sock.sendMessage(
              chat,
              {
                text: "Status pesanan dan saldo pelanggan belum tersedia. Hubungi owner toko untuk bantuan.",
              },
              { quoted: message },
            );
        } catch {
          logger.warn("Pesan tidak diproses: backend/transport belum siap.");
        }
      }
    });
    if (state.status !== "CONNECTED" && state.status !== "PAIRING") await report({ status: "CONNECTING" });
  } finally {
    starting = false;
  }
}

const app = express();
app.get("/health", (_req, res) => res.json({ status: state.status, store_id: config.storeId }));
app.get("/state", (req, res) => {
  const actual = Buffer.from((req.headers.authorization ?? "").replace(/^Bearer /, ""));
  const expected = Buffer.from(config.token);
  if (actual.length !== expected.length || !timingSafeEqual(actual, expected)) {
    res.sendStatus(401);
    return;
  }
  res.json(state);
});
const server = app.listen(config.port, "127.0.0.1", async () => {
  logger.info({ store_id: config.storeId, port: config.port }, "Worker API siap pada loopback.");
  if (!config.waEnabled) return;
  try {
    await mkdir(config.sessionPath, { recursive: true, mode: 0o700 });
    const candidate = join(config.sessionPath, ".worker.lock");
    let file;
    try { file = await open(candidate, "wx", 0o600); }
    catch (error) {
      if ((error as NodeJS.ErrnoException).code !== "EEXIST") throw error;
      const pid = Number(await readFile(candidate, "utf8"));
      if (!Number.isInteger(pid) || pid < 1) throw new Error("Invalid lock");
      try {process.kill(pid, 0);throw new Error("Session is in use");}
      catch (check) {if ((check as NodeJS.ErrnoException).code !== "ESRCH") throw check;}
      await unlink(candidate);
      file = await open(candidate, "wx", 0o600);
    }
    lockPath = candidate;
    await file.writeFile(String(process.pid));
    await file.close();
    await startSocket();
  } catch {
    logger.error("Worker WA gagal start atau sesi dikunci proses lain.");
    await shutdown();
    process.exitCode = 1;
  }
});
const heartbeat = setInterval(async () => {
  if (!config.waEnabled || stopping || starting) return;
  try {
    const access = await api("/api/internal/bot/access");
    if (stopping) return;
    if (!access.data.can_connect) {
      paused = true;
      clearTimeout(reconnect);
      clearTimeout(pairingTimer);
      socket?.end(new Error("Store access ended"));
      await report({ status: "STOPPED" });
    } else if (paused) {paused = false;await startSocket();}
    else await report(state.status === "PAIRING" && state.expires_at && Date.now() >= new Date(state.expires_at).getTime() ? {status:"EXPIRED"} : state);
  } catch {
    logger.warn("Heartbeat backend belum tersedia.");
  }
}, 30_000);
async function shutdown() {
  stopping = true;
  clearTimeout(reconnect);
  clearTimeout(pairingTimer);
  clearInterval(heartbeat);
  socket?.end(new Error("Worker shutdown"));
  server.close();
  const ownedLock = lockPath;
  lockPath = undefined;
  if (ownedLock) await unlink(ownedLock).catch(() => {});
  if (process.connected) process.disconnect();
}
process.on("SIGINT", () => {
  void shutdown();
});
process.on("SIGTERM", () => {
  void shutdown();
});
process.on("disconnect", () => {void shutdown();});
process.on("message", message => {
  if ((message as {type?: string})?.type !== "logout") return;
  stopping = true;
  clearTimeout(reconnect);
  clearTimeout(pairingTimer);
  void (async () => {
    if (socket) await withOperationTimeout(() => socket!.logout(), 3000, "Logout").catch(() => {});
    await shutdown();
    if (process.connected) process.disconnect();
  })();
});
