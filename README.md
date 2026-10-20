# oribos2csv: IOF XML 3.0 results to CSV

A single-file PHP page that converts result exports from ORIBOS (IOF XML 3.0) into the CSV formats used by the OZS portal. Drop an XML file on the page and the CSV downloads right away. The same URL also works as an endpoint for scripts.

Working instance: <https://www.cerkno-cup.com/oribos2csv/>

## Formats

| Mode | First line | Output | Based on |
| --- | --- | --- | --- |
| Individual | `@SICSVOE` | One row per runner, including splits | [OZS-IT/xml2oevent](https://github.com/OZS-IT/xml2oevent) (`iof2oevent.xsl`, Marko) |
| Relay | `@RELAY-LAST` | One row per team, runner columns repeated for each leg | [OZS-IT/relay-iofxml2csv](https://github.com/OZS-IT/relay-iofxml2csv) (`relay_xml_to_csv.py`, Klemen) |
| Auto (default) | | Relay if the file contains `TeamResult` elements, otherwise individual | |

Both converters are rewritten in plain PHP, so the server needs neither Python nor an XSLT 2.0 engine. On sample files the output is byte-for-byte identical to the originals:

- Relay: the 2026 relay championship results (33 teams).
- Individual: EYOC 2026 long results (435 runners).

## Installation

1. Upload `index.php` and `.htaccess` into a folder (for example `oribos2csv/`) on any web server with PHP.
2. Open `https://your-server/oribos2csv/` in a browser.

Requirements:

- PHP 7.4 or newer with the DOM extension, which is enabled on practically every host. The included `.htaccess` selects PHP 8.3 on LiteSpeed hosts such as hitrost.com, where the account default is PHP 5.x and the page would otherwise return HTTP 500. On other hosts, set the PHP version in the hosting panel and remove the `SetHandler` block if it causes problems.
- An upload limit large enough for your files. The script accepts up to 25 MB (`MAX_UPLOAD_BYTES` at the top of the file), but many hosts allow only 2 MB by default. If larger files fail, raise `upload_max_filesize` and `post_max_size` in `php.ini` or `.htaccess`.

Files are converted in memory and never stored on the server.

## Using it from the command line

```sh
# multipart upload (mode: auto | individual | relay)
curl -F file=@results.xml -F mode=auto https://your-server/oribos2csv/ -o results.csv

# raw request body
curl --data-binary @results.xml -H "Content-Type: application/xml" \
     "https://your-server/oribos2csv/?mode=relay" -o results.csv
```

- **Success:** returns `200` with `text/csv` (UTF-8) and these headers: `X-Converter-Mode`, `X-Converter-Rows`, `X-Converter-Classes` and `X-Converter-Event` (URL-encoded).
- **Errors:** return `400` with a plain-text message, for example when the file is not valid XML, is a start list or course file instead of results, uses the old IOF 2.0.3 format, or does not fit the chosen mode.

## Notes on the output

**Individual.** These behaviours are copied from the original XSL so the portal gets what it expects:

- Start and Finish hold only the minutes and seconds of the clock time (`11:34:00` becomes `34:00`).
- `Cl. no.` is always `0`.
- Runners with the surname `Vakant` are skipped.
- Status codes: OK = 0, DNS = 1, DNF = 2, MP = 3, anything else = 99.

**Relay.** These behaviours come from the Python script:

- Times are relative to the event start (`Event/StartTime`), so the XML must contain it.
- Any status other than OK becomes classifier 3. Teams that are not OK get no team time and no place.
- Every row ends with an extra `;`.

## Testing locally with Docker

```sh
docker run --rm -p 8099:8000 -v "$PWD:/app" -w /app php:8.3-cli php -S 0.0.0.0:8000
```

Then open <http://localhost:8099/>.
