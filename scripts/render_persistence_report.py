#!/usr/bin/env python3
"""Render a validated persistence result.json without rerunning measurements."""
from __future__ import annotations

import argparse
import html
import json
import math
import os
from pathlib import Path
import sys
from urllib.parse import quote

ROOT = Path(__file__).resolve().parent.parent
LABELS = {"user_cache": "UserCache", "reference": "FrankenPHP zval.h reference wrapper", "reference_first": "Reference first", "reference_second": "Reference second"}


def esc(value):
    return html.escape(str(value), quote=True)


def fmt(value, digits=1):
    if not isinstance(value, (float, int)) or isinstance(value, bool) or not math.isfinite(value):
        raise ValueError("missing or invalid report metric")
    return f"{value:,.{digits}f}"


def median(row, backend, field):
    return row["backends"][backend][field]["median"]


def case_label(row):
    mutation = " / mutate" if row["mutate"] else " / read"
    if row["mutate"] and row["payload"] in ("null", "bool", "int", "double"):
        mutation = " / mutate flag (scalar no-op)"
    return f'{row["payload"]} / {row["keys"]:,} keys / {row["temperature"]}{mutation} / TTL {row["ttl"]}'


def table(headers, rows):
    if not rows:
        return '<p class="muted">Not measured in this selection.</p>'
    return '<div class="scroll"><table><thead><tr>' + ''.join('<th>' + esc(h) + '</th>' for h in headers) + '</tr></thead><tbody>' + ''.join('<tr>' + ''.join('<td>' + cell + '</td>' for cell in row) + '</tr>' for row in rows) + '</tbody></table></div>'


def validate_report(data):
    if data.get("schema_version") != 1 or data.get("status") not in ("complete", "failed", "running"):
        raise ValueError("unsupported or missing result schema/status")
    if not isinstance(data.get("smoke_only"), bool):
        raise ValueError("missing smoke/measurement distinction")
    for key in ("executions", "micro", "aa", "raw_helper", "http", "errors"):
        if not isinstance(data.get(key), list):
            raise ValueError(f"missing report section: {key}")
    if data["status"] == "complete":
        if data["errors"] or not data["executions"]:
            raise ValueError("complete report contains errors or no executions")
        if any(row.get("validated") is not True or row.get("returncode") != 0 for row in data["executions"]):
            raise ValueError("complete report contains an unvalidated/failed execution")
        stage = data.get("configuration", {}).get("stage")
        sections = {"all": ("micro", "aa", "raw_helper", "http"), "micro": ("micro", "raw_helper"), "aa": ("aa",), "http": ("http",)}.get(stage)
        if sections is None or any(not data[key] for key in sections):
            raise ValueError("complete report is missing requested measurements")
    return data


def render(data, source, output):
    validate_report(data)
    def link(path, label):
        relative = os.path.relpath(path, output.parent)
        return '<a href="' + esc(quote(relative, safe="/")) + '">' + esc(label) + '</a>'
    status = "SMOKE ONLY — not performance evidence" if data["smoke_only"] else "Measured run"
    if data["status"] != "complete":
        status = "INCOMPLETE / FAILED — " + status
    else:
        status += " — all selected samples validated"
    mode = data.get("configuration", {}).get("mode", "worker")
    if mode not in ("worker", "classic"):
        raise ValueError("invalid micro execution mode")
    parts = [f'<h1>FrankenPHP persistence performance</h1><p class="banner">{esc(status)}</p>',
             '<p>UserCache compared with a <strong>benchmark reference wrapper using the official FrankenPHP zval.h helpers</strong>. The reference is not a public native FrankenPHP cache API. Raw helper measurements are shown separately and never enter cache rankings.</p>',
             '<p><a id="download-result" href="#embedded-result" download="persistence-result.json">Download embedded aggregate JSON</a> · ' + link(source, "Workspace aggregate JSON") + ' · ' + link(source.parent / "build-manifest.json", "Workspace build manifest") + '</p>',
             '<p class="muted">This HTML contains the complete aggregate JSON, including provenance and the build manifest, so it remains available when only the HTML is published. Raw JSONL samples and logs remain separate local artifacts. Workspace links work only alongside the measurement files; publishing this HTML alone does not publish those files.</p>']
    if data.get("source_archive"):
        archive = data["source_archive"]
        parts.append('<p>Verified source inputs: ' + link(source.parent / archive["file"], "Download source-input archive (workspace artifact)") + ' · ' + fmt(archive["bytes"], 0) + ' bytes · SHA-256 <code>' + esc(archive["sha256"]) + '</code>. Includes the exact PHP input tree, harness assets, built source/config files and measurement producer snapshots. This separate archive must be published too if source downloads are needed outside the measurement workspace.</p>')
    if data["errors"]:
        parts.append('<h2>Run errors</h2><ul>' + ''.join('<li>' + esc(error) + '</li>' for error in data["errors"]) + '</ul>')
    parts.extend(['<h2>Measurement contract</h2>',
        '<p>Micro/A/A execution mode: <strong>' + esc(mode) + '</strong>. Worker mode retains the PHP execution between HTTP requests; classic mode starts a fresh PHP request for each micro/A/A HTTP sample. Each sample performs its seed and warmup in that same PHP execution. HTTP scaling always uses persistent workers, independently of this option.</p>',
        '<p>Worker-mode samples must have zero outstanding graph pins. Classic samples record a nonnegative pin count during the PHP request, when borrowed shared values may legitimately remain pinned; this observation does not verify their release after request shutdown.</p>',
        '<p>Dynamic payloads are created at runtime, with OPcache and JIT disabled. Seed/persist, setup and warmup are outside the timed fetch batch. Both cache wrappers include lookup, generation/TTL invalidation and warm PHP-heap COW caching; the raw helper reads an already persisted source and excludes cache lookup. Cold means the first fetch after store, including any store-time string COW seeding; there is no cold one-key row. The retained integer mutate-flag case does not mutate integers.</p>',
        '<p>Common cases cover null, boolean, integer, double, strings and nested arrays; object hooks, references, aliases/cycles and UserCache-specific native object support are outside this reference comparison. Mutation checks verify the cached source remains unchanged. Fetched values are released each iteration: these memory figures do not measure retaining an unbounded list of returned values. Stores in the mixed HTTP workload are reported together with reads; this is not an isolated store benchmark.</p>',
        '<p>Independent hosts run sequentially. Micro samples use randomized paired backend order; HTTP pairs alternate order within each case. A/A runs use the same reference wrapper twice to expose local noise. Each case stays visible: no grand mean, universal allowance, or claim of being faster for every type. Raw scalar/immutable-pointer helpers can be lower bounds that a full cache cannot beat. The supplied fixtures are dynamic; OPcache-immutable fixtures are not mixed into these results.</p>',
        '<p>Timing tables use per-backend medians. Ratios are medians of matched pairs, not ratios of pooled means; brackets show a paired bootstrap 95% interval for the median when at least two pairs exist. Session ratios remain visible. Intervals from very few pairs are exploratory. Lower latency/CPU is better; higher HTTP throughput is better. A repeated material regression must remain visible and investigated, rather than hidden by other cases.</p>'])
    aa_by_key = {(row["payload"], row["keys"], row["temperature"], row["mutate"], row["ttl"]): row for row in data["aa"]}
    rows = []
    for row in data["micro"]:
        ratio = row["paired_ratio"]["median"]
        interval = row["median_ratio_ci95"]
        ratio_text = fmt(ratio, 3) + '×' + (f' [{fmt(interval[0], 3)}, {fmt(interval[1], 3)}]' if interval else ' [one pair]')
        key = tuple(row[k] for k in ("payload", "keys", "temperature", "mutate", "ttl"))
        noise = aa_by_key.get(key)
        noise_text = fmt(noise["paired_ratio"]["median"], 3) + '×' if noise else 'not measured'
        sessions = ', '.join('s' + esc(k) + ': ' + fmt(v["median"], 3) + '×' for k, v in row["session_ratios"].items())
        rows.append([esc(case_label(row)), fmt(median(row, "user_cache", "ns_per_operation")), fmt(median(row, "reference", "ns_per_operation")), ratio_text, fmt(median(row, "user_cache", "cpu_ns_per_operation")), fmt(median(row, "reference", "cpu_ns_per_operation")), sessions, noise_text, esc(row["pairs"])])
    parts += ['<h2>PHP fetch batches — ' + esc(mode) + ' mode</h2>', '<p>ns/op includes the common PHP fetch loop and observation/mutation. Whole HTTP elapsed time also includes seed/setup and is kept in the JSON, not confused with timed cache operations.</p>', table(["Case", "UserCache ns/op", "Reference ns/op", "UserCache / reference", "UserCache CPU ns/op", "Reference CPU ns/op", "Session ratios", "A/A median", "Pairs"], rows)]
    rows = []
    for row in data["http"]:
        label = f'{row["kind"]} / {row["workers"]} workers / {row["operations"]} ops/HTTP / {"99% read + 1% store" if row["mixed"] else "read"} / TTL {row["ttl"]}'
        ratio = row["paired_throughput_ratio"]["median"]
        interval = row["median_ratio_ci95"]
        rows.append([esc(label), fmt(median(row, "user_cache", "requests_per_second"), 0), fmt(median(row, "reference", "requests_per_second"), 0), fmt(median(row, "user_cache", "operations_per_second"), 0), fmt(median(row, "reference", "operations_per_second"), 0), fmt(ratio, 3) + '×' + (f' [{fmt(interval[0], 3)}, {fmt(interval[1], 3)}]' if interval else ' [one pair]'), esc(row["rounds"])])
    parts += ['<h2>Real HTTP worker scaling</h2>', '<p>Closed-loop clients equal twice the worker count, with all workers warmed before timing. The array fixture has TTL 3600; the scalar fixture has TTL 0. Both share 32 keys. Request and operation rates are separate; all individual response samples and status/pin checks are retained.</p>', '<p>The loopback HTTP client and server run in the same Go process and share its CPU affinity. HTTP throughput includes client, server and networking overhead; it is not an isolated remote-client server-capacity result. Thread CPU measurements cover the PHP thread only, not total Go host/client CPU.</p>', table(["Case", "UserCache req/s", "Reference req/s", "UserCache ops/s", "Reference ops/s", "Throughput ratio (higher better)", "Rounds"], rows)]
    rows = []
    for row in data["http"]:
        label = f'{row["kind"]}, w{row["workers"]}, o{row["operations"]}, {"mixed" if row["mixed"] else "read"}'
        for backend in ("user_cache", "reference"):
            rows.append([esc(label), esc(LABELS[backend]), fmt(median(row, backend, "batch_ns_per_operation")), fmt(median(row, backend, "cpu_ns_per_operation")), fmt(median(row, backend, "http_p50_ns") / 1000), fmt(median(row, backend, "http_p95_ns") / 1000), fmt(median(row, backend, "http_p99_ns") / 1000)])
    parts.append(table(["HTTP case", "Backend", "PHP batch ns/op", "Thread CPU ns/op", "HTTP p50 µs", "HTTP p95 µs", "HTTP p99 µs"], rows))
    rows = []
    for row in data["micro"]:
        for backend in ("user_cache", "reference"):
            rows.append([esc(case_label(row)), esc(LABELS[backend])] + [fmt(median(row, backend, key), 0) for key in ("shared_used", "reference_persistent_after_seed", "php_heap_after_reset", "php_heap_after_seed", "php_heap_after_warm", "php_heap_after", "php_peak_heap")] + [fmt(median(row, backend, "graph_pins"), 0) if "graph_pins" in row["backends"][backend] else "raw samples only"])
    parts += ['<h2>Memory, kept separate by allocator</h2>', '<p>The supplied micro host configures UserCache SHM capacity at 256 MiB, entries_hint at 65,536 and PHP memory_limit at 768 MiB. The HTTP host configures SHM at 32 MiB and memory_limit at 256 MiB, leaving entries_hint at the extension default. These are configured capacities/limits, separate from the measured used-byte snapshots below; neither is process RSS.</p>', '<p>Bytes below are medians of absolute snapshots, not RSS or a combined score. UserCache shared used memory includes shared storage overhead. Reference persistent bytes count requested helper allocations, excluding allocator rounding and some wrapper metadata. PHP heap covers the sampled worker, after reset/seed/warm/read, including warm prototypes and bookkeeping. The inactive backend still exists in the host. Peak is reset per case. These categories cannot be added as if they described equal allocations.</p>', table(["Case", "Backend", "Shared used B", "Reference persistent B", "PHP reset B", "PHP seed B", "PHP warm B", "PHP after B", "PHP peak B", "In-request graph pins"], rows)]
    rows = []
    for row in data["http"]:
        for backend in ("user_cache", "reference"):
            rows.append([esc(f'{row["kind"]}, w{row["workers"]}, o{row["operations"]}, {"mixed" if row["mixed"] else "read"}'), esc(LABELS[backend])] + [fmt(median(row, backend, field), 0) for field in ("status_shared_used", "status_reference_persistent_bytes", "status_php_heap")])
    parts += ['<p>HTTP end status samples one worker PHP heap; it is not total heap across all workers or process RSS. Shared/persistent stores are process-wide.</p>', table(["HTTP case", "Backend", "Shared used B", "Reference persistent B", "Sampled worker PHP heap B"], rows)]
    rows = []
    for row in data["aa"]:
        dist = row["paired_ratio"]
        ci = row["median_ratio_ci95"]
        rows.append([esc(case_label(row)), fmt(dist["median"], 3) + '×', fmt(dist["min"], 3) + '–' + fmt(dist["max"], 3), fmt(dist["p25"], 3) + '–' + fmt(dist["p75"], 3), f'{fmt(ci[0], 3)}–{fmt(ci[1], 3)}' if ci else 'one pair', esc(row["pairs"])])
    parts += ['<h2>A/A measurement noise</h2>', table(["Case", "Second / first median", "Observed pair range", "Pair p25–p75", "Median 95% interval", "Pairs"], rows)]
    rows = [[esc(case_label(row)), fmt(row["ns_per_operation"]["median"]), fmt(row["cpu_ns_per_operation"]["median"]), esc(row["ns_per_operation"]["n"])] for row in data["raw_helper"]]
    parts += ['<h2>Raw helper diagnostic — no cache speed ratio</h2>', '<p>Reads call persistent_zval_to_request from an already persisted value. They do not persist/free per iteration, perform key lookup, implement TTL or share the warm wrapper prototype cache. The 32,768-key label records the surrounding workload shape; the raw helper still has one source value. This is an allocation/copy lower-level diagnostic, not a public FrankenPHP cache comparison.</p>', table(["Case shape", "Raw helper ns/op", "Raw helper CPU ns/op", "Samples"], rows)]
    parts += ['<h2>Provenance and artifacts</h2>', '<p>Official helper: <a href="' + esc(data.get("upstream_helper", "")) + '">pinned FrankenPHP zval.h</a>. Build manifest and binary/library/fixture SHA-256 values are stored in the aggregate JSON.</p>', '<details><summary>Configuration and environment</summary><pre>' + esc(json.dumps({"created_at": data.get("created_at"), "configuration": data.get("configuration"), "environment": data.get("environment"), "binaries": data.get("binaries"), "php_libraries": data.get("php_libraries")}, indent=2)) + '</pre></details>']
    rows = []
    for execution in data["executions"]:
        rows.append([esc(execution["label"]), "validated" if execution.get("validated") else "FAILED / incomplete", esc(execution.get("returncode", "not completed")), link(source.parent / execution["raw_file"], "JSONL.gz"), link(source.parent / execution["stderr_file"], "stderr")])
    parts.append('<p class="muted">The execution links below refer to local measurement-workspace artifacts. They are unavailable in an HTML-only publication unless the files are copied too.</p>')
    parts.append(table(["Execution", "Sample validation", "Exit", "Workspace raw samples", "Workspace log"], rows))
    css = """body{margin:0;color:#17202a;background:white;font:15px -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}main{max-width:1440px;margin:auto;padding:28px 20px 52px}h1{font-size:30px;margin:0 0 12px}h2{font-size:21px;margin:32px 0 10px}p{max-width:1100px;line-height:1.55}.banner{background:#fff2d8;border-left:4px solid #8c5a00;padding:12px;font-weight:600}.scroll{overflow-x:auto;margin:14px 0}table{border-collapse:collapse;width:100%;font-size:13px}th,td{border:1px solid #d8e0e7;padding:9px;text-align:right;white-space:nowrap}th{background:#f7f9fb;position:sticky;top:0}td:first-child,th:first-child{text-align:left}tr:nth-child(even){background:#fafbfd}a{color:#13715f}.muted{color:#5f6b7a}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f7f9fb;padding:15px}details{margin:16px 0}"""
    # A JSON script element is raw text in HTML: escape '<' so labels containing
    # '</script>' cannot terminate it. JSON decoding restores the original data.
    embedded = json.dumps(data, ensure_ascii=False, allow_nan=False, separators=(",", ":"))
    embedded = embedded.replace("&", "\\u0026").replace("<", "\\u003c").replace(">", "\\u003e").replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")
    archive = '<script id="embedded-result" type="application/json">' + embedded + '</script>'
    archive += '''<script id="download-result-script">
(function () {
    const contents = document.getElementById('embedded-result').textContent;
    const link = document.getElementById('download-result');
    link.href = URL.createObjectURL(new Blob([contents + '\\n'], {type: 'application/json;charset=utf-8'}));
})();
</script><noscript><p>The aggregate JSON is embedded in the HTML source in the application/json element with id embedded-result. Enable JavaScript for the download link.</p></noscript>'''
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FrankenPHP persistence performance</title><style>' + css + '</style></head><body><main>' + '\n'.join(parts) + '</main>' + archive + '</body></html>\n'


def render_file(source, output):
    source, output = Path(source).resolve(), Path(output).resolve()
    data = json.loads(source.read_text(encoding="utf-8"))
    contents = render(data, source, output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(contents, encoding="utf-8")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("result", type=Path)
    parser.add_argument("--output", type=Path, default=ROOT / "BENCH_RESULT_PERSISTENCE.html")
    args = parser.parse_args(argv)
    try:
        render_file(args.result, args.output)
    except (ValueError, KeyError, TypeError, OSError) as exc:
        print(f"persistence report: {exc}", file=sys.stderr)
        return 1
    print(f"Wrote HTML report: {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
