<?php
/**
 * IOF XML 3.0 results → CSV converter (single-file endpoint).
 *
 * GET  → drag-and-drop web page.
 * POST → converts an uploaded IOF 3.0 ResultList and returns the CSV.
 *
 *   curl -F file=@results.xml -F mode=auto https://example.org/oribos2csv/ -o results.csv
 *
 * Modes:
 *   individual  OE result CSV (@SICSVOE), port of OZS-IT/xml2oevent (iof2oevent.xsl, Marko)
 *   relay       wide relay CSV (@RELAY-LAST), port of relay_xml_to_csv.py (OZS-IT/relay-iofxml2csv)
 *   auto        relay if the file contains TeamResult elements, otherwise individual
 *
 * Requires PHP 7.4+ with the DOM extension (enabled on practically every host).
 */
declare(strict_types=1);

const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

final class ConverterError extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

function handle_post(): void
{
    try {
        [$xml, $name] = read_upload();
        $mode = strtolower(trim((string) ($_POST['mode'] ?? $_GET['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'individual', 'relay'], true)) {
            throw new ConverterError("Unknown mode \"$mode\". Use auto, individual or relay.");
        }

        $x = load_result_list($xml);
        if ($mode === 'auto') {
            $mode = count($x->all('//TeamResult', $x->doc)) > 0 ? 'relay' : 'individual';
        }
        [$csv, $rows] = $mode === 'relay' ? convert_relay($x) : convert_individual($x);

        $base = preg_replace('/\.[^.\/\\\\]*$/', '', basename($name)) ?: 'results';
        $outName = $base . '.csv';
        $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $outName);

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$asciiName\"; filename*=UTF-8''" . rawurlencode($outName));
        header('X-Converter-Mode: ' . $mode);
        header('X-Converter-Rows: ' . $rows);
        header('X-Converter-Classes: ' . count($x->all('/ResultList/ClassResult', $x->doc)));
        header('X-Converter-Event: ' . rawurlencode($x->text('/ResultList/Event/Name', $x->doc)));
        header('Cache-Control: no-store');
        echo $csv;
    } catch (ConverterError $e) {
        send_error(400, $e->getMessage());
    } catch (Throwable $e) {
        send_error(500, 'Conversion failed: ' . $e->getMessage());
    }
}

function send_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
}

/** @return array{0: string, 1: string} XML content and original file name */
function read_upload(): array
{
    if (isset($_FILES['file'])) {
        $f = $_FILES['file'];
        if (is_array($f['error'])) {
            throw new ConverterError('Upload one file per request.');
        }
        switch ($f['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new ConverterError('The file is larger than the server allows (upload_max_filesize).');
            case UPLOAD_ERR_NO_FILE:
                throw new ConverterError('No file was uploaded.');
            default:
                throw new ConverterError('Upload failed (error code ' . $f['error'] . ').');
        }
        if ($f['size'] > MAX_UPLOAD_BYTES) {
            throw new ConverterError('The file is too large (max ' . (MAX_UPLOAD_BYTES >> 20) . ' MB).');
        }
        return [(string) file_get_contents($f['tmp_name']), (string) $f['name']];
    }

    // Raw body, e.g. curl --data-binary @results.xml -H "Content-Type: application/xml"
    $body = (string) file_get_contents('php://input', false, null, 0, MAX_UPLOAD_BYTES + 1);
    if ($body === '') {
        throw new ConverterError('No file received. Send it as multipart field "file" (or the file may exceed post_max_size).');
    }
    if (strlen($body) > MAX_UPLOAD_BYTES) {
        throw new ConverterError('The file is too large (max ' . (MAX_UPLOAD_BYTES >> 20) . ' MB).');
    }
    return [$body, (string) ($_GET['name'] ?? 'results.xml')];
}

// ---------------------------------------------------------------------------
// XML helpers
// ---------------------------------------------------------------------------

/** Thin XPath wrapper; queries use plain element names (the IOF default namespace is stripped). */
final class Xml
{
    /** @var DOMDocument */
    public $doc;
    /** @var DOMXPath */
    private $xp;

    public function __construct(DOMDocument $doc)
    {
        $this->doc = $doc;
        $this->xp = new DOMXPath($doc);
    }

    /** @return DOMElement[] */
    public function all(string $query, ?DOMNode $ctx): array
    {
        if ($ctx === null) {
            return [];
        }
        $out = [];
        $list = $this->xp->query($query, $ctx);
        if ($list !== false) {
            foreach ($list as $node) {
                $out[] = $node;
            }
        }
        return $out;
    }

    public function one(string $query, ?DOMNode $ctx): ?DOMElement
    {
        return $this->all($query, $ctx)[0] ?? null;
    }

    public function text(string $query, ?DOMNode $ctx): string
    {
        $node = $this->one($query, $ctx);
        return $node === null ? '' : trim($node->textContent);
    }

    public function attr(string $query, string $name, ?DOMNode $ctx): string
    {
        $node = $this->one($query, $ctx);
        return $node === null ? '' : trim($node->getAttribute($name));
    }
}

function load_result_list(string $xml): Xml
{
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
    // Drop the default namespace declaration on the root element so XPath can use bare names.
    $isIof3 = false;
    $xml = preg_replace_callback(
        '/<ResultList\b[^>]*>/',
        static function (array $m) use (&$isIof3): string {
            $isIof3 = strpos($m[0], 'datastandard/3.0') !== false
                || preg_match('/\siofVersion\s*=\s*["\']3/', $m[0]) === 1;
            return preg_replace('/\sxmlns\s*=\s*("[^"]*"|\'[^\']*\')/', '', $m[0]);
        },
        $xml,
        1
    );

    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$ok || $doc->documentElement === null) {
        $detail = $errors ? trim($errors[0]->message) . ' (line ' . $errors[0]->line . ')' : 'unknown error';
        throw new ConverterError("This is not a valid XML file: $detail.");
    }

    $root = $doc->documentElement->localName;
    if ($root !== 'ResultList') {
        $hint = $root === 'StartList' ? ' This looks like a start list – export results instead.' : '';
        throw new ConverterError("Expected an IOF ResultList, found <$root>.$hint");
    }

    if (!$isIof3) {
        throw new ConverterError('This is not IOF XML 3.0 (probably the older 2.0.3 format). Please export results as IOF XML 3.0.');
    }
    return new Xml($doc);
}

function csv_minimal($value): string
{
    $s = (string) $value;
    return strpbrk($s, ";\"\r\n") === false ? $s : '"' . str_replace('"', '""', $s) . '"';
}

function quoted(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

// ---------------------------------------------------------------------------
// Individual results → OE CSV (@SICSVOE)   — port of iof2oevent.xsl
// ---------------------------------------------------------------------------

const OE_HEADER = [
    'Stno', 'Chip', 'Database Id', 'Surname', 'First name', 'YB', 'S', 'Block', 'nc', 'Start', 'Finish',
    'Time', 'Classifier', 'Club no.', 'Cl.name', 'City', 'Nat', 'Cl. no.', 'Short', 'Long', 'Num1', 'Num2',
    'Num3', 'Text1', 'Text2', 'Text3', 'Adr. name', 'Street', 'Line2', 'Zip', 'City', 'Phone', 'Fax',
    'EMail', 'Id/Club', 'Rented', 'Start fee', 'Paid', 'Course no.', 'Course', 'km', 'm', 'Course controls',
    'Pl', 'Start punch', 'Finish punch', 'Control1', 'Punch1', 'Control2', 'Punch2', 'Control3', 'Punch3',
    'Control4', 'Punch4', 'Control5', 'Punch5', '(may be more) ...',
];

const OE_STATUS = ['OK' => 0, 'DidNotStart' => 1, 'DidNotFinish' => 2, 'MissingPunch' => 3];

function sec_to_min_sec(string $seconds): string
{
    if (!is_numeric($seconds)) {
        return '';
    }
    $total = (int) floor((float) $seconds);
    return intdiv($total, 60) . ':' . sprintf('%02d', $total % 60);
}

/** @return array{0: string, 1: int} */
function convert_individual(Xml $x): array
{
    $results = $x->all('/ResultList/ClassResult/PersonResult', $x->doc);
    if (!$results) {
        throw new ConverterError('No individual results (PersonResult) found. Is this a relay? Try the Relay mode.');
    }

    $lines = ['@SICSVOE', implode(';', OE_HEADER)];
    $rows = 0;
    foreach ($results as $pr) {
        if ($x->text('Person/Name/Family', $pr) === 'Vakant') {
            continue;
        }
        $result = $x->one('Result', $pr);
        $org = $x->text('Organisation/Name', $pr);
        $class = $x->text('../Class/Name', $pr);
        $time = sec_to_min_sec($x->text('Time', $result));
        $splits = $x->all('SplitTime', $result);

        $f = [
            $x->text('BibNumber', $result),
            $x->text('ControlCard', $result),
            '""',
            quoted($x->text('Person/Name/Family', $pr)),
            quoted($x->text('Person/Name/Given', $pr)),
            '', '', '',                                   // YB, S, Block
            '0',                                          // nc
            substr($x->text('StartTime', $result), 14, 5),
            substr($x->text('FinishTime', $result), 14, 5),
            $time,
            (string) (OE_STATUS[$x->text('Status', $result)] ?? 99),
            $x->text('Organisation/Id', $pr),
            quoted($org),                                 // Cl.name
            quoted($org),                                 // City
            quoted($x->attr('Person/Nationality', 'code', $pr)),
            '0',                                          // Cl. no.
            quoted($class),                               // Short
            quoted($class),                               // Long
            '', '', '',                                   // Num1–3
        ];
        array_push($f, ...array_fill(0, 11, '""'));       // Text1 … EMail
        array_push(
            $f,
            '',                                           // Id/Club
            '0',                                          // Rented
            '"0,0"',                                      // Start fee
            '0',                                          // Paid
            '',                                           // Course no.
            '""', '""', '""',                             // Course, km, m
            (string) count($splits),                      // Course controls
            $x->text('Position', $result),                // Pl
            '',                                           // Start punch
            $time                                         // Finish punch
        );
        foreach ($splits as $split) {
            $f[] = $x->text('ControlCode', $split);
            $f[] = $x->one('Time', $split) !== null ? sec_to_min_sec($x->text('Time', $split)) : '';
        }

        $lines[] = implode(';', $f) . ';';
        $rows++;
    }

    return [implode("\n", $lines) . "\n", $rows];
}

// ---------------------------------------------------------------------------
// Relay results → wide CSV (@RELAY-LAST)   — port of relay_xml_to_csv.py
// ---------------------------------------------------------------------------

/** Wall-clock seconds of an ISO date-time (time zone ignored, as in the Python version). */
function wall_clock_seconds(string $iso): ?float
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?/', trim($iso), $m)) {
        return null;
    }
    $base = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
    return (float) $base + (isset($m[7]) && $m[7] !== '' ? (float) ('0' . $m[7]) : 0.0);
}

function format_seconds(?int $seconds): string
{
    if ($seconds === null) {
        return '';
    }
    $seconds = max($seconds, 0);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

function relative_time(string $iso, float $eventStart): string
{
    $t = wall_clock_seconds($iso);
    return $t === null ? '' : format_seconds((int) ($t - $eventStart));
}

function relay_classifier(string $status): int
{
    $s = strtolower(trim($status));
    return ($s === '' || $s === 'ok') ? 0 : 3;
}

function country_code(string $raw): string
{
    return $raw === '' ? '' : trim(explode('-', $raw)[0]);
}

function normalize_team_descr(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^(?:[A-Z]{0,3}\d+)\s*-\s*(.+)$/u', $raw, $m)) {
        return trim($m[1]);
    }
    return $raw;
}

/** @return array{0: string, 1: int} */
function convert_relay(Xml $x): array
{
    $doc = $x->doc;
    $date = $x->text('/ResultList/Event/StartTime/Date', $doc);
    $time = $x->text('/ResultList/Event/StartTime/Time', $doc);
    $eventStart = ($date !== '' && $time !== '') ? wall_clock_seconds("{$date}T{$time}") : null;
    if ($eventStart === null) {
        throw new ConverterError('Event start time is missing from the XML (Event/StartTime). The relay format needs it for relative times.');
    }

    $teams = [];
    $maxLegs = 0;
    foreach ($x->all('/ResultList/ClassResult', $doc) as $i => $classResult) {
        foreach ($x->all('TeamResult', $classResult) as $teamResult) {
            $team = build_team($x, $classResult, $i + 1, $teamResult, $eventStart);
            $teams[] = $team;
            $maxLegs = max($maxLegs, count($team['legs']));
        }
    }
    if (!$teams) {
        throw new ConverterError('No relay results (TeamResult) found. Is this an individual race? Try the Individual mode.');
    }

    $header = [
        'Stno', 'Descr', 'Block', 'nc', 'Start', 'Time', 'Classifier', 'Club no.', 'Cl.name', 'City', 'Nat',
        'Cl. no.', 'Short', 'Long', 'Legs', 'Num1', 'Num2', 'Num3', 'Text1', 'Text2', 'Text3', 'Start fee', 'Paid',
    ];
    for ($i = 0; $i < $maxLegs; $i++) {
        array_push($header, 'Surname', 'First name', 'YB', 'S', 'Start', 'Finish', 'Time', 'Classifier', 'Chip', 'Rented', 'Database Id');
    }
    $header[] = 'Pl';

    $out = "@RELAY-LAST\n" . relay_line($header);
    foreach ($teams as $t) {
        $row = [
            $t['start_no'], $t['descr'], '', 0, $t['team_start'], $t['team_time'], $t['classifier'],
            $t['club_no'], '', $t['club_name'], $t['nation'], $t['class_no'], $t['class_name'], $t['class_name'],
            count($t['legs']), '', '', '', '', '', '', '0.00', 0,
        ];
        foreach ($t['legs'] as $leg) {
            array_push($row, $leg['surname'], $leg['first_name'], '', '', $leg['start'], $leg['finish'],
                $leg['time'], $leg['classifier'], $leg['chip'], 0, '');
        }
        for ($i = count($t['legs']); $i < $maxLegs; $i++) {
            array_push($row, '', '', '', '', '', '', '', 0, '', 0, '');
        }
        $row[] = $t['placing'];
        $out .= relay_line($row);
    }

    return [$out, count($teams)];
}

function relay_line(array $fields): string
{
    return implode(';', array_map('csv_minimal', $fields)) . ";\n";
}

function build_team(Xml $x, DOMElement $classResult, int $classNo, DOMElement $teamResult, float $eventStart): array
{
    $orgs = $x->all('Organisation', $teamResult);
    $members = $x->all('TeamMemberResult', $teamResult);
    usort($members, static function (DOMElement $a, DOMElement $b) use ($x): int {
        return (int) ($x->text('Result/Leg', $a) ?: '0') <=> (int) ($x->text('Result/Leg', $b) ?: '0');
    });

    $legs = [];
    $statuses = [];
    foreach ($members as $member) {
        $person = $x->one('Person', $member);
        $result = $x->one('Result', $member);
        $classifier = relay_classifier($x->text('Status', $result));
        $timeText = $x->text('Time', $result);
        $seconds = ctype_digit($timeText) ? (int) $timeText : null;
        $noTime = $classifier !== 0 && ($seconds === null || $seconds <= 0);

        $legs[] = [
            'surname' => $x->text('Name/Family', $person),
            'first_name' => $x->text('Name/Given', $person),
            'start' => relative_time($x->text('StartTime', $result), $eventStart),
            'finish' => $noTime ? '' : relative_time($x->text('FinishTime', $result), $eventStart),
            'time' => $noTime ? '' : format_seconds($seconds),
            'classifier' => $classifier,
            'chip' => $x->text('ControlCard', $result),
        ];

        $status = $x->text('OverallResult/Status', $result) ?: $x->text('Status', $result);
        if ($status !== '') {
            $statuses[] = $status;
        }
    }

    $teamStatus = $statuses ? end($statuses) : '';
    $teamFailed = $teamStatus !== '' && relay_classifier($teamStatus) !== 0;
    $last = $members ? $x->one('Result', end($members)) : null;
    $overallTime = $x->text('OverallResult/Time', $last);

    $nation = '';
    foreach ($orgs as $org) {
        $nation = country_code($x->attr('Country', 'code', $org));
        if ($nation !== '') {
            break;
        }
    }
    if ($nation === '') {
        foreach ($members as $member) {
            $nation = country_code($x->attr('Person/Nationality', 'code', $member));
            if ($nation !== '') {
                break;
            }
        }
    }

    return [
        'start_no' => $x->text('BibNumber', $teamResult),
        'descr' => normalize_team_descr($x->text('Name', $teamResult)),
        'classifier' => relay_classifier($teamStatus),
        'club_no' => $x->text('Id', $orgs[0] ?? null),
        'club_name' => implode(' / ', array_filter(array_map(static function (DOMElement $o) use ($x): string {
            return $x->text('Name', $o);
        }, $orgs), 'strlen')),
        'nation' => $nation,
        'class_no' => (string) $classNo,
        'class_name' => $x->text('Class/Name', $classResult),
        'team_start' => $legs[0]['start'] ?? '',
        'team_time' => (!$teamFailed && ctype_digit($overallTime)) ? format_seconds((int) $overallTime) : '',
        'legs' => $legs,
        'placing' => $teamFailed ? '' : $x->text('OverallResult/Position', $last),
    ];
}

// ---------------------------------------------------------------------------
// Web page
// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    handle_post();
    exit;
}

$endpoint = htmlspecialchars(
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://'
    . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/oribos2csv/', '?'),
    ENT_QUOTES
);
$maxMb = MAX_UPLOAD_BYTES >> 20;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Results XML to CSV</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='6' fill='%23fff' stroke='%23e8590c' stroke-width='2'/%3E%3Cpath d='M2 30 30 2v28z' fill='%23e8590c'/%3E%3C/svg%3E">
<style>
:root {
  --bg: #f6f5f2;
  --surface: #ffffff;
  --surface-2: #f1efea;
  --border: #e3e0d8;
  --text: #1d1c1a;
  --muted: #6d6a63;
  --accent: #e8590c;
  --accent-soft: #fff1e8;
  --accent-text: #b8440a;
  --ok: #2f7d4f;
  --ok-soft: #e7f4ec;
  --err: #b42318;
  --err-soft: #fdecea;
  --shadow: 0 1px 2px rgba(30, 25, 15, .05), 0 8px 24px rgba(30, 25, 15, .06);
  --mono: ui-monospace, "SF Mono", "Cascadia Code", Consolas, monospace;
  color-scheme: light;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #151412;
    --surface: #1e1d1a;
    --surface-2: #262521;
    --border: #36342f;
    --text: #edeae4;
    --muted: #a19d94;
    --accent: #ff7a2f;
    --accent-soft: #33220f;
    --accent-text: #ffa36b;
    --ok: #5cc28a;
    --ok-soft: #17291f;
    --err: #ff8a7d;
    --err-soft: #331816;
    --shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 8px 24px rgba(0, 0, 0, .25);
    color-scheme: dark;
  }
}
* { box-sizing: border-box; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  -webkit-font-smoothing: antialiased;
}
.wrap { max-width: 880px; margin: 0 auto; padding: 48px 16px 64px; }
header { display: flex; gap: 16px; align-items: center; margin-bottom: 28px; }
.flag { flex: none; width: 44px; height: 44px; border-radius: 10px; overflow: hidden; box-shadow: var(--shadow); }
h1 { margin: 0; font-size: 1.6rem; letter-spacing: -.02em; line-height: 1.2; }
.sub { margin: 2px 0 0; color: var(--muted); }

.modes {
  display: inline-flex; gap: 4px; padding: 4px; margin-bottom: 14px;
  background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px;
  flex-wrap: wrap;
}
.modes input { position: absolute; opacity: 0; pointer-events: none; }
.modes label {
  padding: 7px 14px; border-radius: 9px; cursor: pointer; color: var(--muted);
  font-weight: 550; font-size: .92rem; transition: background .15s, color .15s;
}
.modes label small { font-weight: 450; opacity: .8; font-family: var(--mono); font-size: .75rem; margin-left: 4px; }
.modes input:checked + label { background: var(--surface); color: var(--text); box-shadow: var(--shadow); }
.modes input:focus-visible + label { outline: 2px solid var(--accent); outline-offset: 1px; }

.drop {
  position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 10px; min-height: 230px; padding: 32px 20px; text-align: center; cursor: pointer;
  background: var(--surface); border: 2px dashed var(--border); border-radius: 18px;
  box-shadow: var(--shadow); transition: border-color .15s, background .15s, transform .15s;
}
.drop:hover, .drop:focus-visible { border-color: var(--accent); outline: none; }
.drop.over { border-color: var(--accent); background: var(--accent-soft); transform: scale(1.01); }
.drop svg { color: var(--accent); }
.drop strong { font-size: 1.1rem; }
.drop span { color: var(--muted); font-size: .9rem; }
.drop u { color: var(--accent-text); text-underline-offset: 3px; }

#results { display: grid; gap: 12px; margin-top: 20px; }
.card {
  background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
  box-shadow: var(--shadow); overflow: hidden; animation: rise .25s ease-out;
}
@keyframes rise { from { opacity: 0; transform: translateY(6px); } }
.card-head { display: flex; align-items: center; gap: 14px; padding: 14px 16px; flex-wrap: wrap; }
.status-dot { flex: none; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; }
.card.ok .status-dot { background: var(--ok-soft); color: var(--ok); }
.card.err .status-dot { background: var(--err-soft); color: var(--err); }
.card.busy .status-dot { background: var(--surface-2); color: var(--muted); }
.card.busy .status-dot svg { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.meta { flex: 1; min-width: 0; }
.meta .name { font-weight: 600; overflow-wrap: anywhere; }
.meta .info { color: var(--muted); font-size: .88rem; }
.card.err .meta .info { color: var(--err); }
.tag {
  display: inline-block; padding: 1px 8px; border-radius: 999px; margin-right: 6px;
  background: var(--accent-soft); color: var(--accent-text); font-size: .78rem; font-weight: 600;
}
.actions { display: flex; gap: 8px; }
button.btn, a.btn {
  font: inherit; font-size: .9rem; font-weight: 600; border-radius: 9px; padding: 8px 14px; cursor: pointer;
  border: 1px solid var(--border); background: var(--surface-2); color: var(--text); text-decoration: none;
  display: inline-flex; align-items: center; gap: 6px;
}
a.btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.btn:hover { filter: brightness(.97); }
.btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

.preview { border-top: 1px solid var(--border); max-height: 360px; overflow: auto; }
.preview[hidden] { display: none; }
table { border-collapse: collapse; font-size: .8rem; font-family: var(--mono); white-space: nowrap; }
th, td { padding: 5px 10px; border-bottom: 1px solid var(--border); text-align: left; }
th { position: sticky; top: 0; background: var(--surface-2); font-weight: 600; z-index: 1; }
tbody tr:hover td { background: var(--accent-soft); }
.preview .note { padding: 8px 12px; color: var(--muted); font-size: .8rem; font-family: inherit; }

.help { margin-top: 36px; display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
.help section { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; }
.help h2 { margin: 0 0 6px; font-size: .95rem; }
.help p, .help li { color: var(--muted); font-size: .88rem; margin: 0; }
.help ul { margin: 0; padding-left: 18px; }
code { font-family: var(--mono); font-size: .82em; background: var(--surface-2); padding: 1px 5px; border-radius: 5px; }
pre {
  margin: 8px 0 0; padding: 10px 12px; background: var(--surface-2); border-radius: 9px;
  overflow-x: auto; font-family: var(--mono); font-size: .78rem; color: var(--text);
}
footer { margin-top: 28px; color: var(--muted); font-size: .82rem; text-align: center; }
footer a { color: inherit; }
@media (max-width: 560px) {
  .wrap { padding-top: 28px; }
  .actions { width: 100%; }
  .actions .btn { flex: 1; justify-content: center; }
}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <svg class="flag" viewBox="0 0 44 44" aria-hidden="true">
      <rect width="44" height="44" fill="#fff"/><path d="M0 44 44 0v44z" fill="#e8590c"/>
    </svg>
    <div>
      <h1>Results XML → CSV</h1>
      <p class="sub">Drop an IOF XML 3.0 result export from ORIBOS and get a CSV back.</p>
    </div>
  </header>

  <div class="modes" role="radiogroup" aria-label="Conversion mode">
    <input type="radio" name="mode" id="m-auto" value="auto" checked><label for="m-auto">Auto-detect</label>
    <input type="radio" name="mode" id="m-ind" value="individual"><label for="m-ind">Individual<small>@SICSVOE</small></label>
    <input type="radio" name="mode" id="m-relay" value="relay"><label for="m-relay">Relay<small>@RELAY-LAST</small></label>
  </div>

  <label class="drop" id="drop" tabindex="0">
    <input type="file" id="file" accept=".xml,text/xml,application/xml" multiple hidden>
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/>
    </svg>
    <strong>Drop XML file here</strong>
    <span>or <u>browse</u> · several files at once are fine · max <?= $maxMb ?> MB</span>
  </label>

  <div id="results" aria-live="polite"></div>

  <div class="help">
    <section>
      <h2>Formats</h2>
      <ul>
        <li><b>Individual</b>: OE result CSV, one row per runner with splits (port of <a href="https://github.com/OZS-IT/xml2oevent">xml2oevent</a>).</li>
        <li><b>Relay</b>: one row per team, runner columns repeated per leg (port of <a href="https://github.com/OZS-IT/relay-iofxml2csv">relay-iofxml2csv</a>).</li>
        <li><b>Auto</b> picks Relay when the file has team results.</li>
      </ul>
    </section>
    <section>
      <h2>Use from the command line</h2>
      <p>The page is also an endpoint:</p>
      <pre>curl -F file=@results.xml \
     -F mode=auto \
     <?= $endpoint ?> \
     -o results.csv</pre>
    </section>
  </div>

  <footer>Files are converted in memory and not stored on the server.</footer>
</div>

<script>
(() => {
  const drop = document.getElementById('drop');
  const input = document.getElementById('file');
  const results = document.getElementById('results');
  const PREVIEW_ROWS = 200;

  const icons = {
    busy: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.2-8.56"/></svg>',
    ok: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>',
    err: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 7v6"/><path d="M12 17h.01"/></svg>',
  };
  const modeLabel = { individual: 'Individual', relay: 'Relay' };

  const el = (tag, attrs = {}, text) => {
    const node = Object.assign(document.createElement(tag), attrs);
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const currentMode = () => document.querySelector('input[name=mode]:checked').value;

  drop.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
  });
  input.addEventListener('change', () => { handle(input.files); input.value = ''; });

  let depth = 0;
  window.addEventListener('dragenter', e => { e.preventDefault(); depth++; drop.classList.add('over'); });
  window.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; drop.classList.remove('over'); } });
  window.addEventListener('dragover', e => e.preventDefault());
  window.addEventListener('drop', e => {
    e.preventDefault(); depth = 0; drop.classList.remove('over');
    handle(e.dataTransfer.files);
  });

  function handle(files) {
    [...files].forEach(convert);
  }

  async function convert(file) {
    const card = el('div', { className: 'card busy' });
    const head = el('div', { className: 'card-head' });
    const dot = el('div', { className: 'status-dot', innerHTML: icons.busy });
    const meta = el('div', { className: 'meta' });
    const info = el('div', { className: 'info' }, 'Converting…');
    meta.append(el('div', { className: 'name' }, file.name), info);
    head.append(dot, meta);
    card.append(head);
    results.prepend(card);

    const fail = msg => {
      card.className = 'card err';
      dot.innerHTML = icons.err;
      info.textContent = msg;
    };

    try {
      const body = new FormData();
      body.append('file', file);
      body.append('mode', currentMode());
      const res = await fetch(location.pathname, { method: 'POST', body });
      if (!res.ok) {
        const plain = (res.headers.get('Content-Type') || '').startsWith('text/plain');
        const msg = plain ? (await res.text()).trim() : '';
        const hint = res.status === 404 ? ' – the converter was not found at this address; reload the page.' : '';
        return fail(msg || `Server error ${res.status} ${res.statusText}${hint}`);
      }

      const blob = await res.blob();
      const mode = res.headers.get('X-Converter-Mode') || '';
      const rows = res.headers.get('X-Converter-Rows') || '?';
      const classes = res.headers.get('X-Converter-Classes') || '?';
      const event = decodeURIComponent(res.headers.get('X-Converter-Event') || '');
      const outName = file.name.replace(/\.[^.]*$/, '') + '.csv';
      const url = URL.createObjectURL(blob);

      card.className = 'card ok';
      dot.innerHTML = icons.ok;
      info.textContent = '';
      info.append(el('span', { className: 'tag' }, modeLabel[mode] || mode));
      const unit = mode === 'relay' ? 'teams' : 'runners';
      info.append(`${rows} ${unit} · ${classes} classes${event ? ' · ' + event : ''}`);

      const actions = el('div', { className: 'actions' });
      const toggle = el('button', { className: 'btn', type: 'button' }, 'Preview');
      const dl = el('a', { className: 'btn primary', href: url, download: outName }, 'Download CSV');
      actions.append(toggle, dl);
      head.append(actions);

      const preview = el('div', { className: 'preview', hidden: true });
      card.append(preview);
      let built = false;
      toggle.addEventListener('click', async () => {
        if (!built) { buildPreview(preview, await blob.text()); built = true; }
        preview.hidden = !preview.hidden;
        toggle.textContent = preview.hidden ? 'Preview' : 'Hide preview';
      });

      dl.click();
    } catch (err) {
      fail('Could not reach the server: ' + err.message);
    }
  }

  function parseCsv(text) {
    const rows = [];
    let row = [], field = '', quoted = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (quoted) {
        if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
        else if (c === '"') quoted = false;
        else field += c;
      } else if (c === '"') quoted = true;
      else if (c === ';') { row.push(field); field = ''; }
      else if (c === '\n' || c === '\r') {
        if (c === '\r' && text[i + 1] === '\n') i++;
        row.push(field); rows.push(row); row = []; field = '';
      } else field += c;
    }
    if (field || row.length) { row.push(field); rows.push(row); }
    return rows.map(r => (r.length > 1 && r[r.length - 1] === '' ? r.slice(0, -1) : r));
  }

  function buildPreview(container, text) {
    const rows = parseCsv(text);
    const marker = rows.shift();
    const header = rows.shift() || [];
    const width = Math.max(header.length, ...rows.slice(0, PREVIEW_ROWS).map(r => r.length));
    const table = el('table');
    const thead = el('thead'), tr = el('tr');
    for (let i = 0; i < width; i++) tr.append(el('th', {}, header[i] ?? ''));
    thead.append(tr);
    const tbody = el('tbody');
    rows.slice(0, PREVIEW_ROWS).forEach(r => {
      const line = el('tr');
      for (let i = 0; i < width; i++) line.append(el('td', {}, r[i] ?? ''));
      tbody.append(line);
    });
    table.append(thead, tbody);
    const more = rows.length > PREVIEW_ROWS ? ` · showing first ${PREVIEW_ROWS} of ${rows.length} rows` : '';
    container.append(el('div', { className: 'note' }, `First line: ${marker ? marker[0] : ''}${more}`), table);
  }
})();
</script>
</body>
</html>
