/**
 * Membuat narasi suara, satu berkas mp3 per adegan, ke public/vo/.
 *
 * Dua mesin tersedia:
 *
 *   edge (bawaan) — Microsoft Edge TTS lewat paket Python edge-tts.
 *                   Gratis, tanpa API key. Suara: id-ID-ArdiNeural (laki-laki).
 *                   Pasang sekali: python -m pip install --user edge-tts
 *
 *   elevenlabs    — butuh ELEVENLABS_API_KEY di environment atau .env.local,
 *                   dan voiceId lewat --voice.
 *
 * Contoh:
 *   node scripts/voiceover.mjs
 *   node scripts/voiceover.mjs --rate +8%
 *   node scripts/voiceover.mjs --engine elevenlabs --voice <voice_id>
 */

import { execFile } from "node:child_process";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { promisify } from "node:util";
import { NARRATION } from "./narration.mjs";

const run = promisify(execFile);
const OUT = path.resolve("public/vo");

const args = process.argv.slice(2);
const flag = (name, fallback) => {
  const i = args.indexOf(`--${name}`);
  return i !== -1 ? args[i + 1] : fallback;
};

const ENGINE = flag("engine", "edge");
const RATE = flag("rate", "+0%");
const EDGE_VOICE = flag("edge-voice", "id-ID-ArdiNeural");

async function loadLocalEnv() {
  try {
    const raw = await readFile(path.resolve(".env.local"), "utf8");
    for (const line of raw.split(/\r?\n/)) {
      const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
      if (m && !process.env[m[1]]) {
        process.env[m[1]] = m[2].replace(/^["']|["']$/g, "");
      }
    }
  } catch {
    // Tidak ada .env.local — lanjut dengan environment apa adanya.
  }
}

/**
 * Durasi dibaca dari berkas subtitle yang ditulis edge-tts: stempel waktu
 * terakhir menandai akhir ucapan. Dipakai untuk memastikan tiap potongan
 * muat di jatah adegannya.
 */
async function readDuration(vttPath) {
  try {
    const vtt = await readFile(vttPath, "utf8");
    // edge-tts menulis milidetik dengan koma (gaya SRT), bukan titik.
    const stamps = [...vtt.matchAll(/(\d{2}):(\d{2}):(\d{2})[.,](\d{3})/g)];
    if (!stamps.length) return null;
    const [, h, m, s, ms] = stamps[stamps.length - 1];
    return Number(h) * 3600 + Number(m) * 60 + Number(s) + Number(ms) / 1000;
  } catch {
    return null;
  }
}

async function synthEdge(item) {
  const mp3 = path.join(OUT, `${item.name}.mp3`);
  const vtt = path.join(OUT, `${item.name}.vtt`);

  await run("python", [
    "-m",
    "edge_tts",
    "--voice",
    EDGE_VOICE,
    "--rate",
    RATE,
    "--text",
    item.text,
    "--write-media",
    mp3,
    "--write-subtitles",
    vtt,
  ]);

  return readDuration(vtt);
}

async function synthElevenLabs(item) {
  const apiKey = process.env.ELEVENLABS_API_KEY;
  const voiceId = flag("voice", process.env.ELEVENLABS_VOICE_ID);
  if (!apiKey || !voiceId) {
    throw new Error("Butuh ELEVENLABS_API_KEY dan --voice <voice_id>.");
  }

  const res = await fetch(`https://api.elevenlabs.io/v1/text-to-speech/${voiceId}`, {
    method: "POST",
    headers: {
      "xi-api-key": apiKey,
      "Content-Type": "application/json",
      Accept: "audio/mpeg",
    },
    body: JSON.stringify({
      text: item.text,
      model_id: process.env.ELEVENLABS_MODEL ?? "eleven_multilingual_v2",
      voice_settings: {
        stability: 0.5,
        similarity_boost: 0.8,
        style: 0.15,
        use_speaker_boost: true,
      },
    }),
  });

  if (!res.ok) throw new Error(`${item.name}: ${res.status} ${await res.text()}`);
  await writeFile(path.join(OUT, `${item.name}.mp3`), Buffer.from(await res.arrayBuffer()));
  return null;
}

await loadLocalEnv();
await mkdir(OUT, { recursive: true });

const synth = ENGINE === "elevenlabs" ? synthElevenLabs : synthEdge;
console.log(
  ENGINE === "edge" ? `Mesin edge-tts, suara ${EDGE_VOICE}, rate ${RATE}:` : "Mesin ElevenLabs:",
);

let overflow = 0;
for (const item of NARRATION) {
  const seconds = await synth(item);
  if (seconds === null) {
    console.log(`  ${item.name}.mp3`);
    continue;
  }
  const fits = seconds <= item.seconds;
  if (!fits) overflow++;
  console.log(
    `  ${item.name}.mp3  ${seconds.toFixed(1)}s / jatah ${item.seconds}s  ${fits ? "muat" : "KEPANJANGAN"}`,
  );
}

console.log(`\nSelesai. ${NARRATION.length} berkas di public/vo/`);
if (overflow > 0) {
  console.log(`${overflow} potongan kepanjangan — ulangi dengan --rate +10% atau lebih.`);
}
