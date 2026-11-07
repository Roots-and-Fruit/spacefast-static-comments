#!/usr/bin/env node
/**
 * Stage the server-only Spacefast Zero comments capsule into a static export directory.
 *
 * Usage:
 *   node scripts/stage-capsule.mjs --target <EXPORT_DIR> [--name <SPACE_NAME>]
 *
 * Copies `sf.jsonc`, `server/`, and `shared/` from `capsule/` into `<EXPORT_DIR>/`
 * and verifies that `client/index.tsx` does NOT exist in `<EXPORT_DIR>/` (so Zero
 * stays server-only and never shadows the static WordPress `index.html`).
 */
import { cp, existsSync, readFile, rm, stat, writeFile } from "node:fs";
import { cp as cpAsync, readFile as readFileAsync, rm as rmAsync, stat as statAsync, writeFile as writeFileAsync } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const packageRoot = path.resolve(fileURLToPath(import.meta.url), "../..");
const capsuleRoot = path.join(packageRoot, "capsule");

function parseArgs(argv) {
  let target = "";
  let name = "";
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (arg === "--target" || arg === "-t") {
      target = argv[i + 1] ?? "";
      i += 1;
    } else if (arg.startsWith("--target=")) {
      target = arg.slice("--target=".length);
    } else if (arg === "--name" || arg === "-n") {
      name = argv[i + 1] ?? "";
      i += 1;
    } else if (arg.startsWith("--name=")) {
      name = arg.slice("--name=".length);
    } else if (!arg.startsWith("-") && !target) {
      target = arg;
    }
  }
  return { target, name };
}

export async function stageCapsule({ targetDir, spaceName = "" }) {
  const outDir = path.resolve(targetDir);
  const outStat = await statAsync(outDir).catch(() => null);
  if (!outStat?.isDirectory()) {
    throw new Error(`stage-capsule: target directory does not exist: ${outDir}`);
  }

  for (const dirName of ["server", "shared"]) {
    const from = path.join(capsuleRoot, dirName);
    const to = path.join(outDir, dirName);
    await rmAsync(to, { recursive: true, force: true });
    await cpAsync(from, to, { recursive: true });
  }

  const sfConfigSource = await readFileAsync(path.join(capsuleRoot, "sf.jsonc"), "utf8");
  const cleanName = spaceName.trim();
  const sfConfigOut = cleanName
    ? sfConfigSource.replace(/"name":\s*"[^"]*"/, `"name": ${JSON.stringify(cleanName)}`)
    : sfConfigSource;

  await writeFileAsync(path.join(outDir, "sf.jsonc"), sfConfigOut, "utf8");

  // Hard invariant: never allow client/index.tsx in the export root.
  const forbiddenClient = path.join(outDir, "client", "index.tsx");
  if (existsSync(forbiddenClient)) {
    await rmAsync(path.join(outDir, "client"), { recursive: true, force: true });
  }

  return {
    targetDir: outDir,
    staged: ["sf.jsonc", "server/index.ts", "shared/comments.ts"],
  };
}

const isMain =
  process.argv[1] &&
  path.resolve(process.argv[1]).toLowerCase() === path.resolve(fileURLToPath(import.meta.url)).toLowerCase();

if (isMain) {
  const { target, name } = parseArgs(process.argv.slice(2));
  if (!target) {
    console.error("Usage: node stage-capsule.mjs --target <export-dir> [--name <space-name>]");
    process.exit(1);
  }
  stageCapsule({ targetDir: target, spaceName: name })
    .then((res) => {
      console.log(`Staged ${res.staged.join(", ")} into ${res.targetDir}`);
    })
    .catch((err) => {
      console.error(err instanceof Error ? err.message : String(err));
      process.exit(1);
    });
}
