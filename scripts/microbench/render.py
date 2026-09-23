"""Self-contained HTML renderer for the microbenchmark JSON format."""
import html
import json
import math


def escape(value):
    return html.escape(str(value), quote=True)


def number(value):
    if value is None or not isinstance(value, (int, float)) or not math.isfinite(value):
        return '—'
    return f'{value:,.2f}'


def render_report(report):
    paired = 'before' in report.get('binaries', {})
    labels = ['before', 'after'] if paired else ['current']
    cases = report.get('cases', {})
    counts = {state: sum(case.get('status') == state for case in cases.values())
              for state in ['passed', 'failed', 'skipped', 'running']}
    rows = []
    for case_id, case in cases.items():
        definition = case['definition']
        metric = definition['metric']
        summary = case.get('summary', {})
        state = case.get('status', 'running')
        cells = []
        for label in labels:
            value = summary.get(label, {}).get(metric, {})
            cells.append('<td class="num">' + number(value.get('median')) +
                         '<small>' + number(value.get('min')) + '–' + number(value.get('max')) + '</small></td>')
        ratio = case.get('ratio_after_before')
        delta = case.get('difference_after_before')
        change = '—'
        if paired and ratio is not None:
            change = f'{(ratio - 1) * 100:+.1f}%'
        elif paired and delta is not None:
            change = f'{delta:+,.0f} {definition["unit"]}'
        detail = '<p>' + escape(definition.get('note', '')) + '</p>'
        if state in ['failed', 'skipped']:
            detail += '<p class="issue">' + escape(case.get('error', case.get('reason', ''))) + '</p>'
        detail += '<p>Primary metric: <code>' + escape(metric) + '</code>; iterations argument: ' + \
            escape(case.get('iterations_argument', 'fixed or unavailable')) + '</p>'
        detail += '<table class="metrics"><thead><tr><th>Metric</th>' + ''.join(
            '<th class="num">' + escape(label) + '</th>' for label in labels) + '</tr></thead><tbody>'
        names = sorted({name for metrics in summary.values() for name in metrics})
        for name in names:
            detail += '<tr><td><code>' + escape(name) + '</code></td>' + ''.join(
                '<td class="num">' + number(summary.get(label, {}).get(name, {}).get('median')) + '</td>'
                for label in labels) + '</tr>'
        detail += '</tbody></table><button type="button" class="raw" data-case="' + escape(case_id) + \
            '">Show raw samples and commands</button><pre class="raw-output" hidden></pre>'
        rows.append('<tbody class="case" data-suite="' + escape(definition['suite']) + '" data-status="' +
                    escape(state) + '"><tr><td><code>' + escape(case_id) + '</code><small>' +
                    escape(definition['unit']) + '</small></td><td>' + escape(state) + '</td>' +
                    ''.join(cells) + ('<td class="num">' + change + '</td>' if paired else '') +
                    '</tr><tr><td colspan="' + str(3 + len(labels) if paired else 2 + len(labels)) +
                    '"><details><summary>Metrics and execution details</summary>' + detail + '</details></td></tr></tbody>')
    metadata = {key: value for key, value in report.items() if key != 'cases'}
    payload = json.dumps(report, ensure_ascii=False, allow_nan=False).replace('&', '\\u0026').replace('<', '\\u003c').replace('>', '\\u003e')
    payload = payload.replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')
    return '''<!doctype html>
<html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>UserCache microbenchmarks</title>
<style>
:root{font-family:system-ui,sans-serif;color:#182329;background:#f5f7f8}body{max-width:1280px;margin:0 auto;padding:2rem}
h1{font-size:2rem;margin-bottom:.5rem}p{line-height:1.5}.notice{border-left:4px solid #b36b00;background:#fff5df;padding:1rem}
.summary{display:flex;gap:1.4rem;flex-wrap:wrap;margin:1rem 0}.toolbar{display:flex;gap:.8rem;flex-wrap:wrap;margin:1.5rem 0}
button,input,select{font:inherit;padding:.5rem;border:1px solid #abb8be;border-radius:4px;background:white}input{min-width:20rem}
table{border-collapse:collapse;width:100%;background:white}th,td{padding:.6rem .8rem;border-bottom:1px solid #dbe2e5;text-align:left}
th{background:#e9eff2}tbody.case:nth-child(even){background:#f9fbfc}.num{text-align:right;font-variant-numeric:tabular-nums}
small{display:block;color:#566970;font-size:.8rem;margin-top:.25rem}details{margin:.3rem 0}summary{cursor:pointer;color:#285c74}
pre{white-space:pre-wrap;overflow-wrap:anywhere;max-height:35rem;overflow:auto;background:#edf2f4;padding:1rem}
code{font-size:.9em}.metrics{font-size:.85rem;margin:1rem 0}.issue{color:#9a3028}.footer{color:#52666e;font-size:.9rem}
@media(max-width:700px){body{padding:1rem}input{min-width:0;width:100%}th,td{padding:.4rem}}
</style><body>
<h1>UserCache microbenchmarks</h1>
<p>''' + ('Paired builds; lower primary values mean less time or retained memory.' if paired else
          'Single-build measurements; no before/after speedup is claimed.') + '''</p>
''' + ('<p class="notice"><strong>Quick smoke run.</strong> Short iterations verify workload execution. These values are not performance conclusions.</p>'
       if report.get('quick') else '') + '''
<div class="summary"><span>Status: <strong>''' + escape(report.get('status', 'unknown')) + '''</strong></span>
<span>Passed: ''' + str(counts['passed']) + '''</span><span>Failed: ''' + str(counts['failed']) + '''</span>
<span>Skipped: ''' + str(counts['skipped']) + '''</span><span>Rounds: ''' + escape(report.get('rounds', '')) + '''</span></div>
''' + ('<p class="issue">' + escape(report['error']) + '</p>' if report.get('error') else '') + '''
<p>Medians are shown with minimum–maximum below. Each sample uses a fresh PHP process. Writer latency percentiles are
batch means of up to 256 operations, not individual-operation tails. Snapshot retention includes PHP management tables;
it is not RSS. Fixed memory cases have their own workload sizes. Expand each row for all measured counters.</p>
<div class="toolbar"><input id="search" type="search" placeholder="Filter case names">
<select id="state"><option value="">All states</option><option>passed</option><option>failed</option><option>skipped</option></select>
<button id="download" type="button">Download complete JSON</button></div>
<table><thead><tr><th>Workload / unit</th><th>State</th>''' + ''.join(
        '<th class="num">' + escape(label) + '</th>' for label in labels) + \
        ('<th class="num">After vs before</th>' if paired else '') + '''</tr></thead>
''' + ''.join(rows) + '''</table>
<details><summary>Environment, PHP configuration and source hashes</summary><pre>''' + \
        escape(json.dumps(metadata, indent=2, ensure_ascii=False)) + '''</pre></details>
<p class="footer">This report contains its data and requires no network connection or external assets.</p>
<script id="report-data" type="application/json">''' + payload + '''</script>
<script>
const report = JSON.parse(document.getElementById('report-data').textContent);
const search = document.getElementById('search'), state = document.getElementById('state');
function filter() { document.querySelectorAll('tbody.case').forEach(row => {
 row.hidden = !row.textContent.toLowerCase().includes(search.value.toLowerCase()) ||
   (state.value && row.dataset.status !== state.value);
}); }
search.addEventListener('input', filter); state.addEventListener('change', filter);
document.querySelectorAll('button.raw').forEach(button => button.addEventListener('click', () => {
 const output = button.nextElementSibling;
 output.textContent = JSON.stringify(report.cases[button.dataset.case], null, 2); output.hidden = !output.hidden;
}));
document.getElementById('download').addEventListener('click', () => {
 const url = URL.createObjectURL(new Blob([JSON.stringify(report, null, 2) + '\\n'], {type:'application/json'}));
 const link = document.createElement('a'); link.href = url; link.download = 'micro.json'; link.click();
 setTimeout(() => URL.revokeObjectURL(url), 1000);
});
</script></body></html>
'''
