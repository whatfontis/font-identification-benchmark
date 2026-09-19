# font-identification-benchmark

Reproducible benchmark for automated font identification from a single word render.

**Test set**: 624 fonts (200+ Adobe Fonts, ~200 Google Fonts, 200+ dafont), each rendered as the word **"Abrupt"** so the typeface is the only thing that varies. Sourced from the public [whatfontis/Font-Finder](https://github.com/whatfontis/Font-Finder) repo (Git LFS).

The benchmark scores an API's ability to return the correct font in Top-1 / Top-5 / Top-10 / Top-20 given only the image. No human cropping. No per-image character hints.

**Filenames are opaque**. Images ship as `img_001.jpg` .. `img_624.jpg` so the filename we POST to the API cannot even *appear* to leak the answer. The ground truth lives in [`test-images/info.txt`](test-images/info.txt) as `img_NNN.jpg|<font name>`, sorted by font name. Rebuild the mapping any time with [`scripts/rename_to_opaque_ids.php`](scripts/rename_to_opaque_ids.php).

---

## Latest result

Run [`results/2026-09-18_d7b6d7de/`](results/2026-09-18_d7b6d7de/) — public endpoint `https://www.whatfontis.com/api2/`, no model parameter sent, fully automated, all 624 images, 0 errors.

| | This run | Blog article |
|---|---:|---:|
| Top-1 | **84.29%** (526/624) | 81.4% |
| Top-5 | **94.87%** (592/624) | 92.8% |
| Top-10 | **95.51%** (596/624) | – |
| Top-20 | **96.15%** (600/624) | 96.3% |
| Not in top 20 | 24 | – |
| Average time per image | 3.96 s | – |

- Started 2026-09-18 15:22 UTC, finished 16:03 UTC.
- The 24 misses, with what the API returned first, are in [`not_found.txt`](results/2026-09-18_d7b6d7de/not_found.txt).
- Scored with `scripts/match_helpers.php` as it is in this repository; the rescore below reproduces the numbers.
- `responses.jsonl` keeps the font list of every response as returned. The API also returns an internal diagnostics block (`model`), which is not stored.

Check it yourself, no API key needed:

```bash
php scripts/rescore.php results/2026-09-18_d7b6d7de
```

It re-scores every raw response in `responses.jsonl` against `test-images/info.txt` and exits non-zero if the result differs from `summary.json`.

Results are not bit-identical between runs: the same image can come back with a different ranking on a second call, so a re-run can differ by a few images.

---

## General-purpose AI models on the same test

The same 624 images, sent on 2026-09-18 to three general-purpose multimodal models through their public APIs. Every model got the same image and the same prompt ([`prompts/font_id_llm.txt`](prompts/font_id_llm.txt)), asking for up to 20 font names, most likely first, as JSON. Provider defaults otherwise: no temperature, no reasoning or thinking settings, no tools, no web search. The answers are scored with the same matcher as the WhatFontIs run.

| | WhatFontIs `api2` | GPT-6 Astra | Claude Fable 5.1 | Gemini 3.8 Flash |
|---|---:|---:|---:|---:|
| Model ID | – | `gpt-6-astra` | `claude-fable-5-1` | `gemini-3.8-flash` |
| Top-1 | **84.29%** (526) | 14.42% (90) | 8.65% (54) | 4.33% (27) |
| Top-5 | **94.87%** (592) | 23.72% (148) | 19.07% (119) | 9.13% (57) |
| Top-10 | **95.51%** (596) | 27.56% (172) | 26.60% (166) | 13.94% (87) |
| Top-20 | **96.15%** (600) | 27.56% (172) | 33.49% (209) | 18.43% (115) |
| Not in the answer | 24 | 452 | 415 | 509 |
| Errors | 0 | 0 | 0 | 0 |
| Average time per image | 4.0 s | 26.4 s | 7.2 s | 12.8 s |
| API cost for 624 images | – | $25.65 | $7.80 | $6.77 |
| Run folder | [`d7b6d7de`](results/2026-09-18_d7b6d7de/) | [`aa9ad5cc`](results/2026-09-18_gpt-6-astra_aa9ad5cc/) | [`8ea83dfe`](results/2026-09-18_claude-fable-5-1_8ea83dfe/) | [`c3c0a5e4`](results/2026-09-18_gemini-3.8-flash_c3c0a5e4/) |

- GPT-6 Astra usually answered with 8–12 names rather than 20, so its Top-20 equals its Top-10.
- Claude Fable 5.1 refused one image (`img_551.jpg`, `stop_reason: refusal`); it counts as a miss. No other refusals, and every other reply had a readable list.
- Cost is computed from the token counts each API reported (in `responses.jsonl`) at list prices on 2026-09-18: GPT-6 Astra and Claude Fable 5.1 $10 / $50 per 1M input / output tokens, Gemini 3.8 Flash $0.75 / $3.75. Reasoning tokens are billed as output.
- Each folder has the raw reply of every call in `responses.jsonl` and the misses in `not_found.txt`. `php scripts/rescore.php <folder>` re-parses the raw replies and checks the score, as for the WhatFontIs run.

Reproduce (keys in env vars or `scripts/llm_keys.php`, see `llm_keys.php.example`):

```bash
php scripts/run_llm_benchmark.php openai    gpt-6-astra
php scripts/run_llm_benchmark.php anthropic claude-fable-5-1
php scripts/run_llm_benchmark.php google    gemini-3.8-flash
```

---

## Method

> How the numbers in the [blog article](https://www.whatfontis.com/blog/can-gpt-5-claude-or-gemini-identify-fonts-better-than-whatfontis-com/) were measured.

### What counts as "correct"

**Token-based match**, not exact string. `info.txt` records the **family name** (e.g. `Alfa Slab`); the catalogue returns the **full cut name** (e.g. `Alfa Slab One`). Both are correct.

The matcher in [`scripts/match_helpers.php`](scripts/match_helpers.php) — the same one WhatFontIs scores itself with — does the following on each candidate title AND on the URL slug:

1. drops weight/style words (`Light`, `Bold`, `Italic`, `Condensed`, `Pro`, `Std`, `Nova`, `Regular`, ...)
2. splits CamelCase (`AlfaSlab` → `Alfa Slab`)
3. strips punctuation / mojibake
4. keeps only the tokens that name the typeface (if that leaves nothing, as with `ITC Serif Gothic`, it keeps `sans` / `serif` / `slab` / `gothic` / `script`)
5. counts a hit when a distinctive token (4+ characters) of the ground-truth name appears in the candidate, or one contains the other at 5+ characters (`garamond` in `garamondpro`); otherwise every ground-truth token must appear

**Any weight/style from the same family is a hit.** `Berthold Akzidenz-Grotesk Medium Extended Italic` matches `Akzidenz-Grotesk`.

Scoring exact strings instead drops the same API's numbers from **~70% to ~20%** — you have to score properly to compare fairly.

### Date and model used

Each run stamps `run_metadata.json` with:

| Field | Meaning |
|---|---|
| `date_utc` / `finished_utc` | ISO-8601 timestamps when the run started and ended |
| `endpoint` | HTTP URL called for each image |
| `api_version` | path of the endpoint, e.g. `api2` |
| `aimodel` | the `AIMODEL` parameter sent, or `null` when none was sent (server default) |
| `test_set_source` | where the images come from |
| `repo_git_ref` | HEAD commit of this repo when the run started |
| `limit`, `timeout_s`, `total_images` | request parameters and set size |

The blog article's numbers were WhatFontIs's own — 81.4% Top-1, 96.3% Top-20 — measured on this same 624-image set with this same matcher.

### Automated or human-assisted?

**Fully automated.** No cropping, no character labels, no picking a bounding box. One `POST` per image with the raw JPEG. Every run's `run_metadata.json` sets `mode: "fully_automated"` explicitly, so it is unambiguous when someone else re-runs it under different assumptions (a human-in-the-loop pass would set `mode: "human_cropped"` or `mode: "human_char_hints"`).

### Per-image results

Every run writes to `results/YYYY-MM-DD_<run-id>/` — two files carry the score,
one file carries the proof:

**`results.csv`** — one row per image, human-readable score:

```csv
file,expected,rank,ms,top1,top5,top10,top20
img_001.jpg,A Another Tag,1,3689,1,1,1,1
img_002.jpg,ABeeZee,1,3266,1,1,1,1
img_100.jpg,Cormorant,3,4127,0,1,1,1
img_500.jpg,Trebuchet MS,not found,4210,0,0,0,0
```

**`responses.jsonl`** — one line per image, the **raw JSON** the server actually
returned. Kept verbatim as proof so anyone can re-score the same run under a
different matcher or spot-check that we did not massage the API's output:

```jsonl
{"file":"img_001.jpg","expected":"A Another Tag","rank":1,"http":200,"ms":3689,"raw":{"results":[{"title":"Another Tag","url":"..."},{"title":"Chiloe Regular otf (400)","url":"..."}]}}
```

**`run_metadata.json`** and **`summary.json`** — see `results/README.md`.

Columns:

- `file` — opaque JPG id (`img_NNN.jpg`) actually posted to the API
- `expected` — ground truth from `test-images/info.txt`
- `rank` — 1-based position of the first matching candidate; `not found` if the
  ground-truth font was not in the returned list; `ERROR: <msg>` if the call
  itself failed
- `ms` — round-trip time for that call
- `top1..top20` — 1 if `rank` is inside that depth, else 0; blank on `ERROR`

The full 624-image test set is at [whatfontis/Font-Finder](https://github.com/whatfontis/Font-Finder) — same seed, same rendering script, same fonts. Only the filenames differ: this benchmark ships them under opaque `img_NNN.jpg` ids to make the answer un-guessable from the request alone.

---

## Reproducing a run

```bash
# 1. clone (Git LFS pulls the two ~5 MB image zips)
git clone https://github.com/YOUR_ORG/font-identification-benchmark
cd font-identification-benchmark

# 2. unpack images (once)
cd test-images
unzip -q anospace.zip             # → imagenospace/*.jpg    (tightly rendered)
unzip -q awithspace.zip           # → image/*.jpg           (letters spaced apart)

# 3. set your API key (or put it in scripts/api_key.php, see api_key.php.example)
#    get one: https://www.whatfontis.com/API-identify-fonts-from-image.html
export WFI_API_KEY=...

# 4. run — writes to results/<date>_<run-id>/
#    optional: WFI_ENDPOINT=<url>  WFI_AIMODEL=<model>
php scripts/run_benchmark.php imagenospace

# 5. re-score from the saved raw responses (no key, no network)
php scripts/rescore.php results/<date>_<run-id>
```

Runtime: **~40 minutes** (`~4s` per image, 624 images).

Live progress is written to `results/<run-id>/results.csv` as it goes — safe to Ctrl+C and inspect.

---

## Layout

```
font-identification-benchmark/
├── README.md                        ← this file (Method printed on the page)
├── prompts/
│   └── font_id_llm.txt              ← the prompt sent to the general-purpose models
├── test-images/
│   ├── anospace.zip                 ← 624 JPGs, tightly cropped
│   ├── awithspace.zip               ← same 624 fonts, letters spaced apart
│   └── info.txt                     ← ground truth: `filename|font-name` × 624
├── scripts/
│   ├── run_benchmark.php            ← the run (POST every image, score, write CSV+JSON)
│   ├── match_helpers.php            ← the token-based matcher (findPosition())
│   ├── rescore.php                  ← re-score a run from responses.jsonl, check summary.json
│   ├── run_llm_benchmark.php        ← same test against GPT / Claude / Gemini
│   ├── llm_helpers.php              ← the three API calls + reading the font list from a reply
│   ├── test_images.php              ← Font-Finder's original scorer (kept for reference)
│   └── example.php                  ← one image, one call — the API response shape
└── results/
    └── YYYY-MM-DD_<run-id>/
        ├── results.csv              ← per-image rank, ms, top1/5/10/20
        ├── responses.jsonl          ← raw API JSON per image (proof of run)
        ├── run_metadata.json        ← date, endpoint, mode, git refs
        ├── summary.json             ← Top-1/5/10/20, avg ms, errors, not-found
        └── not_found.txt            ← misses: file | expected | what the API returned first
```

---

## What this benchmark does not answer

- **Distributional drift** — the images are clean single-word renders on white. Real user uploads have kerning, effects, background, photo noise; scores here will read higher than the same API on real crops.
- **Family vs cut discrimination** — a "correct" hit here means "any weight/style from the right family". If you need "the exact weight, italic and all", this scorer will overstate the answer.
- **Prompting the general-purpose models** — they were run with one fixed prompt and default settings. A different prompt, higher reasoning effort or web search could change their scores; `run_llm_benchmark.php` makes that easy to try.

---

## License

MIT for scripts and README. The test images are © their respective foundries and are used
here for benchmarking research only, via the [whatfontis/Font-Finder](https://github.com/whatfontis/Font-Finder) set.