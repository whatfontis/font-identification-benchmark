# results/

One folder per run. Folder name is `YYYY-MM-DD_<8-char-run-id>`. Each folder holds:

| File | What |
|---|---|
| `results.csv` | one row per image — `file, expected, rank, ms, top1, top5, top10, top20` |
| `responses.jsonl` | one line per image — the raw JSON body the server returned, kept verbatim as proof of run |
| `run_metadata.json` | date, endpoint, mode, git refs |
| `summary.json` | Top-1 / Top-5 / Top-10 / Top-20 counts, avg ms, errors, not-found |
| `not_found.txt` | (newer runs) images whose font was not in the top 20: `file|expected|what the API returned first` |

Runs of general-purpose models (`YYYY-MM-DD_<model>_<run-id>`, from `scripts/run_llm_benchmark.php`) use the
same files. `results.csv` has an extra `answer_1` column (the model's first answer), and each `responses.jsonl`
line also has `provider`, `fonts` (the list read from the reply) and `attempts`; `raw` is the provider's reply.

Verify any run offline with `php scripts/rescore.php results/<run-folder>` — it re-scores
`responses.jsonl` and checks the result against `summary.json`.
## CSV columns

| Column | Meaning |
|---|---|
| `file` | JPG basename in `test-images/imagenospace/` (or `image/`) |
| `expected` | ground truth from `test-images/info.txt` — the family name |
| `rank` | 1-based position of the first matching candidate in the API response; `not found` if not in the returned list; `ERROR: <msg>` if the call itself failed |
| `ms` | round-trip time (curl start → response) for that call |
| `top1..top20` | 1 if `rank` is within that depth, else 0; blank when `rank` is `ERROR:` |

Errors are excluded from the % denominator (an API/network failure is not a wrong
answer). A font that simply did not come back is a **miss** (`not found`) and counts
against the score.

## Comparing runs

Both `summary.json` and `run_metadata.json` are stable, small, and diff-friendly.
Two runs on the same date on the same machine collide only if they share the same
`run_id` (~1 in 4 billion), so it is safe to keep every run in this folder.

Delete a run folder to drop it from git; nothing else references these files.
