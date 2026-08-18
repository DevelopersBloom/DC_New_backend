"""
db_loader.py — Parse a MySQL SQL dump and return requested tables as DataFrames.
"""

import re
from io import StringIO
from typing import Dict, List, Optional

import pandas as pd


def load_tables(sql_path: str, table_names: List[str]) -> Dict[str, pd.DataFrame]:
    """
    Parse *sql_path* (a mysqldump file) and return a dict of
    {table_name: DataFrame} for every name in *table_names*.
    Raises KeyError if a required table has no INSERT rows.
    """
    wanted = set(table_names)
    # {table: [list-of-column-names]}
    columns: Dict[str, List[str]] = {}
    # {table: [list-of-row-dicts]}
    rows: Dict[str, List[dict]] = {t: [] for t in wanted}

    insert_header_re = re.compile(
        r"INSERT INTO `([^`]+)` \(([^)]+)\) VALUES\s*(.*)$"
    )
    create_re = re.compile(r"CREATE TABLE `([^`]+)`")
    col_def_re = re.compile(r"^\s*`([^`]+)`")
    # a data row starts with ( and ends with ), or ),
    data_row_re = re.compile(r"^\s*\(")

    current_create: Optional[str] = None
    current_cols: List[str] = []
    in_create = False
    # active INSERT state (persists across multiple value lines)
    active_table: Optional[str] = None
    active_cols: Optional[List[str]] = None

    with open(sql_path, "r", encoding="utf-8", errors="replace") as fh:
        for raw_line in fh:
            line = raw_line.rstrip("\n")

            # Track CREATE TABLE blocks
            if not in_create:
                m = create_re.match(line)
                if m:
                    current_create = m.group(1)
                    current_cols = []
                    in_create = True
                    active_table = None
                    active_cols = None
                    continue
            else:
                if line.strip().startswith(")"):
                    if current_create and current_create in wanted:
                        columns[current_create] = current_cols
                    in_create = False
                    current_create = None
                    continue
                m = col_def_re.match(line)
                if m:
                    current_cols.append(m.group(1))
                continue

            # New INSERT header — may end the previous multi-row block
            if line.startswith("INSERT INTO"):
                m = insert_header_re.match(line)
                if m:
                    tname = m.group(1)
                    col_names = [c.strip().strip("`") for c in m.group(2).split(",")]
                    if tname in wanted:
                        active_table = tname
                        active_cols = col_names
                    else:
                        active_table = None
                        active_cols = None
                    # handle values inline (rare but possible)
                    values_str = m.group(3).rstrip(";").strip()
                    if values_str and active_table:
                        for row_tuple in _split_value_tuples(values_str):
                            parsed = _parse_tuple(row_tuple)
                            if parsed is not None and len(parsed) == len(active_cols):
                                rows[active_table].append(dict(zip(active_cols, parsed)))
                continue

            # Data row lines for the active INSERT
            if active_table is not None and data_row_re.match(line):
                values_str = line.rstrip(";").rstrip(",").strip()
                for row_tuple in _split_value_tuples(values_str):
                    parsed = _parse_tuple(row_tuple)
                    if parsed is not None and len(parsed) == len(active_cols):
                        rows[active_table].append(dict(zip(active_cols, parsed)))
                continue

            # Any non-data line resets active INSERT
            if line.strip() and not line.strip().startswith("--"):
                active_table = None
                active_cols = None

    result: Dict[str, pd.DataFrame] = {}
    for t in table_names:
        result[t] = pd.DataFrame(rows.get(t, []))

    return result


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _split_value_tuples(values_str: str) -> List[str]:
    """Split  '(a,b),(c,d)'  into  ['a,b', 'c,d']  handling nested parens."""
    tuples: List[str] = []
    depth = 0
    start = None
    for i, ch in enumerate(values_str):
        if ch == "(" and depth == 0:
            depth = 1
            start = i + 1
        elif ch == "(":
            depth += 1
        elif ch == ")" and depth == 1:
            depth = 0
            if start is not None:
                tuples.append(values_str[start:i])
        elif ch == ")":
            depth -= 1
    return tuples


def _parse_tuple(s: str) -> Optional[List]:
    """Parse a comma-separated SQL value list into Python objects."""
    values: List = []
    i = 0
    n = len(s)
    while i < n:
        # skip leading whitespace/comma
        while i < n and s[i] in (" ", "\t"):
            i += 1
        if i >= n:
            break

        if s[i] == ",":
            i += 1
            continue

        if s[i] == "'":
            # string literal
            i += 1
            buf: List[str] = []
            while i < n:
                ch = s[i]
                if ch == "\\" and i + 1 < n:
                    nxt = s[i + 1]
                    buf.append(_unescape(nxt))
                    i += 2
                elif ch == "'":
                    i += 1
                    break
                else:
                    buf.append(ch)
                    i += 1
            values.append("".join(buf))
        else:
            # unquoted token: NULL, integer, float, hex
            j = i
            while j < n and s[j] not in (",",):
                j += 1
            token = s[i:j].strip()
            i = j
            values.append(_cast(token))

    return values


def _unescape(ch: str) -> str:
    return {"n": "\n", "t": "\t", "r": "\r", "0": "\x00"}.get(ch, ch)


def _cast(token: str):
    if token.upper() == "NULL":
        return None
    try:
        return int(token)
    except ValueError:
        pass
    try:
        return float(token)
    except ValueError:
        pass
    return token
