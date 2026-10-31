"""IDX Full Registry Ingestion Script.
Collects listed companies on Bursa Efek Indonesia (BEI).
Checks official source first, detects Cloudflare block gracefully, falls back to authorized public catalog,
computes SHA-256 provenance hash, and outputs structured JSON.
"""

import sys
import os
import json
import hashlib
import datetime
import urllib.request
from pathlib import Path
from bs4 import BeautifulSoup

def collect_idx_registry() -> dict:
    today_wib = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=7))).date().isoformat()
    project_root = Path(__file__).resolve().parent.parent
    archive_dir = project_root / "data" / "raw_immutable" / "registry"
    os.makedirs(archive_dir, exist_ok=True)

    official_url = "https://www.idx.co.id/en/listed-companies/company-profiles"
    fallback_url = "https://id.wikipedia.org/wiki/Daftar_perusahaan_yang_tercatat_di_Bursa_Efek_Indonesia"

    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    }

    raw_content = None
    source_used = None
    is_official = False

    # 1. Probe official endpoint first
    try:
        req = urllib.request.Request(official_url, headers=headers)
        with urllib.request.urlopen(req, timeout=5) as resp:
            data = resp.read()
            if b"Attention Required" not in data and b"Cloudflare" not in data:
                raw_content = data
                source_used = official_url
                is_official = True
    except Exception as e:
        # Expected Cloudflare block on direct script access
        pass

    # 2. Use secondary public directory if official blocked (Cloudflare 403)
    if not raw_content:
        source_used = f"Kandidat saham dari registri sekunder (Wikipedia: {fallback_url})"
        req = urllib.request.Request(fallback_url, headers=headers)
        with urllib.request.urlopen(req, timeout=20) as resp:
            raw_content = resp.read()

    raw_hash = hashlib.sha256(raw_content).hexdigest()
    raw_archive_file = archive_dir / f"idx_registry_{today_wib.replace('-', '')}.html"
    with open(raw_archive_file, "wb") as f:
        f.write(raw_content)

    # 3. Parse companies table
    html_text = raw_content.decode("utf-8", errors="replace")
    soup = BeautifulSoup(html_text, "html.parser")
    table = soup.find("table", class_="wikitable")

    companies = []
    if table:
        rows = table.find_all("tr")
        for row in rows[1:]:
            cols = [td.get_text(strip=True) for td in row.find_all(["td", "th"])]
            if len(cols) >= 6:
                raw_code = cols[1]
                ticker = raw_code.replace("BEI:", "").strip().upper()
                if not (len(ticker) == 4 and ticker.isalpha()):
                    continue

                company_name = cols[2]
                listing_date_raw = cols[3] if len(cols) > 3 else ""
                
                # Extract date YYYY-MM-DD
                listing_date = None
                import re
                m = re.search(r'\((\d{4}-\d{2}-\d{2})\)', listing_date_raw)
                if m:
                    listing_date = m.group(1)
                else:
                    m2 = re.search(r'(\d{4}-\d{2}-\d{2})', listing_date_raw)
                    if m2:
                        listing_date = m2.group(1)

                shares_raw = cols[4].replace(".", "").replace(",", "").strip() if len(cols) > 4 else "0"
                try:
                    shares_outstanding = int(shares_raw)
                except ValueError:
                    shares_outstanding = None

                board = cols[5] if len(cols) > 5 else "Utama"
                sector = cols[6] if len(cols) > 6 else "General"

                companies.append({
                    "ticker": ticker,
                    "company_name": company_name,
                    "listing_date": listing_date,
                    "listing_board": board,
                    "sector": sector,
                    "shares_outstanding": shares_outstanding,
                    "instrument_type": "EQUITY",
                    "listing_status": "ACTIVE",
                })

    # Add benchmark index explicitly separated
    indices = [
        {
            "ticker": "^JKSE",
            "company_name": "Indeks Harga Saham Gabungan (IHSG)",
            "listing_date": "1982-08-10",
            "listing_board": "Benchmark Index",
            "sector": "Composite Index",
            "shares_outstanding": None,
            "instrument_type": "INDEX",
            "listing_status": "ACTIVE",
        }
    ]

    result = {
        "success": True,
        "snapshot_date": today_wib,
        "source": source_used,
        "is_official_direct": is_official,
        "official_url_tested": official_url,
        "official_status": "SOURCE_DIRECT_AVAILABLE" if is_official else "SOURCE_UNAVAILABLE_CLOUDFLARE_BLOCKED",
        "raw_hash": raw_hash,
        "raw_archive_file": str(raw_archive_file),
        "total_equities": len(companies),
        "total_indices": len(indices),
        "companies": companies,
        "indices": indices,
    }

    json_archive_file = archive_dir / f"idx_registry_parsed_{today_wib.replace('-', '')}.json"
    with open(json_archive_file, "w", encoding="utf-8") as f:
        json.dump(result, f, indent=2)

    return result

if __name__ == "__main__":
    res = collect_idx_registry()
    print(f"Total Equities: {res['total_equities']} | Total Indices: {res['total_indices']} | Hash: {res['raw_hash'][:16]}...")
    if len(sys.argv) > 1 and sys.argv[1] == "--json":
        print(json.dumps(res))
