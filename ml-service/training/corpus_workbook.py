"""Reading the collection workbook without a spreadsheet library.

`scamsense_corpus_collection.xlsx` is where messages and URLs are collected by
hand before they reach a corpus file. This module reads it using nothing but the
standard library — an .xlsx is a zip of XML, and the twenty lines below are
cheaper than adding openpyxl to a project whose one dependency rule is that a
clean checkout can run everything.

It reads only. The workbook is often open in Excel, and Excel holds a lock file
beside it; nothing here writes, so that never matters.
"""

from __future__ import annotations

import re
import zipfile
from dataclasses import dataclass, field
from pathlib import Path
from xml.etree import ElementTree as ET  # noqa: S405

# ruff flags xml.etree as unsafe for untrusted input, which is right in
# general — an XML bomb expands during parse. It is suppressed here rather than
# pulling in defusedxml because this reads one file the developer authored, from
# their own disk, in a training script that never runs in a deployed service.
# The suppression is per-call and commented, not blanket, so an untrusted source
# arriving later has to argue with a reviewer.
NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"

#: Rows whose first cell starts with this are worked examples for the collector,
#: not data. The workbook tells them to delete the row; this does not rely on it.
EXAMPLE_PREFIX = "EXAMPLE"


@dataclass
class MessageRow:
    """One collected message, as typed into the workbook."""

    text: str
    label: str
    scam_type: str
    language: str
    #: What the collector typed in the `split` column. In the workbook as
    #: received this holds the collection channel ("my phone", "family phone")
    #: rather than a partition, which is why it is kept raw here and interpreted
    #: by the caller rather than trusted.
    split_raw: str
    source_raw: str
    notes: str
    row_number: int


@dataclass
class UrlRow:
    url: str
    label: str
    institution: str
    sector: str
    row_number: int


@dataclass
class Workbook:
    messages: list[MessageRow] = field(default_factory=list)
    urls: list[UrlRow] = field(default_factory=list)


def _column_index(ref: str) -> int:
    """`C4` -> 2. Cell references are letters then digits."""
    match = re.match(r"[A-Z]+", ref)
    if match is None:
        return 0

    index = 0
    for char in match.group(0):
        index = index * 26 + (ord(char) - 64)

    return index - 1


def _sheet_rows(archive: zipfile.ZipFile, member: str, shared: list[str]) -> list[list[str]]:
    root = ET.fromstring(archive.read(member))  # noqa: S314
    rows: list[list[str]] = []

    for row in root.iter(f"{NS}row"):
        cells: dict[int, str] = {}

        for cell in row.findall(f"{NS}c"):
            ref = cell.get("r")
            if not ref:
                continue

            value = cell.find(f"{NS}v")
            inline = cell.find(f"{NS}is")

            if cell.get("t") == "s" and value is not None:
                text = shared[int(value.text or "0")]
            elif inline is not None:
                text = "".join(t.text or "" for t in inline.iter(f"{NS}t"))
            elif value is not None:
                text = value.text or ""
            else:
                text = ""

            cells[_column_index(ref)] = text

        if cells:
            rows.append([cells.get(i, "") for i in range(max(cells) + 1)])

    return rows


def read(path: Path) -> Workbook:
    """Parse the Messages and URLs sheets. Other sheets are guidance for the
    collector and carry no data."""
    book = Workbook()

    with zipfile.ZipFile(path) as archive:
        shared: list[str] = []
        if "xl/sharedStrings.xml" in archive.namelist():
            root = ET.fromstring(archive.read("xl/sharedStrings.xml"))  # noqa: S314
            shared = ["".join(t.text or "" for t in si.iter(f"{NS}t")) for si in root]

        workbook_xml = ET.fromstring(archive.read("xl/workbook.xml"))  # noqa: S314
        names = [s.get("name") or "" for s in workbook_xml.iter(f"{NS}sheet")]

        members = sorted(
            n for n in archive.namelist() if re.match(r"xl/worksheets/sheet\d+\.xml$", n)
        )

        for name, member in zip(names, members, strict=False):
            if name not in ("Messages", "URLs"):
                continue

            rows = _sheet_rows(archive, member, shared)
            if not rows:
                continue

            for number, row in enumerate(rows[1:], start=2):

                def get(index: int, row: list[str] = row) -> str:
                    return row[index].strip() if index < len(row) else ""

                first = get(0)
                if not first or first.startswith(EXAMPLE_PREFIX):
                    continue
                if any(EXAMPLE_PREFIX in cell for cell in row):
                    continue

                if name == "Messages":
                    book.messages.append(
                        MessageRow(
                            text=first,
                            label=get(1).lower(),
                            scam_type=get(2),
                            language=get(3).lower(),
                            split_raw=get(4),
                            source_raw=get(5),
                            notes=get(6),
                            row_number=number,
                        )
                    )
                else:
                    book.urls.append(
                        UrlRow(
                            url=first,
                            label=get(1).lower(),
                            institution=get(2),
                            sector=get(3),
                            row_number=number,
                        )
                    )

    return book
