"""
webapp.py — Local browser UI for the loan verifier.

Run:  python3 webapp.py
Then open http://127.0.0.1:5001 in a browser.

Two tools in one app:
  - Schedule validator (new): upload a SQL dump, enter a contract ID, see the
    initial schedule rebuilt two ways, the real payment trail, and the debt/
    penalty cross-check. See contract_report.py / schedule_engine.py.
  - Interest verifier (existing verify_loans.py logic, reused as-is): nominal/
    effective interest recompute vs. what the software stored.
"""

import os
from datetime import date

from flask import Flask, flash, redirect, render_template, request, url_for
from werkzeug.utils import secure_filename

import config
from calculator import verify_contract
from contract_report import build_contract_report
from db_loader import load_tables
from verify_loans import _build_contracts, _build_interest_records, _normalise_df
from config import COL_DEAL_CONTRACT_ID

app = Flask(__name__)
app.secret_key = "loan-verifier-local-dev"

UPLOAD_FOLDER = os.path.join(os.path.dirname(os.path.abspath(__file__)), "uploads")
os.makedirs(UPLOAD_FOLDER, exist_ok=True)

ALL_TABLES = list(dict.fromkeys(config.REQUIRED_TABLES + [config.TABLE_DOCUMENTS_JOURNAL]))

# Single-user local tool: keep parsed tables in a module-level cache instead
# of re-parsing a 20+MB dump on every request.
_STATE = {"tables": None, "filename": None}


def _ensure_loaded():
    if _STATE["tables"] is not None:
        return
    if os.path.exists(config.SQL_FILE_PATH):
        _STATE["tables"] = load_tables(config.SQL_FILE_PATH, ALL_TABLES)
        _STATE["filename"] = os.path.basename(config.SQL_FILE_PATH)


@app.route("/")
def index():
    _ensure_loaded()
    loaded = _STATE["tables"] is not None
    counts = {}
    if loaded:
        for t in ("contracts", "payments", "deals", "documents_journal", "transactions"):
            counts[t] = len(_STATE["tables"].get(t, []))
    return render_template("index.html", loaded=loaded, filename=_STATE["filename"], counts=counts)


@app.route("/upload", methods=["POST"])
def upload():
    f = request.files.get("sqlfile")
    if not f or not f.filename:
        flash("No file selected.", "error")
        return redirect(url_for("index"))
    filename = secure_filename(f.filename)
    path = os.path.join(UPLOAD_FOLDER, filename)
    f.save(path)
    try:
        _STATE["tables"] = load_tables(path, ALL_TABLES)
        _STATE["filename"] = filename
        flash(f"Parsed {filename} successfully.", "ok")
    except Exception as exc:  # noqa: BLE001 — surface parse errors to the user
        _STATE["tables"] = None
        flash(f"Failed to parse {filename}: {exc}", "error")
    return redirect(url_for("index"))


@app.route("/schedule/<int:contract_id>")
def schedule_page(contract_id):
    _ensure_loaded()
    if _STATE["tables"] is None:
        flash("Upload a SQL dump first.", "error")
        return redirect(url_for("index"))
    if contract_id <= 0:
        flash("Enter a contract ID.", "error")
        return redirect(url_for("index"))

    report = build_contract_report(_STATE["tables"], contract_id, date.today())
    if "error" in report and "info" not in report:
        return render_template("schedule.html", error=report["error"], contract_id=contract_id)

    max_rows = max(len(report["stored_schedule"]), len(report["legacy_schedule"]), len(report["broken_schedule"]))

    return render_template(
        "schedule.html",
        error=report.get("error"),
        contract_id=contract_id,
        info=report["info"],
        stored_schedule=[_StoredRow(r) for r in report["stored_schedule"]],
        legacy_schedule=report["legacy_schedule"],
        broken_schedule=report["broken_schedule"],
        max_rows=max_rows,
        current_rows=report["current_rows"],
        ledger=report["ledger"],
        calculated_balance=report["calculated_balance"],
        recomputed_legacy=report["recomputed_legacy"],
        recomputed_broken=report["recomputed_broken"],
        completed_count=report["completed_count"],
        live_completed_count=report["live_completed_count"],
        first_open_explainer=report["first_open_explainer"],
        payment_ledger=report["payment_ledger"],
        timeline=report["timeline"],
        penalty_result=report["penalty_result"],
        missing_penalty_postings=report["missing_penalty_postings"],
    )


class _StoredRow:
    """Thin wrapper so the template can use dot-access on stored JSON dicts too."""

    def __init__(self, d):
        self.date = d.get("date")
        self.interest = float(d.get("interest") or 0)
        self.principal = float(d.get("principal") or 0)
        self.balance = float(d.get("balance") or 0)


@app.route("/interest")
def interest_page():
    _ensure_loaded()
    if _STATE["tables"] is None:
        flash("Upload a SQL dump first.", "error")
        return redirect(url_for("index"))

    contract_id = request.args.get("contract_id", type=int)
    tables = _STATE["tables"]

    contracts = _build_contracts(tables[config.TABLE_CONTRACTS], tables[config.TABLE_CLIENTS])
    if contract_id:
        contracts = [c for c in contracts if c.id == contract_id]
        if not contracts:
            return render_template("interest.html", error=f"Contract {contract_id} not found or not active.",
                                    contract_id=contract_id)

    interest_records = _build_interest_records(tables[config.TABLE_TRANSACTIONS])
    cah_df = _normalise_df(tables[config.TABLE_CONTRACT_AMOUNT_HISTORIES], config.COL_CAH_CONTRACT_ID)
    deals_df = _normalise_df(tables[config.TABLE_DEALS], COL_DEAL_CONTRACT_ID)

    today = date.today()
    all_rows = []
    for contract in contracts:
        recs = [r for r in interest_records if r.contract_id == contract.id]
        all_rows.extend(verify_contract(contract, recs, cah_df, deals_df, today))

    summary = {}
    for r in all_rows:
        key = (r.contract_num, r.client_name)
        s = summary.setdefault(key, {"total": 0, "nom_mismatch": 0, "eff_mismatch": 0, "missing": 0})
        s["total"] += 1
        if not r.has_stored_record:
            s["missing"] += 1
        elif not r.is_match:
            if r.interest_type == "Nominal":
                s["nom_mismatch"] += 1
            else:
                s["eff_mismatch"] += 1

    summary_rows = []
    for (cnum, cname), s in sorted(summary.items()):
        mismatches = s["nom_mismatch"] + s["eff_mismatch"]
        status = "OK" if mismatches == 0 and s["missing"] == 0 else (f"{mismatches} MISMATCH" if mismatches else f"{s['missing']} MISSING")
        summary_rows.append({
            "contract_num": cnum, "client_name": cname, "days": s["total"] // 2,
            "nom_mismatch": s["nom_mismatch"], "eff_mismatch": s["eff_mismatch"],
            "missing": s["missing"], "mismatches": mismatches, "status": status,
        })

    detail_rows = [r for r in all_rows if not r.has_stored_record or not r.is_match]
    detail_rows.sort(key=lambda x: (x.contract_id, x.record_date, x.interest_type))

    return render_template(
        "interest.html",
        error=None,
        contract_id=contract_id,
        summary=summary_rows,
        details=detail_rows,
        total_rows=len(all_rows),
        shown_rows=len(detail_rows),
    )


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5001, debug=True)
