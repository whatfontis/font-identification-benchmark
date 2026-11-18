# font-identification-benchmark

Reproducible benchmark for automated font identification from a single word render.

**Test set**: 624 fonts (200+ Adobe Fonts, ~200 Google Fonts, 200+ dafont), each rendered as the word **"Abrupt"** so the typeface is the only thing that varies. Sourced from the public [whatfontis/Font-Finder](https://github.com/whatfontis/Font-Finder) repo (Git LFS).

The benchmark scores an API's ability to return the correct font in Top-1 / Top-5 / Top-10 / Top-20 given only the image. No human cropping. No per-image character hints.

**Filenames are opaque**. Images ship as `img_001.jpg` .. `img_624.jpg` so the filename we POST to the API cannot even *appear* to leak the answer. The ground truth lives in [`test-images/info.txt`](test-images/info.txt) as `img_NNN.jpg|<font name>`, sorted by font name. Rebuild the mapping any time with [`scripts/rename_to_opaque_ids.php`](scripts/rename_to_opaque_ids.php).

---

## Latest result

The API now takes a `mode` parameter (API 2.2+): **`fast`** does one identification pass, **`pro`** runs several and fuses the answers. `fast` is the server default. Both were run twice on 2026-11-17 on all 624 images — public endpoint `https://www.whatfontis.com/api2/`, no `AIMODEL` sent, fully automated, 0 errors.

| | `fast` (2026-11-17) | `pro` (2026-11-17) | 2026-10-08 | 2026-09-18 | Blog article |
|---|---:|---:|---:|---:|---:|
| Top-1 | 91.51% (571/624) | **91.83%** (573/624) | 92.95% (580/624) | 84.29% (526/624) | 81.4% |
| Top-5 | **98.72%** (616/624) | **98.72%** (616/624) | 98.08% (612/624) | 94.87% (592/624) | 92.8% |
| Top-10 | 99.04% (618/624) | **99.36%** (620/624) | 98.56% (615/624) | 95.51% (596/624) | – |
| Top-20 | 99.04% (618/624) | **99.52%** (621/624) | 98.56% (615/624) | 96.15% (600/624) | 96.3% |
| Not in top 20 | 6 | **3** | 9 | 24 | – |
| Median time per image | **1.86 s** | 5.57 s | 11.95 s | 3.74 s | – |
| Average time per image | **2.02 s** | 5.67 s | 12.24 s | 3.96 s | – |
| Run folders | [`1cf358d5`](results/2026-11-17_1cf358d5/) [`70402f80`](results/2026-11-17_70402f80/) | [`06de8c63`](results/2026-11-17_06de8c63/) [`35deb47a`](results/2026-11-17_35deb47a/) | [`171bc8af`](results/2026-10-08_171bc8af/) | [`d7b6d7de`](results/2026-09-18_d7b6d7de/) | – |

### The runs are now reproducible

**Each mode was run twice, and the two runs are identical image by image** — same ranking for all 624 images, in both modes. That was not true before: two runs of the same endpoint on the same day in September differed on dozens of images. Anyone re-running this today should expect to reproduce the numbers above, not merely land near them.

- `fast`: `1cf358d5` and `70402f80` — 0 images with a different rank.
- `pro`: `06de8c63` and `35deb47a` — 0 images with a different rank.

The second run of each pair is published alongside the first so the claim can be checked (`diff` the two `results.csv`, modulo the `ms` column).

### fast vs pro

Of 624 images, **590 get the exact same rank in both modes.** `pro` is better on 18 and worse on 16, so it buys 2 net images at Top-1 for 2.3× the time.

Where `pro` earns its keep is the images `fast` misses entirely: three of the six (`Break`, `Docallisme On Street`, `Christmas`) come back, two of them at rank 1. It also promotes 13 images from rank 2–9 to rank 1.

What it costs: 11 fonts that `fast` returns first drop to rank 2–3, and `Times` (4 → 10) and `Noto Sans` (3 → 8) fall further. These are nearly all classic text faces, where fusing several passes appears to dilute the right answer. Full lists in each run's `results.csv`.

**Practical reading: use `fast` as the default and `pro` as a second attempt when `fast` finds nothing.** Running `pro` on everything pays 2.3× the latency for +0.3 pp Top-1.

### Against the previous runs

Versus **2026-10-08**, the picture is mixed and worth stating plainly: Top-1 is 1.1 pp *lower* (`pro`) while Top-20 is ~1 pp higher, misses drop from 9 to 3, and each call is 2–6× faster. Image by image, `pro` ranks 25 images higher and 27 lower than the October run; at Top-1 it gains 17 and loses 24. Both modes recover `Times`, `Panic`, `The Beach` and `The Christmas`, which October missed entirely; `pro` also recovers `Break`, `Christmas` and `Docallisme On Street`. One image is a new miss in both modes: `img_421.jpg` (`New Romantics`), which was Top-1 in October.

Versus **2026-09-18**, both modes are far ahead on every depth: 19 of September's 24 misses are now found, and Top-1 is up 7+ pp.

The three images `pro` still misses, with what the API returned first, are in [`not_found.txt`](results/2026-11-17_06de8c63/not_found.txt):

```
img_087.jpg|BlincType Letterpress Fontpak|Gomorrah
img_357.jpg|Love|Love2
img_421.jpg|New Romantics|Hebrew Yossef Std Tag
```

`img_357.jpg` is a scoring edge case rather than a clean failure: the expected family is `Love` and the API answers `Love2`, which the token matcher rejects.

### Verify any of it yourself, no API key needed

```bash
php scripts/rescore.php results/2026-11-17_1cf358d5      # fast, run 1
php scripts/rescore.php results/2026-11-17_70402f80      # fast, run 2
php scripts/rescore.php results/2026-11-17_06de8c63      # pro, run 1
php scripts/rescore.php results/2026-11-17_35deb47a      # pro, run 2
php scripts/rescore.php results/2026-10-08_171bc8af
php scripts/rescore.php results/2026-09-18_d7b6d7de
```

Each re-scores every raw response in `responses.jsonl` against `test-images/info.txt` and exits non-zero if the result differs from `summary.json`. All six pass as published.

Which mode actually served a run is recorded, not assumed: the API returns `X-WFI-Mode` and `X-WFI-API` headers, and the runner stores them in `run_metadata.json` as `mode_served` / `api_served` next to the `mode_param` it asked for. The four runs above are stamped `"api_served": "2.3"`.

`responses.jsonl` keeps the font list of every response as returned. The API also returns an internal diagnostics block (`model`); it is not stored. The response shape has varied over time — `{"raw": [...]}` in the October and November runs, `{"raw": {"results": [...]}}` in September — and `rescore.php` reads both.

---

## General-purpose AI models on the same test

The same 624 images, sent on 2026-11-17 to three general-purpose multimodal models through their public APIs. Every model got the same image and the same prompt ([`prompts/font_id_llm.txt`](prompts/font_id_llm.txt)), asking for up to 20 font names, most likely first, as JSON. Provider defaults otherwise: no temperature, no reasoning or thinking settings, no tools, no web search. The answers are scored with the same matcher as the WhatFontIs runs.

| | WhatFontIs `api2`<br>`pro` | WhatFontIs `api2`<br>`fast` | GPT-6.2 Astra | Claude Fable 5.2 | Gemini 4 Carbon |
|---|---:|---:|---:|---:|---:|
| Model ID | – | – | `gpt-6.2-astra` | `claude-fable-5-2` | `gemini-4-carbon` |
| Top-1 | **91.83%** (573) | 91.51% (571) | 20.99% (131) | 13.62% (85) | 12.18% (76) |
| Top-5 | **98.72%** (616) | 98.72% (616) | 32.69% (204) | 27.08% (169) | 20.03% (125) |
| Top-10 | **99.36%** (620) | 99.04% (618) | 37.02% (231) | 32.37% (202) | 26.44% (165) |
| Top-20 | **99.52%** (621) | 99.04% (618) | 37.98% (237) | 39.74% (248) | 33.17% (207) |
| Not in the answer | **3** | 6 | 387 | 376 | 417 |
| Errors | 0 | 0 | 0 | 0 | 0 |
| Unreadable replies | – | – | 0 | 0 | 10 |
| Median time per image | 5.57 s | **1.86 s** | 10.69 s | 3.07 s | 24.23 s |
| Input tokens, 624 images | – | – | 193,985 | 232,377 | 747,618 |
| Output tokens, 624 images | – | – | 179,374 | 172,916 | 1,304,431 |
| …of which reasoning | – | – | 148,062 | 76,970 | 1,245,014 |
| Cost per image | $0.0010 | $0.0010 | $0.0140 | $0.0141 | $0.0233 |
| Cost for all 624 images | **$0.62** | **$0.62** | $8.73 | $8.78 | $14.54 |
| Cost per correct Top-1 answer | **$0.0011** | $0.0011 | $0.067 | $0.103 | $0.191 |
| Run folder | [`06de8c63`](results/2026-11-17_06de8c63/) | [`1cf358d5`](results/2026-11-17_1cf358d5/) | [`51f3e95d`](results/2026-11-17_gpt-6.2-astra_51f3e95d/) | [`8b470235`](results/2026-11-17_claude-fable-5-2_8b470235/) | [`8a52c295`](results/2026-11-17_gemini-4-carbon_8a52c295/) |

**The purpose-built API is 4.4× the best general-purpose model at Top-1 and 2.5× at Top-20.** The gap widened since the September comparison: WhatFontIs gained 7 pp while the general-purpose models stayed in the same band, even though all three are newer releases than the ones tested in September.

- GPT-6.2 Astra is the strongest of the three at Top-1 (20.99%) but returns about 10 names rather than 20, so its Top-20 barely exceeds its Top-10. Claude Fable 5.2 answers with nearly the full 20 and overtakes it at Top-20 (39.74%) while ranking first less than half as often — a wider, less committed shortlist.
- Gemini 4 Carbon is last at every depth. It also spent 1.25 M reasoning tokens — 5.5× the other two models' reasoning put together — and 10 of its 624 replies hit the token ceiling before producing a parseable list (`MAX_TOKENS`); those count as misses.
- No refusals from any of the three this time (Claude Fable 5.1 refused one image in September).
- Both GPT and Claude produced reasoning/thinking tokens under provider defaults, without being asked to.
- **Cost.** Token counts come from what each API reported, line by line, in `responses.jsonl`, so every figure above can be recomputed from the published runs. Reasoning tokens bill as output on all three. At list prices on 2026-11-17 — GPT-6.2 Astra $8 / $40 per 1M input / output tokens, Claude Fable 5.2 $8 / $40, Gemini 4 Carbon $2 / $10 — the three model runs cost **$32.05 together**. The WhatFontIs figures use **$1 per 1,000 identifications**, the commercial API rate; the public endpoint is free for personal use within its 200-requests-a-day default limit, and `pro` is priced the same as `fast`.
- **Gemini's bill is mostly reasoning**: its 1.25 M thinking tokens bill as output at $10 per 1M, which is $12.45 of its $14.54 — and it still placed last at every depth. Its price doubles on 2026-12-01 ($4 / $20), which would take the same run to $29.08.
- **Per correct answer** the gap is wider than per image: one Top-1 hit costs $0.0011 through the API against $0.067 from GPT-6.2 Astra (61×), $0.103 from Claude Fable 5.2 (95×) and $0.191 from Gemini 4 Carbon (176×). The purpose-built API is both more accurate and cheaper per unit of accuracy, not one at the expense of the other.
- Each folder has the raw reply of every call in `responses.jsonl` and the misses in `not_found.txt`. `php scripts/rescore.php <folder>` re-parses the raw replies and checks the score, as for the WhatFontIs runs.

For reference, the earlier generation of the same three models, run 2026-09-18 on this same set: GPT-6 Astra 14.42% Top-1 / 27.56% Top-20 ([`aa9ad5cc`](results/2026-09-18_gpt-6-astra_aa9ad5cc/)), Claude Fable 5.1 8.65% / 33.49% ([`8ea83dfe`](results/2026-09-18_claude-fable-5-1_8ea83dfe/)), Gemini 3.8 Flash 4.33% / 18.43% ([`c3c0a5e4`](results/2026-09-18_gemini-3.8-flash_c3c0a5e4/)).

Reproduce (keys in env vars or `scripts/llm_keys.php`, see `llm_keys.php.example`):

```bash
php scripts/run_llm_benchmark.php openai    gpt-6.2-astra
php scripts/run_llm_benchmark.php anthropic claude-fable-5-2
php scripts/run_llm_benchmark.php google    gemini-4-carbon
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

### Date and mode used

Each run stamps `run_metadata.json` with:

| Field | Meaning |
|---|---|
| `date_utc` / `finished_utc` | ISO-8601 timestamps when the run started and ended |
| `endpoint` | HTTP URL called for each image |
| `api_version` | path of the endpoint, e.g. `api2` |
| `aimodel` | the `AIMODEL` parameter sent, or `null` when none was sent (server default) |
| `mode_param` | the `mode` parameter sent — `fast`, `pro`, or `null` for none (server default) |
| `mode_served` / `api_served` | `X-WFI-Mode` / `X-WFI-API` from the response — which mode and API version actually ran |
| `test_set_source` | where the images come from |
| `repo_git_ref` | HEAD commit of this repo when the run started |
| `limit`, `timeout_s`, `total_images` | request parameters and set size |

`mode_param` is what we asked for and `mode_served` is what the server says it did; an invalid value falls back to `fast`, which the pair makes visible instead of silent.

The blog article's numbers were WhatFontIs's own — 81.4% Top-1, 96.3% Top-20 — measured on this same 624-image set with this same matcher.

### Automated or human-assisted?

**Fully automated.** No cropping, no character labels, no picking a bounding box. One `POST` per image with the raw JPEG. Every run's `run_metadata.json` sets `mode: "fully_automated"` explicitly, so it is unambiguous when someone else re-runs it under different assumptions (a human-in-the-loop pass would set `mode: "human_cropped"` or `mode: "human_char_hints"`). This field is about human involvement and is unrelated to the API's own `mode_param` (`fast` / `pro`), which is recorded separately.

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
{"file":"img_001.jpg","expected":"A Another Tag","rank":1,"http":200,"ms":3689,"raw":[{"title":"Another Tag","url":"..."},{"title":"Chiloe Regular otf (400)","url":"..."}]}
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
git clone https://github.com/whatfontis/font-identification-benchmark
cd font-identification-benchmark

# 2. unpack images (once)
cd test-images
unzip -q anospace.zip             # → imagenospace/*.jpg    (tightly rendered)
unzip -q awithspace.zip           # → image/*.jpg           (letters spaced apart)

# 3. set your API key (or put it in scripts/api_key.php, see api_key.php.example)
#    get one: https://www.whatfontis.com/API-identify-fonts-from-image.html
#    free for personal use (200 requests/day by default); commercial use is
#    $1 per 1,000 identifications, same price in fast and pro mode
export WFI_API_KEY=...

# 4. run — writes to results/<date>_<run-id>/
#    optional: WFI_ENDPOINT=<url>  WFI_AIMODEL=<model>  WFI_MODE=fast|pro
php scripts/run_benchmark.php imagenospace
WFI_MODE=pro php scripts/run_benchmark.php imagenospace

# 5. re-score from the saved raw responses (no key, no network)
php scripts/rescore.php results/<date>_<run-id>

# 6. list the misses (and, with --images, copy them into the run folder locally)
php scripts/build_not_found.php results/<date>_<run-id> [results/<earlier-run> ...]
```

Runtime for all 624 images: **~21 minutes** in `fast` mode (~2 s per image), **~50 minutes** in `pro` (~5 s). It was ~2 hours at the October 2026 revision and ~40 minutes in September.

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
│   ├── build_not_found.php          ← the misses of a run, and which are new vs an earlier run
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
