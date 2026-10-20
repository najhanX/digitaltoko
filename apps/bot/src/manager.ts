import "dotenv/config";
import express from "express";
import QRCode from "qrcode";
import { timingSafeEqual, randomUUID } from "node:crypto";
import { spawn, type ChildProcess } from "node:child_process";
import { mkdir, readFile, rename, rm, writeFile, unlink } from "node:fs/promises";
import { resolve, dirname, sep } from "node:path";
import { fileURLToPath } from "node:url";
import { sendDirectMessage } from "./direct-message.js";

const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
type Entry = {store_id: string; connection_id: string; phone: string; method: "qr" | "code"; worker_token: string};
const token = process.env.BOT_MANAGER_TOKEN ?? "";
if (token.length < 32) throw new Error("BOT_MANAGER_TOKEN minimal 32 karakter.");
const port = Number(process.env.MANAGER_PORT ?? 3099);
if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error("MANAGER_PORT tidak valid.");
const webUrl = new URL(process.env.WEB_BASE_URL ?? "http://127.0.0.1:8000");
if (!["http:", "https:"].includes(webUrl.protocol) || webUrl.username || webUrl.password) throw new Error("WEB_BASE_URL tidak valid.");
const maxWorkers = Number(process.env.MANAGER_MAX_WORKERS ?? 100);
if (!Number.isInteger(maxWorkers) || maxWorkers < 1) throw new Error("MANAGER_MAX_WORKERS tidak valid.");
const sessionRoot = resolve(process.env.SESSION_ROOT ?? "sessions");
const registry = resolve(sessionRoot, ".manager.json");
const workerPath = resolve(dirname(fileURLToPath(import.meta.url)), "index.js");
const entries = new Map<string, Entry>();
const children = new Map<string, ChildProcess>();
const retries = new Map<string, number>();
const timers = new Map<string, ReturnType<typeof setTimeout>>();
let closing = false;
let queue = Promise.resolve();
function serial<T>(action: () => Promise<T>): Promise<T> {
  const next = queue.then(action);
  queue = next.then(() => {}, () => {});
  return next;
}
function valid(value: unknown): value is Entry {
  const item = value as Entry | undefined;
  return !!item && uuid.test(item.store_id) && uuid.test(item.connection_id) && /^[1-9][0-9]{7,14}$/.test(item.phone)
    && ["qr", "code"].includes(item.method) && typeof item.worker_token === "string" && /^[0-9a-f]{64}$/.test(item.worker_token);
}
async function persist() {
  const temporary = `${registry}.${randomUUID()}.tmp`;
  try {
    await writeFile(temporary, JSON.stringify([...entries.values()]), {flag: "wx", mode: 0o600, flush: true});
    await rename(temporary, registry);
  } finally { await unlink(temporary).catch(() => {}); }
}
async function clearSession(storeId: string) {
  if (!uuid.test(storeId)) throw new Error("Invalid store");
  const target = resolve(sessionRoot, storeId);
  if (!target.startsWith(sessionRoot + sep) || dirname(target) !== sessionRoot) throw new Error("Invalid session path");
  await rm(target, {recursive: true, force: true});
}
async function start(entry: Entry) {
  if (closing || children.has(entry.store_id)) return;
  const child = spawn(process.execPath, [workerPath], {
    shell: false, windowsHide: true, stdio: ["ignore", "inherit", "inherit", "ipc"],
    env: {...process.env, STORE_ID: entry.store_id, WORKER_TOKEN: entry.worker_token, PORT: "0", WA_ENABLED: "true",
      WEB_BASE_URL: webUrl.href, SESSION_ROOT: sessionRoot, BOT_PHONE: entry.phone, PAIRING_METHOD: entry.method,
      CONNECTION_ID: entry.connection_id, BOT_MANAGER_TOKEN: ""},
  });
  children.set(entry.store_id, child);
  child.on("exit", code => {
    if (children.get(entry.store_id) !== child) return;
    children.delete(entry.store_id);
    if (closing || code === 0 || entries.get(entry.store_id)?.connection_id !== entry.connection_id) return;
    const failures = (retries.get(entry.store_id) ?? 0) + 1;
    retries.set(entry.store_id, failures);
    if (failures > 5) return;
    timers.set(entry.store_id, setTimeout(() => {
      timers.delete(entry.store_id);
      if (entries.get(entry.store_id)?.connection_id === entry.connection_id) void start(entry).catch(() => {});
    }, Math.min(60_000, failures * 10_000)));
  });
  await new Promise<void>((done, reject) => { child.once("spawn", done); child.once("error", reject); });
}
async function stop(storeId: string, logout = false) {
  clearTimeout(timers.get(storeId));
  timers.delete(storeId);
  const child = children.get(storeId);
  children.delete(storeId);
  if (!child || child.exitCode !== null || child.signalCode !== null) return;
  await new Promise<void>((done, reject) => {
    const force = setTimeout(() => { child.kill("SIGKILL"); }, 5000);
    const deadline = setTimeout(() => { clearTimeout(force); reject(new Error("Worker did not stop")); }, 6000);
    child.once("exit", () => { clearTimeout(force); clearTimeout(deadline); done(); });
    if (logout && child.connected) child.send({type: "logout"}, () => {});
    else child.kill("SIGTERM");
  });
}

await mkdir(sessionRoot, {recursive: true, mode: 0o700});
try {
  const saved: unknown = JSON.parse(await readFile(registry, "utf8"));
  if (!Array.isArray(saved) || !saved.every(valid)) throw new Error("Invalid registry");
  for (const entry of saved) entries.set(entry.store_id, entry);
} catch (error) {
  if ((error as NodeJS.ErrnoException).code !== "ENOENT") throw new Error("Registry bot tidak valid; periksa file sesi secara lokal.");
}
const app = express();
app.disable("x-powered-by");
app.use(express.json({limit: "8kb"}));
app.use((req, res, next) => {
  const received = Buffer.from((req.headers.authorization ?? "").replace(/^Bearer /, ""));
  const expected = Buffer.from(token);
  if (received.length !== expected.length || !timingSafeEqual(received, expected)) {res.sendStatus(401); return;}
  next();
});
app.post("/qr", async (req,res)=>{
  const payload=req.body?.payload;
  if(typeof payload!=="string" || payload.length<8 || payload.length>4096){res.sendStatus(422);return;}
  try {res.json({status:"SUCCESS",data:await QRCode.toDataURL(payload,{errorCorrectionLevel:"M",width:360,margin:2})});}
  catch {res.status(422).json({status:"FAILED",message:"Payload QR tidak valid."});}
});
app.get("/health", (_req, res) => res.json({status: "SUCCESS", workers: children.size}));
app.post("/stores/:store/message", async (req,res)=>{
  if(!uuid.test(String(req.params.store)) || !/^62[1-9][0-9]{7,12}$/.test(req.body?.phone) || typeof req.body?.message!=="string" || !req.body.message.length || req.body.message.length>6000){res.sendStatus(422);return;}
  const child=children.get(String(req.params.store));
  if(closing || !child?.connected || child.exitCode!==null){res.sendStatus(409);return;}
  try {await sendDirectMessage(child,req.body.phone,req.body.message);res.json({status:"SUCCESS"});}
  catch {res.status(503).json({status:"FAILED"});}
});
app.post("/stores/:store/notifications", (req,res)=>{
  if(!uuid.test(String(req.params.store))){res.sendStatus(422);return;}
  const child=children.get(String(req.params.store));
  if(closing || !child?.connected || child.exitCode!==null){res.sendStatus(409);return;}
  child.send({type:"notifications"},error=>{if(error)res.sendStatus(503);else res.json({status:"SUCCESS"});});
});
app.post("/stores/:store/connect", async (req, res) => {
  const entry = {...req.body, store_id: req.params.store};
  if (!valid(entry)) {res.sendStatus(422);return;}
  try {
    await serial(async () => {
      if (closing) throw new Error("Closing");
      const old = entries.get(entry.store_id);
      if (old?.connection_id === entry.connection_id && children.has(entry.store_id)) return;
      if (!children.has(entry.store_id) && children.size >= maxWorkers) throw new Error("Capacity");
      await stop(entry.store_id, !!old && (old.phone !== entry.phone || old.method !== entry.method));
      if (old && (old.phone !== entry.phone || old.method !== entry.method)) await clearSession(entry.store_id);
      entries.set(entry.store_id, {store_id: entry.store_id, connection_id: entry.connection_id, phone: entry.phone, method: entry.method, worker_token: entry.worker_token});
      await persist();
      retries.delete(entry.store_id);
      await start(entry);
    });
    res.json({status: "SUCCESS"});
  } catch { res.status(503).json({status: "ERROR", message: "Worker belum dapat dimulai."}); }
});
app.post("/stores/:store/disconnect", async (req, res) => {
  const id = req.params.store;
  if (!uuid.test(id)) {res.sendStatus(422);return;}
  try {
    await serial(async () => {
      entries.delete(id);
      await persist();
      await stop(id, true);
      await clearSession(id);
    });
    res.json({status: "SUCCESS"});
  } catch {res.status(503).json({status: "ERROR"});}
});
const server = app.listen(port, "127.0.0.1", () => {
  console.info(`Bot manager siap di 127.0.0.1:${port}.`);
  for (const entry of [...entries.values()].slice(0, maxWorkers)) void start(entry).catch(() => {});
});
async function shutdown() {
  if (closing) return;
  closing = true;
  server.close();
  await serial(async () => {await Promise.all([...children.keys()].map(id => stop(id)));});
}
process.on("SIGINT", () => {void shutdown();});
process.on("SIGTERM", () => {void shutdown();});
