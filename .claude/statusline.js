#!/usr/bin/env node
// Status line para Claude Code: muestra costo total de la sesión,
// costo del turno actual (delta) y tokens del último turno.
// Recibe un JSON por stdin y escribe una línea por stdout.

const fs = require('fs');
const os = require('os');
const path = require('path');

let raw = '';
process.stdin.on('data', (c) => (raw += c));
process.stdin.on('end', () => {
  let d = {};
  try { d = JSON.parse(raw); } catch (_) { /* sin datos: línea mínima */ }

  const model = d.model?.display_name || 'Claude';
  const totalCost = Number(d.cost?.total_cost_usd ?? 0);

  // Delta del turno: guardamos el último total por sesión en un archivo temporal.
  const sessionId = d.session_id || 'default';
  const cacheFile = path.join(os.tmpdir(), `cc-cost-${sessionId}.txt`);
  let last = 0;
  try { last = Number(fs.readFileSync(cacheFile, 'utf8')) || 0; } catch (_) {}
  let delta = totalCost - last;
  if (delta < 0) delta = 0; // por si se reinicia el contador
  try { fs.writeFileSync(cacheFile, String(totalCost)); } catch (_) {}

  // Tokens del último turno (current_usage) y % de contexto usado.
  const u = d.context_window?.current_usage || {};
  const inTok = Number(u.input_tokens ?? 0);
  const outTok = Number(u.output_tokens ?? 0);
  const cacheRead = Number(u.cache_read_input_tokens ?? 0);
  const usedPct = d.context_window?.used_percentage;

  const fmtTok = (n) => (n >= 1000 ? (n / 1000).toFixed(1) + 'k' : String(n));

  const parts = [
    model,
    `💰 $${totalCost.toFixed(4)} total`,
    `+$${delta.toFixed(4)} turno`,
    `🧾 in ${fmtTok(inTok)} / out ${fmtTok(outTok)} (cache ${fmtTok(cacheRead)})`,
  ];
  if (usedPct != null) parts.push(`ctx ${usedPct}%`);

  process.stdout.write(parts.join('  |  '));
});
